<?php

namespace Tests\Feature;

use App\Models\FootballMatch;
use App\Models\League;
use App\Models\Season;
use App\Models\Team;
use App\Models\TeamMatchStat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExportXgDatasetCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_export_writes_chronological_matches_with_measured_xg(): void
    {
        $league = League::create(['code' => 'PL', 'name' => 'Premier League', 'country' => 'England', 'tier' => '1', 'is_active' => true]);
        $season = Season::create(['league_id' => $league->id, 'name' => '2025-2026', 'year_start' => 2025, 'year_end' => 2026, 'is_current' => true]);
        $home = Team::create(['name' => 'Arsenal FC', 'short_name' => 'ARS', 'country' => 'England']);
        $away = Team::create(['name' => 'Chelsea FC', 'short_name' => 'CHE', 'country' => 'England']);

        $withXg = FootballMatch::create([
            'external_id' => 'statsbomb:1',
            'data_source' => 'statsbomb',
            'league_id' => $league->id,
            'season_id' => $season->id,
            'home_team_id' => $home->id,
            'away_team_id' => $away->id,
            'match_date_utc' => '2026-01-10 15:00:00',
            'status' => 'FINISHED',
            'home_score' => 2,
            'away_score' => 1,
        ]);

        $withoutXg = FootballMatch::create([
            'external_id' => 'openfootball:2',
            'data_source' => 'openfootball',
            'league_id' => $league->id,
            'season_id' => $season->id,
            'home_team_id' => $away->id,
            'away_team_id' => $home->id,
            'match_date_utc' => '2026-01-17 15:00:00',
            'status' => 'FINISHED',
            'home_score' => 0,
            'away_score' => 0,
        ]);

        TeamMatchStat::create(['match_id' => $withXg->id, 'team_id' => $home->id, 'is_home' => true, 'shots' => 14, 'shots_on_target' => 6, 'xg' => 1.87]);
        TeamMatchStat::create(['match_id' => $withXg->id, 'team_id' => $away->id, 'is_home' => false, 'shots' => 9, 'shots_on_target' => 3, 'xg' => 1.12]);
        TeamMatchStat::create(['match_id' => $withoutXg->id, 'team_id' => $away->id, 'is_home' => true, 'shots' => 7, 'shots_on_target' => 2, 'xg' => null]);

        $path = storage_path('app/testing/xg-dataset.json');

        $this->artisan('oracle:export-xg-dataset', ['--output' => $path, '--league' => 'PL'])
            ->assertExitCode(0);

        $this->assertFileExists($path);
        $payload = json_decode((string) file_get_contents($path), true);

        $this->assertIsArray($payload['matches']);
        $this->assertCount(2, $payload['matches']);

        // Chronological order: the backtest depends on it.
        $this->assertSame('2026-01-10', $payload['matches'][0]['date']);
        $this->assertSame('2026-01-17', $payload['matches'][1]['date']);

        $this->assertSame('Arsenal FC', $payload['matches'][0]['home_team']);
        $this->assertEqualsWithDelta(1.87, $payload['matches'][0]['home_xg'], 0.001);
        $this->assertEqualsWithDelta(1.12, $payload['matches'][0]['away_xg'], 0.001);
        $this->assertSame(14, $payload['matches'][0]['home_shots']);
        $this->assertSame('PL', $payload['matches'][0]['competition']);

        // A match without measured xG must stay null, never zero.
        $this->assertNull($payload['matches'][1]['home_xg']);
        $this->assertNull($payload['matches'][1]['away_xg']);

        @unlink($path);
    }

    public function test_export_rejects_a_league_without_finished_matches(): void
    {
        $path = storage_path('app/testing/empty-dataset.json');

        $this->artisan('oracle:export-xg-dataset', ['--output' => $path, '--league' => 'UNKNOWN'])
            ->assertExitCode(1);

        $this->assertFileDoesNotExist($path);
    }
}
