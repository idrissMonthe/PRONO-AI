<?php

namespace Tests\Feature;

use App\Models\CombinationSnapshot;
use App\Models\FootballMatch;
use App\Models\League;
use App\Models\ModelVersion;
use App\Models\Parlay;
use App\Models\Prediction;
use App\Models\PredictionSnapshot;
use App\Models\PredictionSettlement;
use App\Models\Season;
use App\Models\Team;
use App\Services\Prediction\PredictionHistoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Vérifie l'historisation immuable : une nouvelle génération ne remplace
 * jamais la probabilité publiée, et rien n'est réglé sans résultat réel.
 */
class PredictionHistoryTest extends TestCase
{
    use RefreshDatabase;

    private function match(array $attributes = []): FootballMatch
    {
        $league = League::firstOrCreate(['code' => 'PL'], ['name' => 'Premier League', 'country' => 'England']);
        $season = Season::firstOrCreate(
            ['league_id' => $league->id, 'name' => '2026-2027'],
            ['year_start' => 2026, 'year_end' => 2027, 'is_current' => true]
        );
        $home = Team::firstOrCreate(['name' => 'Arsenal'], ['short_name' => 'ARS']);
        $away = Team::firstOrCreate(['name' => 'Chelsea'], ['short_name' => 'CHE']);

        return FootballMatch::create(array_merge([
            'league_id' => $league->id,
            'season_id' => $season->id,
            'home_team_id' => $home->id,
            'away_team_id' => $away->id,
            'match_date_utc' => now()->addDay(),
            'status' => 'SCHEDULED',
            'data_source' => 'football-data-uk',
        ], $attributes));
    }

    private function prediction(FootballMatch $match, string $selection, float $probability, array $attributes = []): Prediction
    {
        $model = ModelVersion::firstOrCreate(
            ['version_name' => 'test-dc'],
            ['family' => 'pure', 'algorithm' => 'Dixon-Coles', 'status' => 'candidate']
        );

        return Prediction::create(array_merge([
            'match_id' => $match->id,
            'model_version_id' => $model->id,
            'content_hash' => hash('sha256', uniqid('', true)),
            'home_lambda' => 1.5,
            'away_lambda' => 1.1,
            'prob_home' => 0.5,
            'prob_draw' => 0.3,
            'prob_away' => 0.2,
            'is_official' => true,
            'published_market' => '1N2',
            'published_selection' => $selection,
            'published_probability' => $probability,
            'confidence_tier' => 'medium',
            'quality_tier' => 'usable',
            'source_model' => 'dixon_coles',
        ], $attributes));
    }

    public function test_a_new_generation_keeps_the_previous_published_probability(): void
    {
        $match = $this->match();
        $history = app(PredictionHistoryService::class);

        $history->recordPrediction($match, $this->prediction($match, 'home', 0.55));
        $history->recordPrediction($match, $this->prediction($match, 'home', 0.61));

        $snapshots = PredictionSnapshot::where('match_id', $match->id)->orderBy('id')->get();

        $this->assertCount(2, $snapshots, 'Deux probabilités différentes doivent produire deux snapshots.');
        $this->assertEqualsWithDelta(0.55, (float) $snapshots[0]->probability, 1e-6);
        $this->assertEqualsWithDelta(0.61, (float) $snapshots[1]->probability, 1e-6);
    }

    public function test_identical_publication_is_idempotent(): void
    {
        $match = $this->match();
        $history = app(PredictionHistoryService::class);

        $history->recordPrediction($match, $this->prediction($match, 'home', 0.55));
        $history->recordPrediction($match, $this->prediction($match, 'home', 0.55));

        $this->assertSame(1, PredictionSnapshot::where('match_id', $match->id)->count());
    }

    public function test_settles_a_won_prediction_from_the_real_result(): void
    {
        $match = $this->match(['status' => 'FINISHED', 'home_score' => 2, 'away_score' => 1]);
        $history = app(PredictionHistoryService::class);
        $history->recordPrediction($match, $this->prediction($match, 'home', 0.55));

        $history->settlePending();

        $settlement = PredictionSettlement::firstOrFail();
        $this->assertSame('WON', $settlement->outcome);
        $this->assertNotNull($settlement->settled_at_utc);
        $this->assertSame(2, $settlement->home_score);
    }

    public function test_a_lost_prediction_is_settled_lost(): void
    {
        $match = $this->match(['status' => 'FINISHED', 'home_score' => 0, 'away_score' => 2]);
        $history = app(PredictionHistoryService::class);
        $history->recordPrediction($match, $this->prediction($match, 'home', 0.55));

        $history->settlePending();

        $this->assertSame('LOST', PredictionSettlement::firstOrFail()->outcome);
    }

    public function test_a_draw_no_bet_is_void_on_a_draw(): void
    {
        $match = $this->match(['status' => 'FINISHED', 'home_score' => 1, 'away_score' => 1]);
        $history = app(PredictionHistoryService::class);
        $history->recordPrediction($match, $this->prediction($match, 'home', 0.5, [
            'published_market' => 'dnb',
        ]));

        $history->settlePending();

        $this->assertSame('VOID', PredictionSettlement::firstOrFail()->outcome);
    }

    public function test_missing_result_keeps_the_prediction_pending(): void
    {
        $match = $this->match(); // SCHEDULED, aucun score
        $history = app(PredictionHistoryService::class);
        $history->recordPrediction($match, $this->prediction($match, 'home', 0.55));

        $history->settlePending();

        $settlement = PredictionSettlement::firstOrFail();
        $this->assertSame('PENDING', $settlement->outcome);
        $this->assertNull($settlement->settled_at_utc);
    }

    public function test_combination_is_won_only_when_every_leg_wins(): void
    {
        $winner = $this->match(['status' => 'FINISHED', 'home_score' => 3, 'away_score' => 0]);
        $loser = $this->match(['status' => 'FINISHED', 'home_score' => 0, 'away_score' => 1]);

        $history = app(PredictionHistoryService::class);
        $history->recordPrediction($winner, $this->prediction($winner, 'home', 0.6));
        $history->recordPrediction($loser, $this->prediction($loser, 'home', 0.6));

        $parlay = Parlay::create([
            'title' => 'Combiné test',
            'date_for' => now()->toDateString(),
            'risk_level' => 'MODERE',
            'risk_score' => 5.0,
            'combined_odds' => 3.5,
            'combined_prob' => 0.36,
            'selections_json' => [
                ['match_id' => $winner->id, 'market' => '1N2', 'selection' => 'home', 'probability' => 0.6],
                ['match_id' => $loser->id, 'market' => '1N2', 'selection' => 'home', 'probability' => 0.6],
            ],
            'model_version' => 'test-dc',
        ]);

        $history->recordCombination($parlay);
        $history->settlePending();

        $combination = CombinationSnapshot::firstOrFail();
        $this->assertSame('LOST', $combination->status, 'Une seule sélection perdue suffit à perdre le combiné.');
    }

    public function test_combination_stays_pending_while_a_leg_is_unsettled(): void
    {
        $finished = $this->match(['status' => 'FINISHED', 'home_score' => 3, 'away_score' => 0]);
        $upcoming = $this->match();

        $history = app(PredictionHistoryService::class);
        $history->recordPrediction($finished, $this->prediction($finished, 'home', 0.6));
        $history->recordPrediction($upcoming, $this->prediction($upcoming, 'home', 0.6));

        $parlay = Parlay::create([
            'title' => 'Combiné en attente',
            'date_for' => now()->toDateString(),
            'risk_level' => 'MODERE',
            'risk_score' => 5.0,
            'combined_odds' => 3.5,
            'combined_prob' => 0.36,
            'selections_json' => [
                ['match_id' => $finished->id, 'market' => '1N2', 'selection' => 'home', 'probability' => 0.6],
                ['match_id' => $upcoming->id, 'market' => '1N2', 'selection' => 'home', 'probability' => 0.6],
            ],
            'model_version' => 'test-dc',
        ]);

        $history->recordCombination($parlay);
        $history->settlePending();

        $this->assertSame('PENDING', CombinationSnapshot::firstOrFail()->status);
    }

    public function test_performance_report_only_counts_settled_snapshots(): void
    {
        $won = $this->match(['status' => 'FINISHED', 'home_score' => 2, 'away_score' => 0]);
        $pending = $this->match();

        $history = app(PredictionHistoryService::class);
        $history->recordPrediction($won, $this->prediction($won, 'home', 0.8));
        $history->recordPrediction($pending, $this->prediction($pending, 'home', 0.8));
        $history->settlePending();

        $report = $history->performance();

        $this->assertSame(2, $report['snapshots']);
        $this->assertSame(1, $report['overall']['n']);
        $this->assertSame(1, $report['overall']['pending']);
        $this->assertEqualsWithDelta(0.04, $report['overall']['brier'], 1e-6);
    }

    /**
     * La ventilation par catégorie doit toujours se réconcilier avec le total
     * des snapshots : le pronostic publié est une catégorie à part entière,
     * pas une ligne non classée.
     */
    public function test_category_breakdown_reconciles_with_the_snapshot_total(): void
    {
        $match = $this->match();
        $prediction = $this->prediction($match, 'home', 0.55, [
            'derived_markets_json' => [
                'market_categories' => [
                    'prudent' => [['market_id' => 'double_chance', 'selection' => '1X', 'probability' => 0.78, 'coverage' => 'covered']],
                    'equilibre' => [['market_id' => 'btts', 'selection' => 'yes', 'probability' => 0.58, 'coverage' => 'covered']],
                    'audacieux' => [['market_id' => 'totals', 'selection' => 'over_2_5', 'probability' => 0.41, 'coverage' => 'covered']],
                ],
            ],
        ]);

        app(PredictionHistoryService::class)->recordPrediction($match, $prediction);

        $total = PredictionSnapshot::where('match_id', $match->id)->count();
        $report = app(PredictionHistoryService::class)->performance();
        $counts = $report['category_counts'];

        $this->assertSame(4, $total, 'Un snapshot publié et trois catégories de risque.');
        $this->assertSame(1, $counts[PredictionHistoryService::CATEGORY_PUBLISHED] ?? null);
        $this->assertSame(1, $counts['safe'] ?? null);
        $this->assertSame(1, $counts['balanced'] ?? null);
        $this->assertSame(1, $counts['bold'] ?? null);
        $this->assertSame($report['snapshots'], array_sum($counts));
    }

    /**
     * Les lignes historiques sans catégorie sont relues comme des pronostics
     * publiés : aucune donnée n'est réécrite pour faire coïncider les chiffres.
     */
    public function test_legacy_null_category_is_reported_as_published(): void
    {
        $match = $this->match();
        app(PredictionHistoryService::class)->recordPrediction($match, $this->prediction($match, 'home', 0.55));

        PredictionSnapshot::where('match_id', $match->id)->update(['category' => null]);

        $report = app(PredictionHistoryService::class)->performance();

        $this->assertSame(1, $report['category_counts'][PredictionHistoryService::CATEGORY_PUBLISHED] ?? null);
        $this->assertArrayNotHasKey('unspecified', $report['category_counts']);
    }
}
