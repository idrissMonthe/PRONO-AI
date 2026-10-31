<?php

namespace Tests\Feature;

use App\Models\FootballMatch;
use App\Models\ModelVersion;
use App\Models\Prediction;
use App\Services\Integrity\HashChainService;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\SampleMatchesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->seed(SampleMatchesSeeder::class);
    }

    public function test_dashboard_returns_successful_response(): void
    {
        $response = $this->get('/');
        $response->assertStatus(200);
        $response->assertSee('Tableau de Bord Analytique', false);
    }

    public function test_dashboard_includes_timed_matches_as_upcoming(): void
    {
        FootballMatch::where('status', 'SCHEDULED')->update(['status' => 'TIMED']);

        $this->get('/')
            ->assertOk()
            ->assertSee('Liverpool FC');
    }

    public function test_matches_exploration_page_loads(): void
    {
        $response = $this->get('/matches');
        $response->assertStatus(200);
        $response->assertSee('Calendrier', false);
    }

    public function test_match_page_displays_derived_market_values_from_the_prediction(): void
    {
        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [['content' => ['parts' => [['text' => 'Analyse synthétique disponible.']]]]],
            ]),
            '*' => Http::response([], 503),
        ]);

        $match = FootballMatch::with('officialPrediction')->firstOrFail();
        $match->officialPrediction->update([
            'derived_markets_json' => [
                'totals' => ['over_2_5' => 0.321, 'under_2_5' => 0.679, 'over_1_5' => 0.456],
                'btts' => ['yes' => 0.123, 'no' => 0.877],
                'clean_sheet' => ['home' => 0.654],
            ],
        ]);

        $response = $this->get('/matches/'.$match->id);

        $response->assertOk()
            ->assertSee('32.1%', false)
            ->assertSee('67.9%', false)
            ->assertSee('45.6%', false)
            ->assertSee('12.3%', false)
            ->assertSee('87.7%', false)
            ->assertSee('65.4%', false);
    }

    public function test_upcoming_filter_includes_timed_matches(): void
    {
        FootballMatch::where('status', 'SCHEDULED')->update(['status' => 'TIMED']);

        $this->get('/matches?status=SCHEDULED')
            ->assertOk()
            ->assertSee('Liverpool FC');
    }

    public function test_comparator_page_loads(): void
    {
        $response = $this->get('/comparator');
        $response->assertStatus(200);
        $response->assertSee('Comparateur de Performances', false);
    }

    public function test_odds_and_markets_page_loads(): void
    {
        $response = $this->get('/odds');
        $response->assertStatus(200);
        $response->assertSee('Paper Trading', false);
    }

    public function test_laboratory_page_loads(): void
    {
        $response = $this->get('/laboratory');
        $response->assertStatus(200);
        $response->assertSee('Banc d\'essai', false);
    }

    public function test_backtest_does_not_persist_fabricated_metrics(): void
    {
        $this->withoutMiddleware()
            ->post('/laboratory/run-backtest')
            ->assertRedirect('/laboratory')
            ->assertSessionHas('error');

        $this->assertDatabaseCount('backtest_runs', 0);
    }

    public function test_model_cannot_be_promoted_without_measured_criteria(): void
    {
        $champion = ModelVersion::where('status', 'champion')->firstOrFail();
        $candidate = ModelVersion::where('status', 'candidate')->firstOrFail();

        $this->withoutMiddleware()
            ->post('/laboratory/promote', ['target_version_id' => $candidate->id])
            ->assertRedirect('/laboratory')
            ->assertSessionHas('error');

        $this->assertSame('champion', $champion->fresh()->status);
        $this->assertSame('candidate', $candidate->fresh()->status);
        $this->assertDatabaseCount('model_promotions', 0);
    }

    public function test_data_management_page_loads(): void
    {
        $response = $this->get('/data');
        $response->assertStatus(200);
        $response->assertSee('team_aliases', false);
    }

    public function test_settings_and_diagnostic_page_loads(): void
    {
        $response = $this->get('/settings');
        $response->assertStatus(200);
        $response->assertSee('Centre de Diagnostic', false);
    }

    public function test_hash_chain_verification_route(): void
    {
        // withoutMiddleware disables CSRF for test environment
        $response = $this->withoutMiddleware()->post('/settings/verify-ledger');
        $response->assertRedirect('/settings');
        $response->assertSessionHas('success');
    }

    public function test_hash_chain_detects_a_tampered_prediction_mirror_hash(): void
    {
        $prediction = Prediction::firstOrFail();
        $prediction->update(['content_hash' => str_repeat('f', 64)]);

        $result = app(HashChainService::class)->verifyLedgerIntegrity();

        $this->assertSame('compromised', $result['status']);
        $this->assertNotNull($result['broken_at_id']);
    }
}
