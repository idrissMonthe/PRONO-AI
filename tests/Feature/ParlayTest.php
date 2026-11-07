<?php

namespace Tests\Feature;

use App\Models\Parlay;
use App\Models\Bookmaker;
use App\Models\FootballMatch;
use App\Models\Market;
use App\Models\OddsSnapshot;
use App\Models\Prediction;
use App\Models\PredictionEvaluation;
use App\Services\Prediction\PredictionSettlementService;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\SampleMatchesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ParlayTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [['content' => ['parts' => [['text' => 'Analyse du risque du combiné probabiliste.']]]]],
            ]),
        ]);
        $this->seed(DatabaseSeeder::class);
        $this->seed(SampleMatchesSeeder::class);
    }

    public function test_parlays_page_loads_and_generates_combinations(): void
    {
        $response = $this->get('/parlays');
        $response->assertStatus(200);
        $response->assertSee('Combinés Quotidiens');
        $response->assertSee('PROBABILISTE');

        // Doit avoir généré au moins un combiné en base
        $this->assertGreaterThan(0, Parlay::count());
    }

    public function test_daily_parlays_include_model_markets_without_inventing_bookmaker_odds(): void
    {
        $baseMatch = FootballMatch::with('officialPrediction')
            ->whereIn('status', ['SCHEDULED', 'TIMED'])
            ->whereHas('officialPrediction')
            ->firstOrFail();
        $targetDate = now()->timezone('Africa/Douala')->addDay()->startOfDay();
        $markets = [
            'totals' => [
                'over_1_5' => 0.80, 'under_1_5' => 0.20,
                'over_2_5' => 0.54, 'under_2_5' => 0.46,
                'over_3_5' => 0.35, 'under_3_5' => 0.65,
                'over_4_5' => 0.15, 'under_4_5' => 0.85,
            ],
            'btts' => ['yes' => 0.60, 'no' => 0.40],
            'team_totals' => [
                'home' => ['over_0_5' => 0.75, 'under_0_5' => 0.25, 'over_1_5' => 0.52, 'under_1_5' => 0.48, 'over_2_5' => 0.25, 'under_2_5' => 0.75],
                'away' => ['over_0_5' => 0.68, 'under_0_5' => 0.32, 'over_1_5' => 0.45, 'under_1_5' => 0.55, 'over_2_5' => 0.18, 'under_2_5' => 0.82],
            ],
            'clean_sheet' => ['home' => 0.30, 'away' => 0.28],
            'goal_margin' => [
                'home_by_2_plus' => 0.22, 'home_by_3_plus' => 0.10,
                'away_by_2_plus' => 0.17, 'away_by_3_plus' => 0.06,
            ],
            'combos' => [
                'home_win_over_2_5' => 0.20, 'away_win_over_2_5' => 0.10,
                'home_win_btts_yes' => 0.18, 'away_win_btts_yes' => 0.08,
            ],
        ];

        $invalidMatrixMatchId = null;
        for ($index = 0; $index < 4; $index++) {
            $match = FootballMatch::create([
                'external_id' => 'model-market-parlay-'.$index,
                'data_source' => 'test',
                'league_id' => $baseMatch->league_id,
                'season_id' => $baseMatch->season_id,
                'home_team_id' => $baseMatch->home_team_id,
                'away_team_id' => $baseMatch->away_team_id,
                'match_date_utc' => $targetDate->copy()->addHours(12 + $index)->utc(),
                'status' => 'SCHEDULED',
                'quality_score' => 95,
                'quality_tier' => 'usable',
            ]);

            $prediction = $baseMatch->officialPrediction->replicate();
            $prediction->match_id = $match->id;
            $prediction->is_abstained = false;
            $prediction->confidence_tier = 'high';
            $prediction->quality_score = 95;
            $prediction->home_lambda = 1.6;
            $prediction->away_lambda = 1.2;
            $prediction->truncated_residual_mass = $index === 3 ? 0.5 : 0.000001;
            $prediction->warnings_json = match ($index) {
                0 => ['low_sample_team:Test FC:2', 'market_backed_prediction_with_limited_team_history'],
                3 => ['unknown_or_promoted_team:New FC'],
                default => [],
            };
            $prediction->derived_markets_json = $markets;
            $prediction->save();
            if ($index === 3) {
                $invalidMatrixMatchId = $match->id;
            }
        }

        Parlay::create([
            'title' => 'Ancien combiné 1N2',
            'date_for' => $targetDate->toDateString(),
            'risk_level' => 'MODERE',
            'risk_score' => 5,
            'combined_odds' => 2.0,
            'combined_prob' => 0.5,
            'expected_value' => 0.0,
            'selections_json' => [[
                'match_id' => $baseMatch->id,
                'match_label' => $baseMatch->homeTeam->name.' vs '.$baseMatch->awayTeam->name,
                'league' => $baseMatch->league->name,
                'match_date_utc' => $baseMatch->match_date_utc->toIso8601String(),
                'selection_label' => 'Victoire domicile',
                'model_prob_pct' => 50,
                'odds' => 2.0,
                'price_basis' => 'bookmaker',
            ]],
            'model_version' => 'M3_DIXON_COLES',
            'status' => 'PENDING',
        ]);

        $parlays = app(\App\Services\Parlay\ParlayService::class)
            ->getOrGenerateDailyParlays($targetDate);
        $modelParlays = $parlays->filter(
            fn (Parlay $parlay): bool => str_starts_with($parlay->title, 'Scénario modèle')
        );

        $this->assertCount(3, $modelParlays);
        foreach ($modelParlays as $parlay) {
            $this->assertNull($parlay->expected_value);
            $this->assertNotEmpty($parlay->selections_json);
            foreach ($parlay->selections_json as $selection) {
                $this->assertSame('model_fair', $selection['price_basis']);
                $this->assertSame('Cote juste modèle — indicative', $selection['odds_source']);
                $this->assertNull($selection['ev_pct']);
                $this->assertEqualsWithDelta(
                    1 / $selection['model_prob'],
                    $selection['fair_odds'],
                    0.01
                );
            }
        }
        $this->assertFalse(
            $modelParlays->flatMap(fn (Parlay $parlay) => $parlay->selections_json)
                ->contains(fn (array $selection): bool => $selection['match_id'] === $invalidMatrixMatchId)
        );

        $this->assertContains(
            'BTTS',
            collect($modelParlays->firstWhere('risk_level', 'EQUILIBRE')->selections_json)
                ->pluck('market_group')
                ->all()
        );
        $limitedHistoryPick = collect($modelParlays->firstWhere('risk_level', 'EQUILIBRE')->selections_json)
            ->firstWhere('limited_team_history', true);
        $this->assertNotNull($limitedHistoryPick);
        $this->assertGreaterThanOrEqual(0.60, $limitedHistoryPick['model_prob']);
        $this->assertLessThan(0.78, $limitedHistoryPick['model_prob']);

        $this->get(route('parlays.index', ['date' => $targetDate->toDateString()]))
            ->assertOk()
            ->assertSee('Cote juste indicative')
            ->assertSee('cette cote n’est pas une cote proposée par un bookmaker')
            ->assertSee('EV : non calculée');

        $explanation = app(\App\Services\AIExplanation\GeminiExplanationService::class)
            ->generateDeterministicParlayExplanation([
                'pricing_basis' => 'cotes justes indicatives du modèle',
                'combined_odds' => 3.0,
                'combined_prob' => 0.3,
                'risk_score' => 7.0,
                'selections' => [],
            ]);
        $this->assertStringContainsString('pas une cote bookmaker', $explanation);
        $this->assertStringContainsString('aucune EV n’est calculée', $explanation);
    }

    public function test_parlay_regenerate_endpoint(): void
    {
        $response = $this->withoutMiddleware()->post('/parlays/generate');
        $response->assertRedirect('/parlays?date=' . now()->toDateString());
        $response->assertSessionHas('success');

        $parlay = Parlay::first();
        $this->assertNotNull($parlay);
        $this->assertNotNull($parlay->combined_odds);
        $this->assertNotNull($parlay->combined_prob);
        $this->assertNotNull($parlay->risk_score);
        $this->assertIsArray($parlay->selections_json);
    }

    public function test_dashboard_shows_at_least_ten_increasing_reliability_picks_across_leagues(): void
    {
        $upcoming = FootballMatch::with('officialPrediction')
            ->whereIn('status', ['SCHEDULED', 'TIMED'])
            ->get();
        $this->assertNotEmpty($upcoming);

        for ($index = 0; $index < 6; $index++) {
            $baseMatch = $upcoming[$index % $upcoming->count()];
            $match = FootballMatch::create([
                'external_id' => 'big-parlay-test-'.$index,
                'data_source' => 'test',
                'league_id' => $baseMatch->league_id,
                'season_id' => $baseMatch->season_id,
                'home_team_id' => $baseMatch->home_team_id,
                'away_team_id' => $baseMatch->away_team_id,
                'match_date_utc' => now()->utc()->addDays(10 + $index),
                'status' => 'SCHEDULED',
                'quality_score' => 92.5,
                'quality_tier' => 'usable',
            ]);

            $prediction = $baseMatch->officialPrediction->replicate();
            $prediction->match_id = $match->id;
            $prediction->save();
        }

        $response = $this->get('/');

        $response->assertOk()
            ->assertSee('Gros combinés')
            ->assertSee('Version prudente')
            ->assertSee('Version équilibrée')
            ->assertSee('Version audacieuse')
            ->assertSee('Fiabilité croissante');

        $response->assertViewHas('bigParlayPicks', function (array $tiers): bool {
            foreach (['prudent', 'equilibre', 'audacieux'] as $risk) {
                $this->assertGreaterThanOrEqual(10, count($tiers[$risk]));
                $this->assertGreaterThanOrEqual(2, collect($tiers[$risk])->pluck('league_key')->unique()->count());

                $probabilities = array_column($tiers[$risk], 'prob');
                $sortedProbabilities = $probabilities;
                sort($sortedProbabilities);
                $this->assertSame($sortedProbabilities, $probabilities);
            }

            return true;
        });
    }

    public function test_dashboard_uses_model_probabilities_when_prediction_abstains(): void
    {
        FootballMatch::whereIn('status', ['SCHEDULED', 'TIMED'])
            ->update(['match_date_utc' => now()->utc()->addDays(30)]);

        $baseMatch = FootballMatch::with('officialPrediction')
            ->whereIn('status', ['SCHEDULED', 'TIMED'])
            ->firstOrFail();
        $match = FootballMatch::create([
            'external_id' => 'market-fallback-test',
            'data_source' => 'test',
            'league_id' => $baseMatch->league_id,
            'season_id' => $baseMatch->season_id,
            'home_team_id' => $baseMatch->home_team_id,
            'away_team_id' => $baseMatch->away_team_id,
            'match_date_utc' => now()->utc()->addMinutes(10),
            'status' => 'SCHEDULED',
            'quality_score' => 20,
            'quality_tier' => 'low',
        ]);
        $prediction = $baseMatch->officialPrediction->replicate();
        $prediction->match_id = $match->id;
        $prediction->is_abstained = true;
        $prediction->prob_home = 0.24;
        $prediction->prob_draw = 0.36;
        $prediction->prob_away = 0.40;
        $prediction->derived_markets_json = [
            '1N2' => ['home' => 0.24, 'draw' => 0.36, 'away' => 0.40],
            'double_chance' => ['1X' => 0.60, 'X2' => 0.76],
        ];
        $prediction->save();

        $marketId = Market::where('code', '1N2')->value('id');
        $bookmaker = Bookmaker::firstOrFail();
        $snapshotTime = now()->utc();
        foreach (['HOME' => 2.0, 'DRAW' => 3.0, 'AWAY' => 4.0] as $selection => $price) {
            OddsSnapshot::create([
                'match_id' => $match->id,
                'bookmaker_id' => $bookmaker->id,
                'market_id' => $marketId,
                'selection' => $selection,
                'decimal_odds' => $price,
                'snapshot_time_utc' => $snapshotTime,
                'source' => 'test',
            ]);
        }

        $this->get('/')
            ->assertOk()
            ->assertViewHas('dailyPicks', function (array $groups) use ($match): bool {
                $pick = collect($groups['prudent'])->first(
                    fn (array $candidate): bool => $candidate['match']->id === $match->id
                );

                $this->assertNotNull($pick);
                $this->assertSame('Modèle · fiabilité limitée', $pick['source']);
                $this->assertSame('X2', $pick['key']);
                $this->assertSame(0.76, $pick['prob']);
                $this->assertFalse(collect($groups)->flatten(1)->contains(
                    fn (array $candidate): bool => $candidate['match']->id === $match->id
                        && $candidate['key'] === 'exact_score'
                ));

                return true;
            })
            ->assertViewHas('bigParlayPicks', function (array $tiers) use ($match): bool {
                $pick = collect($tiers['prudent'])->first(
                    fn (array $candidate): bool => $candidate['match']->id === $match->id
                );

                $this->assertNotNull($pick);
                $this->assertSame('Modèle · fiabilité limitée', $pick['source']);
                $this->assertSame($match->awayTeam->short_name.' ou nul', $pick['label']);
                $this->assertSame(0.76, $pick['prob']);

                $balancedPick = collect($tiers['equilibre'])->first(
                    fn (array $candidate): bool => $candidate['match']->id === $match->id
                );
                $audaciousPick = collect($tiers['audacieux'])->first(
                    fn (array $candidate): bool => $candidate['match']->id === $match->id
                );
                $this->assertSame('Modèle · fiabilité limitée', $balancedPick['source']);
                $this->assertContains($balancedPick['prob'], [0.24, 0.36, 0.40]);
                $this->assertSame('Modèle · fiabilité limitée', $audaciousPick['source']);
                $this->assertContains($audaciousPick['prob'], [0.24, 0.36, 0.40]);

                return true;
            });
    }

    public function test_abstained_audacious_picks_use_model_probabilities_instead_of_odds(): void
    {
        FootballMatch::whereIn('status', ['SCHEDULED', 'TIMED'])
            ->update(['match_date_utc' => now()->utc()->addDays(30)]);

        $baseMatch = FootballMatch::with('officialPrediction')
            ->whereIn('status', ['SCHEDULED', 'TIMED'])
            ->firstOrFail();
        $marketId = Market::where('code', '1N2')->value('id');
        $bookmaker = Bookmaker::firstOrFail();
        $matches = collect();

        foreach ([
            ['HOME' => 3.2, 'DRAW' => 4.0, 'AWAY' => 2.3],
            ['HOME' => 2.3, 'DRAW' => 4.0, 'AWAY' => 3.2],
        ] as $index => $odds) {
            $match = FootballMatch::create([
                'external_id' => 'audacious-outcome-test-'.$index,
                'data_source' => 'test',
                'league_id' => $baseMatch->league_id,
                'season_id' => $baseMatch->season_id,
                'home_team_id' => $baseMatch->home_team_id,
                'away_team_id' => $baseMatch->away_team_id,
                'match_date_utc' => now()->utc()->addDays(2)->addHours($index),
                'status' => 'SCHEDULED',
                'quality_score' => 20,
                'quality_tier' => 'low',
            ]);
            $prediction = $baseMatch->officialPrediction->replicate();
            $prediction->match_id = $match->id;
            $prediction->is_abstained = true;
            $prediction->prob_home = 0.27;
            $prediction->prob_draw = 0.34;
            $prediction->prob_away = 0.39;
            $prediction->derived_markets_json = [
                '1N2' => ['home' => 0.27, 'draw' => 0.34, 'away' => 0.39],
                'double_chance' => ['1X' => 0.61, 'X2' => 0.73],
            ];
            $prediction->save();
            $matches->push($match);

            foreach ($odds as $selection => $price) {
                OddsSnapshot::create([
                    'match_id' => $match->id,
                    'bookmaker_id' => $bookmaker->id,
                    'market_id' => $marketId,
                    'selection' => $selection,
                    'decimal_odds' => $price,
                    'snapshot_time_utc' => now()->utc(),
                    'source' => 'test',
                ]);
            }
        }

        $this->get('/')
            ->assertOk()
            ->assertViewHas('bigParlayPicks', function (array $tiers) use ($matches): bool {
                $picks = collect($tiers['audacieux'])
                    ->filter(fn (array $pick): bool => $matches->contains('id', $pick['match']->id))
                    ->values();

                $this->assertCount(2, $picks);
                $this->assertTrue($picks->every(
                    fn (array $pick): bool => $pick['source'] === 'Modèle · fiabilité limitée'
                        && $pick['market'] === '1N2'
                        && in_array($pick['prob'], [0.27, 0.34, 0.39], true)
                ));

                return true;
            });
    }

    public function test_dashboard_rotates_audacious_model_picks_across_available_markets(): void
    {
        FootballMatch::whereIn('status', ['SCHEDULED', 'TIMED'])
            ->update(['match_date_utc' => now()->utc()->addDays(30)]);

        $baseMatch = FootballMatch::with('officialPrediction')
            ->whereIn('status', ['SCHEDULED', 'TIMED'])
            ->firstOrFail();
        $markets = [
            'totals' => ['over_2_5' => 0.35, 'under_2_5' => 0.45],
            'btts' => ['yes' => 0.35, 'no' => 0.45],
            'combos' => [
                'home_win_over_2_5' => 0.20,
                'away_win_over_2_5' => 0.18,
                'home_win_btts_yes' => 0.22,
                'away_win_btts_yes' => 0.16,
            ],
            'goal_margin' => [
                'home_by_2_plus' => 0.30,
                'away_by_2_plus' => 0.20,
                'home_by_3_plus' => 0.15,
                'away_by_3_plus' => 0.12,
            ],
            'team_totals' => [
                'home' => ['over_2_5' => 0.30],
                'away' => ['over_2_5' => 0.20],
            ],
        ];
        $createdMatches = collect();

        for ($index = 0; $index < 8; $index++) {
            $match = FootballMatch::create([
                'external_id' => 'audacious-model-market-test-'.$index,
                'data_source' => 'test',
                'league_id' => $baseMatch->league_id,
                'season_id' => $baseMatch->season_id,
                'home_team_id' => $baseMatch->home_team_id,
                'away_team_id' => $baseMatch->away_team_id,
                'match_date_utc' => now()->utc()->addDays(2)->addHours($index),
                'status' => 'SCHEDULED',
                'quality_score' => 95,
                'quality_tier' => 'usable',
            ]);
            $prediction = $baseMatch->officialPrediction->replicate();
            $prediction->match_id = $match->id;
            $prediction->is_official = true;
            $prediction->is_abstained = false;
            $prediction->confidence_tier = 'high';
            $prediction->quality_score = 95;
            $prediction->home_lambda = 1.8;
            $prediction->away_lambda = 1.2;
            $prediction->truncated_residual_mass = 0.000001;
            $prediction->warnings_json = [];
            $prediction->derived_markets_json = array_merge([
                '1N2' => ['home' => 0.42, 'draw' => 0.26, 'away' => 0.32],
            ], $markets);
            $prediction->save();
            $createdMatches->push($match);
        }

        $this->get('/')
            ->assertOk()
            ->assertViewHas('dailyPicks', function (array $groups) use ($createdMatches): bool {
                $picks = collect($groups['audacieux'])
                    ->filter(fn (array $pick): bool => $createdMatches->contains('id', $pick['match']->id));

                $this->assertGreaterThanOrEqual(2, $picks->pluck('market')->unique()->count());

                return true;
            })
            ->assertViewHas('bigParlayPicks', function (array $tiers) use ($createdMatches): bool {
                $picks = collect($tiers['audacieux'])
                    ->filter(fn (array $pick): bool => $createdMatches->contains('id', $pick['match']->id));
                $markets = $picks->pluck('market')->unique();

                $this->assertGreaterThanOrEqual(4, $markets->count());
                $this->assertTrue($markets->contains('BTTS'));
                $this->assertTrue($markets->contains('Total buts'));
                $this->assertTrue($markets->contains('Combiné'));
                $this->assertTrue($markets->contains('Marge de buts'));

                return true;
            });
    }

    public function test_dashboard_includes_model_based_two_goal_margin_picks(): void
    {
        FootballMatch::whereIn('status', ['SCHEDULED', 'TIMED'])
            ->update(['match_date_utc' => now()->utc()->addDays(30)]);
        Prediction::where('is_official', true)->update(['is_abstained' => true]);

        $baseMatch = FootballMatch::with('officialPrediction')
            ->whereIn('status', ['SCHEDULED', 'TIMED'])
            ->firstOrFail();
        $match = FootballMatch::create([
            'external_id' => 'goal-margin-test',
            'data_source' => 'test',
            'league_id' => $baseMatch->league_id,
            'season_id' => $baseMatch->season_id,
            'home_team_id' => $baseMatch->home_team_id,
            'away_team_id' => $baseMatch->away_team_id,
            'match_date_utc' => now()->utc()->addMinutes(10),
            'status' => 'SCHEDULED',
            'quality_score' => 95,
            'quality_tier' => 'usable',
        ]);
        $prediction = $baseMatch->officialPrediction->replicate();
        $prediction->match_id = $match->id;
        $prediction->is_abstained = false;
        $prediction->derived_markets_json = [
            '1N2' => ['home' => 0.90, 'draw' => 0.06, 'away' => 0.04],
            'double_chance' => ['1X' => 0.96, 'X2' => 0.10],
            'totals' => ['over_1_5' => 0.60, 'under_4_5' => 0.75],
            'btts' => ['yes' => 0.20, 'no' => 0.80],
            'combos' => [
                'home_win_over_2_5' => 0.12,
                'away_win_over_2_5' => 0.08,
                'home_win_btts_yes' => 0.10,
                'away_win_btts_yes' => 0.06,
            ],
            'goal_margin' => [
                'home_by_2_plus' => 0.35,
                'away_by_2_plus' => 0.02,
                'home_by_3_plus' => 0.25,
                'away_by_3_plus' => 0.01,
            ],
            'team_totals' => [
                'home' => ['over_0_5' => 0.85, 'over_1_5' => 0.60, 'over_2_5' => 0.25, 'under_0_5' => 0.15],
                'away' => ['over_0_5' => 0.75, 'over_1_5' => 0.45, 'over_2_5' => 0.15, 'under_0_5' => 0.25],
            ],
            'clean_sheet' => ['home' => 0.30, 'away' => 0.20],
        ];
        $prediction->top_scores_json = [];
        $prediction->save();

        $this->get('/')
            ->assertOk()
            ->assertViewHas('upcomingMatches', function ($matches) use ($match): bool {
                $dashboardMatch = $matches->firstWhere('id', $match->id);
                $this->assertNotNull($dashboardMatch);
                $this->assertFalse($dashboardMatch->officialPrediction->is_abstained);
                $this->assertEqualsWithDelta(
                    0.35,
                    $dashboardMatch->officialPrediction->derived_markets_json['goal_margin']['home_by_2_plus'],
                    0.0001
                );

                return true;
            })
            ->assertViewHas('dailyPicks', function (array $groups) use ($match): bool {
                $pick = collect($groups['audacieux'])->first(
                    fn (array $candidate): bool => $candidate['match']->id === $match->id
                );

                $this->assertNotNull($pick);
                $this->assertSame('home_by_2_plus', $pick['key']);
                $this->assertSame('Marge de buts', $pick['market']);
                $this->assertEqualsWithDelta(0.35, $pick['prob'], 0.0001);

                return true;
            })
            ->assertViewHas('bigParlayPicks', function (array $tiers) use ($match): bool {
                $pick = collect($tiers['audacieux'])->first(
                    fn (array $candidate): bool => $candidate['match']->id === $match->id
                );

                $this->assertNotNull($pick);
                $this->assertSame('Marge de buts', $pick['market']);
                $this->assertSame($match->homeTeam->short_name.' gagne par 2 buts ou plus', $pick['label']);
                $this->assertEqualsWithDelta(0.35, $pick['prob'], 0.0001);

                return true;
            });

        $this->get(route('matches.show', $match->id))
            ->assertOk()
            ->assertSee('Buts par équipe')
            ->assertSee('Marge de victoire');
    }

    public function test_dashboard_hides_score_markets_when_team_history_or_score_matrix_is_unreliable(): void
    {
        FootballMatch::whereIn('status', ['SCHEDULED', 'TIMED'])
            ->update(['match_date_utc' => now()->utc()->addDays(30)]);

        $baseMatch = FootballMatch::with('officialPrediction')
            ->whereIn('status', ['SCHEDULED', 'TIMED'])
            ->firstOrFail();
        $match = FootballMatch::create([
            'external_id' => 'unreliable-score-markets-test',
            'data_source' => 'test',
            'league_id' => $baseMatch->league_id,
            'season_id' => $baseMatch->season_id,
            'home_team_id' => $baseMatch->home_team_id,
            'away_team_id' => $baseMatch->away_team_id,
            'match_date_utc' => now()->utc()->addMinutes(15),
            'status' => 'SCHEDULED',
            'quality_score' => 95,
            'quality_tier' => 'usable',
        ]);
        $prediction = $baseMatch->officialPrediction->replicate();
        $prediction->match_id = $match->id;
        $prediction->is_abstained = false;
        $prediction->confidence_tier = 'high';
        $prediction->quality_score = 95;
        $prediction->home_lambda = 0.1;
        $prediction->away_lambda = 0.1;
        $prediction->truncated_residual_mass = 0.5;
        $prediction->warnings_json = ['unknown_or_promoted_team:New FC'];
        $prediction->top_scores_json = [['score' => '0-0', 'p' => 0.99]];
        $prediction->derived_markets_json = [
            '1N2' => ['home' => 0.70, 'draw' => 0.20, 'away' => 0.10],
            'double_chance' => ['1X' => 0.90, 'X2' => 0.30],
            'totals' => ['under_4_5' => 0.99],
            'btts' => ['no' => 0.99],
            'clean_sheet' => ['home' => 0.99, 'away' => 0.99],
            'team_totals' => ['home' => ['under_0_5' => 0.99], 'away' => ['under_0_5' => 0.99]],
            'goal_margin' => ['home_by_2_plus' => 0.95, 'away_by_2_plus' => 0.02],
            'combos' => ['home_win_over_2_5' => 0.80],
        ];
        $prediction->save();

        $this->get('/')
            ->assertOk()
            ->assertViewHas('dailyPicks', function (array $groups) use ($match): bool {
                $matchPicks = collect($groups)->flatten(1)
                    ->filter(fn (array $pick): bool => $pick['match']->id === $match->id);
                $this->assertNotEmpty($matchPicks);
                $this->assertFalse($matchPicks->contains(
                    fn (array $pick): bool => in_array($pick['market'], ['Total buts', 'BTTS', 'Clean sheet', 'Buts par équipe', 'Marge de buts', 'Combiné', 'Score exact'], true)
                ));

                return true;
            })
            ->assertViewHas('bigParlayPicks', function (array $tiers) use ($match): bool {
                $matchPicks = collect($tiers)->flatten(1)
                    ->filter(fn (array $pick): bool => $pick['match']->id === $match->id);
                $this->assertFalse($matchPicks->contains(
                    fn (array $pick): bool => in_array($pick['market'], ['Total buts', 'BTTS', 'Clean sheet', 'Buts par équipe', 'Marge de buts', 'Combiné', 'Score exact'], true)
                ));

                return true;
            });
    }

    public function test_new_score_markets_are_settled_against_the_final_score(): void
    {
        $match = FootballMatch::with('officialPrediction')
            ->where('status', 'FINISHED')
            ->firstOrFail();
        $prediction = $match->officialPrediction;
        $prediction->is_abstained = false;
        $prediction->derived_markets_json = [
            'team_totals' => [
                'home' => ['over_0_5' => 0.80, 'under_0_5' => 0.20],
                'away' => ['over_0_5' => 0.70, 'under_0_5' => 0.30],
            ],
            'clean_sheet' => ['home' => 0.25, 'away' => 0.20],
            'goal_margin' => [
                'home_by_1_plus' => 0.60,
                'away_by_1_plus' => 0.25,
            ],
        ];
        $prediction->save();

        app(PredictionSettlementService::class)->settleFinishedPredictions();

        $this->assertSame(
            'WON',
            PredictionEvaluation::where('prediction_id', $prediction->id)
                ->where('market_key', 'team_total_home_over_0_5')
                ->value('status')
        );
        $this->assertSame(
            'WON',
            PredictionEvaluation::where('prediction_id', $prediction->id)
                ->where('market_key', 'home_by_1_plus')
                ->value('status')
        );
        $this->assertNotNull(
            PredictionEvaluation::where('prediction_id', $prediction->id)
                ->where('market_key', 'clean_sheet_home')
                ->first()
        );
    }
}
