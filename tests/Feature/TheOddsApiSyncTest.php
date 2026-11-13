<?php

namespace Tests\Feature;

use App\Models\FootballMatch;
use App\Models\OddsSnapshot;
use App\Services\FootballData\FootballDataService;
use App\Services\Odds\TheOddsApiService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TheOddsApiSyncTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        config()->set('services.the_odds_api.api_key', 'test-odds-api-key');
        config()->set('services.the_odds_api.base_url', 'https://api.the-odds-api.com/v4');
        config()->set('services.the_odds_api.regions', 'eu');
        config()->set('services.the_odds_api.bookmakers', ['pinnacle']);
        config()->set('services.the_odds_api.markets', ['h2h', 'totals', 'spreads']);
    }

    public function test_automatic_odds_sync_includes_champions_league_events_and_records_real_odds(): void
    {
        $this->assertSame('soccer_uefa_champs_league', config('services.the_odds_api.sports.CL'));
        $kickoff = now('Africa/Douala')->addDay()->setTime(20, 0);
        $match = app(FootballDataService::class)->ingestMatchRecord([
            'external_id' => 'odds-api-cl-match',
            'home_team' => 'Paris Saint-Germain',
            'away_team' => 'FC Bayern München',
            'date' => $kickoff->copy()->utc()->toIso8601String(),
            'league_code' => 'CL',
            'league_name' => 'UEFA Champions League',
            'country' => 'Europe',
            'season' => '2026-2027',
            'status' => 'SCHEDULED',
        ], 'football-data.org');
        $this->assertInstanceOf(FootballMatch::class, $match);

        $event = [
                'id' => 'event-1',
                'commence_time' => $kickoff->copy()->utc()->toIso8601String(),
                'home_team' => 'Paris Saint-Germain',
                'away_team' => 'FC Bayern München',
                'bookmakers' => [[
                    'key' => 'pinnacle',
                    'title' => 'Pinnacle',
                    'markets' => [
                        [
                            'key' => 'h2h',
                            'last_update' => now()->utc()->toIso8601String(),
                            'outcomes' => [
                                ['name' => 'Paris Saint-Germain', 'price' => 2.1],
                                ['name' => 'FC Bayern München', 'price' => 3.2],
                                ['name' => 'Draw', 'price' => 3.4],
                            ],
                        ],
                        [
                            'key' => 'totals',
                            'last_update' => now()->utc()->toIso8601String(),
                            'outcomes' => [
                                ['name' => 'Over', 'point' => 2.5, 'price' => 1.95],
                                ['name' => 'Under', 'point' => 2.5, 'price' => 1.85],
                            ],
                        ],
                        [
                            'key' => 'spreads',
                            'last_update' => now()->utc()->toIso8601String(),
                            'outcomes' => [
                                ['name' => 'Paris Saint-Germain', 'point' => -0.5, 'price' => 2.05],
                                ['name' => 'FC Bayern München', 'point' => 0.5, 'price' => 1.80],
                            ],
                        ],
                    ],
                ]],
            ];
        Http::fake(fn ($request) => str_contains($request->url(), '/soccer_uefa_champs_league/odds')
            ? Http::response([$event])
            : Http::response([]));

        $result = app(TheOddsApiService::class)->sync(
            $kickoff->toDateString(),
            $kickoff->toDateString()
        );

        $this->assertSame(1, $result['matched']);
        $this->assertSame(7, OddsSnapshot::where('match_id', $match->id)->count());
        $this->assertDatabaseHas('odds_snapshots', [
            'match_id' => $match->id,
            'source' => 'the-odds-api',
            'selection' => 'HOME',
            'decimal_odds' => 2.1,
        ]);
        $this->assertDatabaseHas('odds_snapshots', [
            'match_id' => $match->id,
            'selection' => 'OVER',
            'line' => 2.5,
            'decimal_odds' => 1.95,
        ]);
        $this->assertDatabaseHas('odds_snapshots', [
            'match_id' => $match->id,
            'selection' => 'HOME',
            'line' => -0.5,
            'decimal_odds' => 2.05,
        ]);
        $clRequest = Http::recorded()->first(
            fn (array $recorded): bool => str_contains($recorded[0]->url(), '/soccer_uefa_champs_league/odds')
        );
        $this->assertNotNull($clRequest);
        $request = $clRequest[0];
        $this->assertStringContainsString('/soccer_uefa_champs_league/odds', $request->url());
        parse_str(parse_url($request->url(), PHP_URL_QUERY) ?? '', $query);
        $this->assertArrayHasKey('commenceTimeTo', $query);
        $this->assertSame('test-odds-api-key', $query['apiKey']);
    }
}
