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
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ParlayAjaxAndDatesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.gemini.api_key', 'test-key');
        config()->set('services.gemini.model', 'test-model');
    }

    public function test_parlay_generate_supports_ajax_and_returns_json(): void
    {
        $league = League::create(['code' => 'PL', 'name' => 'Premier League', 'country' => 'England', 'tier' => '1', 'is_active' => true]);
        $season = Season::create(['league_id' => $league->id, 'name' => '2025-2026', 'year_start' => 2025, 'year_end' => 2026, 'is_current' => true]);
        $m3 = ModelVersion::create([
            'version_name' => 'M3_DIXON_COLES',
            'algorithm' => 'dixon_coles',
            'is_active' => true,
        ]);
        $bookmaker = Bookmaker::create(['code' => 'pinnacle', 'name' => 'Pinnacle']);
        $market1N2 = Market::create(['code' => '1N2', 'name' => '1N2']);

        $teamA = Team::create(['name' => 'Arsenal FC', 'short_name' => 'ARS', 'country' => 'England']);
        $teamB = Team::create(['name' => 'Chelsea FC', 'short_name' => 'CHE', 'country' => 'England']);
        $teamC = Team::create(['name' => 'Liverpool FC', 'short_name' => 'LIV', 'country' => 'England']);
        $teamD = Team::create(['name' => 'Everton FC', 'short_name' => 'EVE', 'country' => 'England']);

        $m1 = FootballMatch::create([
            'external_id' => 'p_match_1',
            'data_source' => 'football-data.org',
            'league_id' => $league->id,
            'season_id' => $season->id,
            'home_team_id' => $teamA->id,
            'away_team_id' => $teamB->id,
            'match_date_utc' => now()->addHours(6),
            'status' => 'SCHEDULED',
            'quality_score' => 95.0,
            'quality_tier' => 'usable',
        ]);
        Prediction::create([
            'match_id' => $m1->id,
            'model_version_id' => $m3->id,
            'calibration_version' => 'calib_v2',
            'is_official' => true,
            'freeze_time_utc' => now(),
            'content_hash' => 'hash1',
            'home_lambda' => 1.8,
            'away_lambda' => 0.9,
            'prob_home' => 0.60,
            'prob_draw' => 0.22,
            'prob_away' => 0.18,
            'quality_score' => 95.0,
            'quality_tier' => 'usable',
            'confidence_tier' => 'high',
            'is_abstained' => false,
            'execution_time_ms' => 15,
        ]);
        OddsSnapshot::create([
            'match_id' => $m1->id,
            'bookmaker_id' => $bookmaker->id,
            'market_id' => $market1N2->id,
            'selection' => 'HOME',
            'decimal_odds' => 1.90,
            'snapshot_time_utc' => now(),
            'is_closing' => false,
            'source' => 'football-data.org',
        ]);

        $m2 = FootballMatch::create([
            'external_id' => 'p_match_2',
            'data_source' => 'football-data.org',
            'league_id' => $league->id,
            'season_id' => $season->id,
            'home_team_id' => $teamC->id,
            'away_team_id' => $teamD->id,
            'match_date_utc' => now()->addHours(8),
            'status' => 'SCHEDULED',
            'quality_score' => 95.0,
            'quality_tier' => 'usable',
        ]);
        Prediction::create([
            'match_id' => $m2->id,
            'model_version_id' => $m3->id,
            'calibration_version' => 'calib_v2',
            'is_official' => true,
            'freeze_time_utc' => now(),
            'content_hash' => 'hash2',
            'home_lambda' => 1.9,
            'away_lambda' => 0.8,
            'prob_home' => 0.62,
            'prob_draw' => 0.22,
            'prob_away' => 0.16,
            'quality_score' => 95.0,
            'quality_tier' => 'usable',
            'confidence_tier' => 'high',
            'is_abstained' => false,
            'execution_time_ms' => 14,
        ]);
        OddsSnapshot::create([
            'match_id' => $m2->id,
            'bookmaker_id' => $bookmaker->id,
            'market_id' => $market1N2->id,
            'selection' => 'HOME',
            'decimal_odds' => 1.85,
            'snapshot_time_utc' => now(),
            'is_closing' => false,
            'source' => 'football-data.org',
        ]);

        // Appel AJAX au endpoint /parlays/generate
        $response = $this->postJson(route('parlays.generate'), [
            'date' => Carbon::now('Africa/Douala')->toDateString(),
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'success',
            'count',
            'message',
            'generated_at',
            'parlays',
        ]);
        $this->assertTrue($response->json('success'));
        $this->assertGreaterThanOrEqual(1, $response->json('count'));
    }

    public function test_artisan_generate_parlays_command_runs_successfully(): void
    {
        $this->artisan('oracle:generate-parlays')
            ->assertExitCode(0);
    }
}
