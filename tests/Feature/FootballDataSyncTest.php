<?php

namespace Tests\Feature;

use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FootballDataSyncTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        config()->set('services.football_data.api_key', 'test-football-data-key');
        config()->set('services.football_data.base_url', 'https://api.football-data.org/v4');
        config()->set('services.football_data.competitions', ['PL']);
    }

    public function test_sync_imports_provider_matches_and_uses_the_auth_header(): void
    {
        Http::fake([
            'api.football-data.org/v4/matches*' => Http::response([
                'matches' => [[
                    'utcDate' => '2026-10-29T19:00:00Z',
                    'status' => 'SCHEDULED',
                    'matchday' => 10,
                    'homeTeam' => ['name' => 'Arsenal FC'],
                    'awayTeam' => ['name' => 'Chelsea FC'],
                    'score' => [
                        'fullTime' => ['home' => null, 'away' => null],
                        'halfTime' => ['home' => null, 'away' => null],
                    ],
                    'competition' => [
                        'code' => 'PL',
                        'name' => 'Premier League',
                        'area' => ['name' => 'England'],
                    ],
                    'season' => [
                        'startDate' => '2026-08-01',
                        'endDate' => '2027-05-31',
                    ],
                ]],
            ]),
        ]);

        $response = $this->withoutMiddleware()->post('/data/sync-api');

        $response->assertRedirect('/data')->assertSessionHas('success');
        $this->assertDatabaseHas('matches', ['status' => 'SCHEDULED']);
        $this->assertDatabaseHas('teams', ['name' => 'Arsenal FC']);

        Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://api.football-data.org/v4/matches?')
            && $request->hasHeader('X-Auth-Token', 'test-football-data-key')
            && $request['competitions'] === 'PL');
    }

    public function test_sync_reports_a_provider_error_without_exposing_credentials(): void
    {
        Http::fake([
            'api.football-data.org/v4/matches*' => Http::response(['message' => 'Unauthorized'], 401),
        ]);

        $response = $this->withoutMiddleware()->post('/data/sync-api');

        $response->assertRedirect('/data')
            ->assertSessionHas('error', 'football-data.org a refusé la synchronisation (HTTP 401).');
    }

    public function test_sync_imports_team_names_with_accented_utf8_characters(): void
    {
        Http::fake([
            'api.football-data.org/v4/matches*' => Http::response([
                'matches' => [[
                    'utcDate' => '2026-10-29T19:00:00Z',
                    'status' => 'TIMED',
                    'homeTeam' => ['name' => 'Deportivo Alavés'],
                    'awayTeam' => ['name' => 'Paris Saint-Germain'],
                    'score' => ['fullTime' => ['home' => null, 'away' => null]],
                    'competition' => ['code' => 'PD', 'name' => 'La Liga', 'area' => ['name' => 'Spain']],
                    'season' => ['startDate' => '2026-08-01', 'endDate' => '2027-05-31'],
                ]],
            ]),
        ]);

        $this->withoutMiddleware()->post('/data/sync-api')
            ->assertRedirect('/data')
            ->assertSessionHas('success');

        $this->assertDatabaseHas('teams', ['name' => 'Deportivo Alavés']);
    }
}
