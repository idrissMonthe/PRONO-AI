<?php

namespace Tests\Feature;

use App\Models\FootballMatch;
use App\Models\League;
use App\Models\Season;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SyncMatchesCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.football_data.api_key', 'test-fd-key');
        config()->set('services.football_data.competitions', ['PL']);
    }

    public function test_sync_matches_command_creates_and_updates_with_external_id(): void
    {
        $payload1 = [
            'matches' => [
                [
                    'id' => 999901,
                    'competition' => ['id' => 2021, 'code' => 'PL', 'name' => 'Premier League', 'area' => ['name' => 'England']],
                    'season' => ['startDate' => '2025-08-15', 'endDate' => '2026-05-25'],
                    'utcDate' => '2026-11-02T15:00:00Z',
                    'status' => 'SCHEDULED',
                    'matchday' => 10,
                    'homeTeam' => ['id' => 65, 'name' => 'Manchester City'],
                    'awayTeam' => ['id' => 66, 'name' => 'Liverpool FC'],
                    'score' => ['fullTime' => ['home' => null, 'away' => null], 'halfTime' => ['home' => null, 'away' => null]],
                ]
            ]
        ];

        $payload2 = [
            'matches' => [
                [
                    'id' => 999901,
                    'competition' => ['id' => 2021, 'code' => 'PL', 'name' => 'Premier League', 'area' => ['name' => 'England']],
                    'season' => ['startDate' => '2025-08-15', 'endDate' => '2026-05-25'],
                    'utcDate' => '2026-11-02T15:00:00Z',
                    'status' => 'FINISHED',
                    'matchday' => 10,
                    'homeTeam' => ['id' => 65, 'name' => 'Manchester City'],
                    'awayTeam' => ['id' => 66, 'name' => 'Liverpool FC'],
                    'score' => ['fullTime' => ['home' => 2, 'away' => 1], 'halfTime' => ['home' => 1, 'away' => 0]],
                ]
            ]
        ];

        Http::fake([
            '*/matches*' => Http::sequence()
                ->push($payload1, 200)
                ->push($payload2, 200),
        ]);

        $this->artisan('oracle:sync-matches --days-back=1 --days-ahead=2')
            ->assertExitCode(0);

        $this->assertDatabaseHas('matches', [
            'external_id' => '999901',
            'data_source' => 'football-data.org',
            'status' => 'SCHEDULED',
        ]);

        // Deuxième exécution : doit mettre à jour le match existant
        $this->artisan('oracle:sync-matches --days-back=1 --days-ahead=2')
            ->assertExitCode(0);

        // Toujours 1 seul enregistrement en base
        $this->assertSame(1, FootballMatch::where('external_id', '999901')->count());

        $this->assertDatabaseHas('matches', [
            'external_id' => '999901',
            'status' => 'FINISHED',
            'home_score' => 2,
            'away_score' => 1,
        ]);
    }
}
