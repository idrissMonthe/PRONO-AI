<?php

namespace Tests\Feature;

use App\Models\FootballMatch;
use App\Services\Prediction\LiveFlashPickService;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\SampleMatchesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LiveFlashPickTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->seed(SampleMatchesSeeder::class);
    }

    public function test_live_flash_uses_the_current_score_and_remaining_match_time(): void
    {
        $match = $this->makeLiveMatch(homeScore: 1, awayScore: 0);

        $pick = app(LiveFlashPickService::class)->build($match);

        $this->assertNotNull($pick);
        $this->assertSame('1 - 0', $pick['score']);
        $this->assertSame($match->homeTeam->short_name.' +1,5 buts', $pick['label']);
        $this->assertSame('Prono flash · projection du modèle', $pick['source']);
        $this->assertGreaterThanOrEqual(0.58, $pick['prob']);
    }

    public function test_live_flash_does_not_recommend_a_team_total_already_reached(): void
    {
        $match = $this->makeLiveMatch(homeScore: 2, awayScore: 0);

        $pick = app(LiveFlashPickService::class)->build($match);

        $this->assertNotNull($pick);
        $this->assertSame($match->homeTeam->short_name.' +2,5 buts', $pick['label']);
    }

    public function test_live_flash_requires_enough_time_remaining_for_a_supported_scenario(): void
    {
        $match = $this->makeLiveMatch(homeScore: 1, awayScore: 0);
        $now = now()->utc();
        $match->match_date_utc = $now->copy()->subMinutes(75);
        $match->last_synced_at = $now;
        $match->save();

        $this->assertNull(app(LiveFlashPickService::class)->build($match, $now));
    }

    public function test_live_flash_ignores_stale_scores_and_unreliable_predictions(): void
    {
        $match = $this->makeLiveMatch(homeScore: 1, awayScore: 0);
        $match->last_synced_at = now()->utc()->subMinutes(4);
        $match->save();
        $this->assertNull(app(LiveFlashPickService::class)->build($match));

        $match->last_synced_at = now()->utc();
        $match->officialPrediction->update([
            'warnings_json' => ['unknown_or_promoted_team:New FC'],
        ]);
        $this->assertNull(app(LiveFlashPickService::class)->build($match->fresh()));
    }

    public function test_dashboard_displays_live_flash_selections_separately(): void
    {
        $this->makeLiveMatch(homeScore: 1, awayScore: 0);

        $this->get('/')
            ->assertOk()
            ->assertSee('PRONO FLASH · EN DIRECT')
            ->assertSee('+1,5 buts')
            ->assertSee('projection du modèle')
            ->assertSee('Ce ne sont pas des cotes live de bookmaker')
            ->assertSee('window.location.reload()', false);
    }

    private function makeLiveMatch(int $homeScore, int $awayScore): FootballMatch
    {
        $baseMatch = FootballMatch::with('officialPrediction')
            ->whereIn('status', ['SCHEDULED', 'TIMED'])
            ->firstOrFail();
        $match = FootballMatch::create([
            'external_id' => 'live-flash-test-'.uniqid(),
            'data_source' => 'football-data.org',
            'league_id' => $baseMatch->league_id,
            'season_id' => $baseMatch->season_id,
            'home_team_id' => $baseMatch->home_team_id,
            'away_team_id' => $baseMatch->away_team_id,
            'match_date_utc' => now()->utc()->subMinutes(40),
            'last_synced_at' => now()->utc(),
            'status' => 'IN_PLAY',
            'home_score' => $homeScore,
            'away_score' => $awayScore,
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
        $prediction->save();

        return $match->load(['homeTeam', 'awayTeam', 'officialPrediction', 'league']);
    }
}
