<?php

namespace Tests\Feature;

use App\Models\FootballMatch;
use App\Models\Prediction;
use App\Services\FootballData\FootballDataService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MarketEngineIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function predictPayload(FootballMatch $match, array $marketCategories): array
    {
        return [
            'match_id' => (string) $match->id,
            'status' => 'ok',
            'availability_status' => 'PYTHON_READY',
            'data_quality' => 90.0,
            'available_markets' => ['1N2', 'totals', 'btts'],
            'missing_data' => [],
            'uncertainty' => 'low',
            'model' => [
                'version' => 'M3_MARKET_SPECIALISTS_v6',
                'family' => 'pure',
                'calibration_version' => 'calib_v8_shrunk_team_lambdas',
            ],
            'computed_at' => now()->utc()->toIso8601String(),
            'data_snapshot_hash' => str_repeat('b', 64),
            'expected_goals' => ['home' => 1.6, 'away' => 1.1, 'total' => 2.7],
            'probabilities' => [
                'home' => ['p' => 0.50, 'ci80' => [0.43, 0.57], 'ci95' => [0.39, 0.61]],
                'draw' => ['p' => 0.27, 'ci80' => [0.21, 0.33], 'ci95' => [0.19, 0.35]],
                'away' => ['p' => 0.23, 'ci80' => [0.17, 0.29], 'ci95' => [0.15, 0.31]],
            ],
            'markets' => [
                '1N2' => ['home' => 0.50, 'draw' => 0.27, 'away' => 0.23],
                'double_chance' => ['1X' => 0.77, 'X2' => 0.50, '12' => 0.73],
                'totals' => ['over_1_5' => 0.74, 'under_1_5' => 0.26, 'over_2_5' => 0.50, 'under_2_5' => 0.50],
                'btts' => ['yes' => 0.55, 'no' => 0.45],
                'team_totals' => [
                    'home' => ['over_0_5' => 0.78, 'under_0_5' => 0.22],
                    'away' => ['over_0_5' => 0.72, 'under_0_5' => 0.28],
                ],
                'clean_sheet' => ['home' => 0.32, 'away' => 0.28],
                'combos' => [],
            ],
            'top_scores' => [['score' => '1-0', 'p' => 0.12]],
            'matrix' => ['max_goals' => 10, 'truncated_mass' => 0.0000001],
            'quality' => ['score' => 90.0, 'tier' => 'usable', 'confidence_tier' => 'high'],
            'confidence_tier' => 'high',
            'abstain' => false,
            'limited_data_mode' => false,
            'abstain_reason' => null,
            'warnings' => [],
            'duration_ms' => 20,
            'uncertainty_method' => 'unavailable',
            'uncertainty_replicates' => 0,
            'market_predictions' => array_merge(...array_values($marketCategories ?: [[]])),
            'market_categories' => $marketCategories,
            'market_coverage' => ['families' => ['corners' => ['generated' => 2, 'coverage' => 'partial', 'market_status' => 'conditional']]],
            'combinations' => [],
            'market_registry' => ['market_count' => 70, 'live_enabled' => false],
            'period_profile' => ['coverage' => 'none', 'sample_size' => 0],
        ];
    }

    private function ingestMatch(): FootballMatch
    {
        $match = app(FootballDataService::class)->ingestMatchRecord([
            'external_id' => 'api-football:fixture:4242',
            'home_team' => 'Cameroun',
            'away_team' => 'Égypte',
            'date' => now()->addDays(1)->utc()->toIso8601String(),
            'league_code' => 'AF6',
            'league_name' => 'Africa Cup of Nations',
            'country' => 'World',
            'season' => '2026-2027',
            'status' => 'SCHEDULED',
        ], 'api-football');

        $this->assertNotNull($match);

        return $match;
    }

    public function test_extended_markets_are_persisted_and_classified(): void
    {
        $match = $this->ingestMatch();
        $corners = [
            'match_id' => (string) $match->id,
            'market_id' => 'total_corners',
            'market_name' => 'Total de corners Over/Under',
            'family' => 'corners',
            'period' => 'full',
            'selection' => 'over:8.5',
            'selection_label' => 'Plus de 8,5 corners',
            'line' => 8.5,
            'probability' => 0.68,
            'coverage' => 'partial',
            'reliability' => 0.71,
            'uncertainty' => 0.4,
            'model' => 'negative_binomial',
            'market_status' => 'conditional',
        ];

        Http::fake([
            '*127.0.0.1:8001/v1/predict*' => Http::response($this->predictPayload($match, [
                'prudent' => [$corners],
                'equilibre' => [],
                'audacieux' => [],
            ])),
        ]);

        $this->artisan('oracle:generate-predictions')->assertExitCode(0);

        $prediction = Prediction::where('match_id', $match->id)->where('is_official', true)->first();
        $this->assertNotNull($prediction);
        $this->assertSame('total_corners', $prediction->derived_markets_json['market_categories']['prudent'][0]['market_id']);
        $this->assertSame(0.68, (float) $prediction->derived_markets_json['market_predictions'][0]['probability']);
        $this->assertArrayHasKey('market_coverage', $prediction->derived_markets_json);

        // Le libellé remonte dans la catégorie correspondante du dashboard
        // lorsque la revue n'invalide pas le pronostic.
        $prediction->forceFill([
            'review_decision' => \App\Services\Prediction\PredictionRevisionService::STATUS_VALIDATED,
        ])->save();
        $response = $this->get('/')->assertOk();
        $picks = $response->viewData('dailyPicks');
        $labels = collect($picks['prudent'] ?? [])->pluck('label')->all();
        $this->assertContains('Plus de 8,5 corners', $labels, 'Picks : '.json_encode($picks).' | abstained='.var_export($prediction->is_abstained, true).' published='.var_export($prediction->published_market, true));
        $response->assertSee('Plus de 8,5 corners', false);
    }

    public function test_absent_extended_data_publishes_no_invented_markets(): void
    {
        $match = $this->ingestMatch();

        Http::fake([
            '*127.0.0.1:8001/v1/predict*' => Http::response($this->predictPayload($match, [])),
        ]);

        $this->artisan('oracle:generate-predictions')->assertExitCode(0);

        $prediction = Prediction::where('match_id', $match->id)->where('is_official', true)->first();
        $this->assertNotNull($prediction);
        $this->assertSame([], $prediction->derived_markets_json['market_categories']);
        $this->assertSame([], $prediction->derived_markets_json['market_predictions']);
    }
}
