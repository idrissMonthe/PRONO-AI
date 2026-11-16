<?php

namespace Tests\Feature;

use App\Models\Bookmaker;
use App\Models\FootballMatch;
use App\Models\League;
use App\Models\Market;
use App\Models\ModelVersion;
use App\Models\OddsSnapshot;
use App\Models\Parlay;
use App\Models\Prediction;
use App\Models\Season;
use App\Models\Team;
use App\Services\Parlay\ParlayService;
use App\Services\Prediction\PredictionRevisionService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

class ParlayGeminiEligibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_bookmaker_parlays_ignore_predictions_gemini_did_not_validate(): void
    {
        config()->set('services.gemini.api_key', null);
        config()->set('services.hybrid_prediction.allow_gemini_alternative_in_parlays', false);

        $target = Carbon::parse('2026-11-20 12:00:00', 'Africa/Douala');
        $validated = $this->matchWithPick($target, 'VALIDATED A', PredictionRevisionService::STATUS_VALIDATED);
        $legacy = $this->matchWithPick($target, 'LEGACY B', null);
        $blocked = [
            $this->matchWithPick($target, 'ALT C', PredictionRevisionService::STATUS_WITH_ALTERNATIVE),
            $this->matchWithPick($target, 'NO ALT D', PredictionRevisionService::STATUS_WITHOUT_ALTERNATIVE),
            $this->matchWithPick($target, 'INFO E', PredictionRevisionService::STATUS_INSUFFICIENT),
            $this->matchWithPick($target, 'FAIL F', PredictionRevisionService::STATUS_FAILED),
        ];

        $parlays = app(ParlayService::class)->generateDailyParlays($target);
        $matchIds = $parlays->flatMap(fn (Parlay $parlay) => collect($parlay->selections_json)->pluck('match_id'))->all();

        $this->assertNotEmpty($matchIds);
        $this->assertContains($validated->id, $matchIds);
        $this->assertContains($legacy->id, $matchIds);
        foreach ($blocked as $match) {
            $this->assertNotContains($match->id, $matchIds);
        }
    }

    public function test_enabled_alternative_is_the_only_candidate_for_a_refused_prediction(): void
    {
        $match = $this->matchWithPick(
            Carbon::parse('2026-11-21 12:00:00', 'Africa/Douala'),
            'REFUSED',
            PredictionRevisionService::STATUS_WITH_ALTERNATIVE
        );
        $prediction = $match->officialPrediction;
        $prediction->forceFill([
            'market_metrics_json' => [
                'gemini_review' => [
                    'alternative' => [
                        'market' => 'btts',
                        'selection' => 'yes',
                        'label' => 'Les deux équipes marquent',
                        'origin' => 'PYTHON',
                        'probability' => 0.62,
                        'technically_valid' => true,
                        'market_recognized' => true,
                    ],
                ],
            ],
        ])->save();

        $service = app(ParlayService::class);
        config()->set('services.hybrid_prediction.allow_gemini_alternative_in_parlays', false);
        $this->assertSame([], $this->modelCandidates($service, $match->fresh('officialPrediction')));

        config()->set('services.hybrid_prediction.allow_gemini_alternative_in_parlays', true);
        $candidates = $this->modelCandidates($service, $match->fresh('officialPrediction'));

        $this->assertCount(1, $candidates);
        $this->assertSame('btts', $candidates[0]['market_group']);
        $this->assertSame(0.62, $candidates[0]['model_prob']);
        $this->assertFalse($candidates[0]['is_ai_estimate']);
        $this->assertStringNotContainsString('HOME', $candidates[0]['selection']);
    }

    private function modelCandidates(ParlayService $service, FootballMatch $match): array
    {
        $method = new ReflectionMethod(ParlayService::class, 'buildModelMarketCandidates');
        $method->setAccessible(true);

        return $method->invoke($service, $match, $match->officialPrediction);
    }

    private function matchWithPick(Carbon $day, string $name, ?string $review): FootballMatch
    {
        $league = League::firstOrCreate(
            ['code' => 'PARLAY'],
            ['name' => 'Ligue parlay', 'country' => 'France', 'tier' => '1', 'is_active' => true]
        );
        $season = Season::firstOrCreate(
            ['league_id' => $league->id, 'name' => '2025-2026'],
            ['year_start' => 2025, 'year_end' => 2026, 'is_current' => true]
        );
        $home = Team::create(['name' => $name.' Home', 'short_name' => 'H'.substr(md5($name), 0, 3), 'country' => 'France']);
        $away = Team::create(['name' => $name.' Away', 'short_name' => 'A'.substr(md5($name), 0, 3), 'country' => 'France']);
        $model = ModelVersion::firstOrCreate(
            ['version_name' => 'parlay-eligibility'],
            ['algorithm' => 'Dixon-Coles', 'status' => 'champion']
        );
        $match = FootballMatch::create([
            'external_id' => 'parlay-'.md5($name),
            'data_source' => 'football-data.org',
            'league_id' => $league->id,
            'season_id' => $season->id,
            'home_team_id' => $home->id,
            'away_team_id' => $away->id,
            'match_date_utc' => $day->copy()->setTime(18, 0)->utc(),
            'status' => 'SCHEDULED',
            'quality_score' => 90,
            'quality_tier' => 'usable',
        ]);
        Prediction::create([
            'match_id' => $match->id,
            'model_version_id' => $model->id,
            'is_official' => true,
            'review_decision' => $review,
            'content_hash' => str_repeat('c', 64),
            'home_lambda' => 1.6,
            'away_lambda' => 1.1,
            'prob_home' => 0.64,
            'prob_draw' => 0.22,
            'prob_away' => 0.14,
            'derived_markets_json' => [
                '1N2' => ['home' => 0.64, 'draw' => 0.22, 'away' => 0.14],
                'totals' => ['over_2_5' => 0.70, 'under_2_5' => 0.30],
                'btts' => ['yes' => 0.62, 'no' => 0.38],
                'double_chance' => ['1X' => 0.86, 'X2' => 0.36],
            ],
            'quality_score' => 90,
            'quality_tier' => 'usable',
            'confidence_tier' => 'high',
            'is_abstained' => false,
            'published_market' => '1N2',
            'published_selection' => 'home',
            'published_probability' => 0.64,
        ]);

        $bookmaker = Bookmaker::firstOrCreate(['code' => 'pinnacle'], ['name' => 'Pinnacle']);
        $markets = [
            '1N2' => Market::firstOrCreate(['code' => '1N2'], ['name' => '1N2']),
            'totals' => Market::firstOrCreate(['code' => 'totals'], ['name' => 'Totaux']),
            'btts' => Market::firstOrCreate(['code' => 'btts'], ['name' => 'BTTS']),
        ];
        $rows = [
            ['1N2', 'HOME', 1.60, null],
            ['1N2', 'DRAW', 3.80, null],
            ['1N2', 'AWAY', 6.00, null],
            ['totals', 'OVER', 1.50, 2.5],
            ['btts', 'YES', 1.70, null],
        ];
        foreach ($rows as [$code, $selection, $odds, $line]) {
            OddsSnapshot::create([
                'match_id' => $match->id,
                'bookmaker_id' => $bookmaker->id,
                'market_id' => $markets[$code]->id,
                'selection' => $selection,
                'line' => $line,
                'decimal_odds' => $odds,
                'snapshot_time_utc' => now(),
                'is_closing' => false,
                'source' => 'the-odds-api',
            ]);
        }

        return $match->fresh(['officialPrediction']);
    }
}
