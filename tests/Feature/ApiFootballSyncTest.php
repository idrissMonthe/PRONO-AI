<?php

namespace Tests\Feature;

use App\Services\FootballData\ApiFootballService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ApiFootballSyncTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        config()->set('services.api_football.api_key', 'test-api-football-key');
        config()->set('services.api_football.base_url', 'https://v3.football.api-sports.io');
        config()->set('services.api_football.competitions', [6]);
        config()->set('services.api_football.seasons', [2025]);
        // Ce test couvre les fixtures configurées ; la découverte du catalogue
        // est couverte séparément avec sa propre réponse simulée.
        config()->set('services.api_football.discover', false);
    }

    public function test_can_fixture_is_imported_with_a_provider_scoped_external_id(): void
    {
        Http::fake([
            'v3.football.api-sports.io/fixtures*' => Http::response([
                'get' => 'fixtures',
                'errors' => [],
                'results' => 1,
                'response' => [[
                    'fixture' => [
                        'id' => 123456,
                        'date' => '2026-01-10T18:00:00+00:00',
                        'status' => ['short' => 'NS'],
                    ],
                    'league' => [
                        'id' => 6,
                        'name' => 'Africa Cup of Nations',
                        'country' => 'World',
                        'season' => 2025,
                        'round' => 'Group Stage - 1',
                    ],
                    'teams' => [
                        'home' => ['name' => 'Cameroon'],
                        'away' => ['name' => 'Egypt'],
                    ],
                    'goals' => ['home' => null, 'away' => null],
                    'score' => ['halftime' => ['home' => null, 'away' => null]],
                ]],
            ]),
        ]);

        $result = app(ApiFootballService::class)->syncFixtures('2026-01-10', '2026-01-10');

        $this->assertSame(1, $result['imported']);
        $this->assertDatabaseHas('matches', [
            'external_id' => 'api-football:fixture:123456',
            'data_source' => 'api-football',
            'status' => 'SCHEDULED',
        ]);
        $this->assertDatabaseHas('leagues', ['code' => 'AF6']);

        Http::assertSent(fn ($request) => $request->hasHeader('x-apisports-key', 'test-api-football-key')
            && (int) ($request['league'] ?? 0) === 6
            && (int) ($request['season'] ?? 0) === 2025
            && $request['from'] === '2026-01-10'
            && $request['to'] === '2026-01-10');
    }
}
