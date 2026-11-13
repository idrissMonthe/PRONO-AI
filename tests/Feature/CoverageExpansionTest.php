<?php

namespace Tests\Feature;

use App\Models\FootballMatch;
use App\Models\Parlay;
use App\Models\Prediction;
use App\Models\ProviderEntityMap;
use App\Services\FootballData\ApiFootballService;
use App\Services\FootballData\FootballDataService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CoverageExpansionTest extends TestCase
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
        config()->set('services.api_football.discover', false);
        config()->set('services.api_football.max_competitions_per_sync', 12);
        config()->set('services.api_football.daily_call_limit', 100);
        config()->set('services.prediction.allow_limited_data', true);
        config()->set('services.hybrid_prediction.mode', 'ASSISTED');
        config()->set('services.hybrid_prediction.allow_ai_in_parlays', true);
        config()->set('services.gemini.api_key', null);
        config()->set('services.ai_service.secret', 'test-ai-secret');
        config()->set('services.ai_service.url', 'http://127.0.0.1:8001');
    }

    public function test_catalogue_discovery_adds_complementary_competitions(): void
    {
        config()->set('services.api_football.discover', true);

        Http::fake([
            'v3.football.api-sports.io/leagues*' => Http::response([
                'errors' => [],
                'paging' => ['current' => 1, 'total' => 1],
                'response' => [
                    [
                        'league' => ['id' => 6, 'name' => 'Africa Cup of Nations', 'type' => 'Cup'],
                        'country' => ['name' => 'World'],
                        'seasons' => [['year' => 2025, 'current' => true]],
                    ],
                    [
                        'league' => ['id' => 39, 'name' => 'Premier League', 'type' => 'League'],
                        'country' => ['name' => 'England'],
                        'seasons' => [['year' => 2025, 'current' => true]],
                    ],
                    [
                        'league' => ['id' => 71, 'name' => 'Serie A', 'type' => 'League'],
                        'country' => ['name' => 'Brazil'],
                        'seasons' => [['year' => 2025, 'current' => true]],
                    ],
                ],
            ]),
            'v3.football.api-sports.io/fixtures*' => Http::response([
                'errors' => [],
                'paging' => ['current' => 1, 'total' => 1],
                'response' => [],
            ]),
        ]);

        $plan = app(ApiFootballService::class)->competitionPlan();

        $this->assertSame([6, 39, 71], array_column($plan, 'id'));
        $this->assertSame('PL', config('services.api_football.league_map')[39]);
        $this->assertSame('AF6', (string) (config('services.api_football.league_map')[6] ?? 'AF6'));
    }

    public function test_fixture_from_the_second_provider_does_not_duplicate_an_existing_match(): void
    {
        $primary = app(FootballDataService::class);
        $existing = $primary->ingestMatchRecord([
            'external_id' => '12345',
            'home_team' => 'Arsenal FC',
            'away_team' => 'Chelsea FC',
            'date' => '2026-10-29T19:00:00Z',
            'league_code' => 'PL',
            'league_name' => 'Premier League',
            'country' => 'England',
            'season' => '2026-2027',
            'status' => 'SCHEDULED',
        ], 'football-data.org');

        $this->assertNotNull($existing);

        Http::fake([
            'v3.football.api-sports.io/fixtures*' => Http::response([
                'errors' => [],
                'paging' => ['current' => 1, 'total' => 1],
                'response' => [[
                    'fixture' => [
                        'id' => 900001,
                        'date' => '2026-10-29T19:00:00+00:00',
                        'status' => ['short' => 'NS'],
                    ],
                    'league' => [
                        'id' => 39,
                        'name' => 'Premier League',
                        'country' => 'England',
                        'season' => 2026,
                        'round' => 'Regular Season - 10',
                    ],
                    'teams' => [
                        'home' => ['name' => 'Arsenal'],
                        'away' => ['name' => 'Chelsea'],
                    ],
                    'goals' => ['home' => null, 'away' => null],
                    'score' => ['halftime' => ['home' => null, 'away' => null]],
                ]],
            ]),
        ]);

        $result = app(ApiFootballService::class)->syncFixtures('2026-10-29', '2026-10-29');

        $this->assertSame(1, $result['imported']);
        $this->assertSame(1, FootballMatch::count(), 'Le second fournisseur ne doit pas créer de doublon.');
        $this->assertSame('football-data.org', FootballMatch::first()->data_source);
        $this->assertSame(2, ProviderEntityMap::where('entity_type', 'match')->count());
    }

    public function test_current_season_is_queried_without_page_parameter_when_no_season_is_pinned(): void
    {
        config()->set('services.api_football.seasons', []);

        Http::fake([
            'v3.football.api-sports.io/fixtures*' => Http::response([
                'errors' => [],
                'paging' => ['current' => 1, 'total' => 1],
                'response' => [],
            ]),
        ]);

        app(ApiFootballService::class)->syncFixtures('2026-11-13', '2026-11-13');

        $urls = collect(Http::recorded())->map(fn (array $record): string => $record[0]->url());
        $current = (int) now('Africa/Douala')->year;

        $this->assertTrue($urls->contains(fn (string $url): bool => str_contains($url, 'season='.$current)),
            'La saison courante doit être interrogée.');
        $this->assertTrue($urls->contains(fn (string $url): bool => str_contains($url, 'season='.($current - 1))),
            'La saison précédente doit aussi être interrogée.');
        $this->assertTrue($urls->every(fn (string $url): bool => ! str_contains($url, 'page=')),
            'Le paramètre page ne doit pas être envoyé sur la première requête.');
    }

    public function test_sync_command_keeps_importing_when_one_competition_fails(): void
    {
        config()->set('services.api_football.competitions', [6, 71]);

        Http::fake(function ($request) {
            parse_str(parse_url($request->url(), PHP_URL_QUERY) ?? '', $query);

            if ((string) ($query['league'] ?? '') === '6') {
                return Http::response(['errors' => ['token' => 'invalid'], 'response' => []], 500);
            }

            return Http::response([
                'errors' => [],
                'paging' => ['current' => 1, 'total' => 1],
                'response' => [[
                    'fixture' => [
                        'id' => 900002,
                        'date' => now()->addDay()->utc()->toIso8601String(),
                        'status' => ['short' => 'NS'],
                    ],
                    'league' => [
                        'id' => 71,
                        'name' => 'Serie A',
                        'country' => 'Brazil',
                        'season' => 2026,
                        'round' => 'Regular Season - 1',
                    ],
                    'teams' => [
                        'home' => ['name' => 'Flamengo'],
                        'away' => ['name' => 'Palmeiras'],
                    ],
                    'goals' => ['home' => null, 'away' => null],
                    'score' => ['halftime' => ['home' => null, 'away' => null]],
                ]],
            ]);
        });

        $this->artisan('oracle:sync-api-football', [
            '--date' => now('Africa/Douala')->addDay()->toDateString(),
        ])
            ->expectsOutputToContain('2 compétition(s)')
            ->expectsOutputToContain('1 compétition(s) en erreur')
            ->assertExitCode(0);

        $this->assertSame(1, FootballMatch::where('data_source', 'api-football')->count(),
            'La compétition en erreur ne doit pas annuler les rencontres déjà récupérées.');
    }

    public function test_sync_command_stops_cleanly_when_the_daily_quota_is_reached(): void
    {
        config()->set('services.api_football.competitions', [6, 71]);
        config()->set('services.api_football.daily_call_limit', 1);

        Http::fake([
            'v3.football.api-sports.io/fixtures*' => Http::response([
                'errors' => [],
                'paging' => ['current' => 1, 'total' => 1],
                'response' => [],
            ]),
        ]);

        $this->artisan('oracle:sync-api-football', [
            '--date' => now('Africa/Douala')->addDay()->toDateString(),
        ])
            ->expectsOutputToContain('Quota quotidien API-Football atteint')
            ->assertExitCode(0);

        $this->assertSame(1, Http::recorded()->count(),
            'Le quota doit interrompre la synchronisation sans requête supplémentaire.');
    }

    public function test_demo_purge_keeps_real_api_football_matches(): void
    {
        $real = app(FootballDataService::class)->ingestMatchRecord([
            'external_id' => 'api-football:fixture:1001',
            'home_team' => 'Senegal',
            'away_team' => 'Morocco',
            'date' => now()->addDays(2)->utc()->toIso8601String(),
            'league_code' => 'AF6',
            'league_name' => 'Africa Cup of Nations',
            'country' => 'World',
            'season' => '2026-2027',
            'status' => 'SCHEDULED',
        ], 'api-football');
        $this->assertNotNull($real);

        $demo = FootballMatch::create([
            'external_id' => null,
            'data_source' => 'demo',
            'league_id' => $real->league_id,
            'season_id' => $real->season_id,
            'home_team_id' => $real->home_team_id,
            'away_team_id' => $real->away_team_id,
            'match_date_utc' => now()->addDays(3)->utc(),
            'status' => 'SCHEDULED',
            'quality_score' => 10,
            'quality_tier' => 'low',
        ]);

        $this->artisan('oracle:purge-demo-data')->assertExitCode(0);

        $this->assertDatabaseHas('matches', ['id' => $real->id]);
        $this->assertDatabaseMissing('matches', ['id' => $demo->id]);
    }

    public function test_only_one_official_prediction_is_kept_per_match(): void
    {
        $this->seed(\Database\Seeders\SampleMatchesSeeder::class);

        $base = Prediction::where('is_official', true)->firstOrFail();

        foreach ([1, 2] as $ignored) {
            $copy = $base->replicate();
            $copy->is_official = true;
            $copy->save();
        }

        $this->assertGreaterThan(1, Prediction::where('match_id', $base->match_id)->where('is_official', true)->count());

        $this->artisan('oracle:normalize-official-predictions')->assertExitCode(0);

        $officials = Prediction::where('match_id', $base->match_id)->where('is_official', true)->pluck('id');
        $this->assertCount(1, $officials);
        $this->assertSame((int) Prediction::where('match_id', $base->match_id)->max('id'), (int) $officials->first());
    }

    public function test_provider_plan_refusal_is_cached_and_does_not_burn_quota_again(): void
    {
        config()->set('services.api_football.seasons', [2025]);

        Http::fake([
            'v3.football.api-sports.io/fixtures*' => Http::response([
                'errors' => ['plan' => 'Free plans do not have access to this season, try from 2022 to 2024.'],
                'response' => [],
            ]),
        ]);

        $first = app(ApiFootballService::class)->syncFixtures('2026-11-10', '2026-11-10');
        $callsAfterFirst = Http::recorded()->count();
        $second = app(ApiFootballService::class)->syncFixtures('2026-11-10', '2026-11-10');

        $this->assertSame(1, $first['failed']);
        $this->assertSame(0, $second['failed'], 'La saison refusée doit être ignorée sans nouvel appel.');
        $this->assertSame($callsAfterFirst, Http::recorded()->count());
    }

    public function test_command_fails_when_no_competition_can_be_synchronised(): void
    {
        config()->set('services.api_football.seasons', [2025]);

        Http::fake([
            'v3.football.api-sports.io/fixtures*' => Http::response([
                'errors' => ['plan' => 'Free plans do not have access to this season, try from 2022 to 2024.'],
                'response' => [],
            ]),
        ]);

        $this->artisan('oracle:sync-api-football', ['--date' => '2026-11-10'])
            ->assertExitCode(1);
    }

    public function test_cross_provider_match_with_equivalent_competition_is_not_duplicated(): void
    {
        $primary = app(FootballDataService::class);
        $existing = $primary->ingestMatchRecord([
            'external_id' => 'fd-euro-1',
            'home_team' => 'Spain',
            'away_team' => 'France',
            'date' => '2026-06-15T19:00:00Z',
            'league_code' => 'EC',
            'league_name' => 'European Championship',
            'country' => 'Europe',
            'season' => '2025-2026',
            'status' => 'SCHEDULED',
        ], 'football-data.org');

        $this->assertNotNull($existing);

        $secondary = $primary->ingestMatchRecord([
            'external_id' => 'api-football:fixture:777',
            'home_team' => 'Spain',
            'away_team' => 'France',
            'date' => '2026-06-15T19:00:00+00:00',
            'league_code' => 'AF9999',
            'league_name' => 'European Championship',
            'country' => 'Europe',
            'season' => '2025-2026',
            'status' => 'SCHEDULED',
        ], 'api-football');

        $this->assertNotNull($secondary);
        $this->assertSame(1, FootballMatch::count());
        $this->assertSame('football-data.org', $secondary->data_source);
    }

    public function test_similar_but_distinct_teams_are_not_merged(): void
    {
        $service = app(FootballDataService::class);
        $service->ingestMatchRecord([
            'external_id' => 'fd-real-1',
            'home_team' => 'Real Madrid',
            'away_team' => 'Sevilla',
            'date' => '2026-05-01T19:00:00Z',
            'league_code' => 'PD',
            'league_name' => 'Primera Division',
            'country' => 'Spain',
            'season' => '2025-2026',
            'status' => 'SCHEDULED',
        ], 'football-data.org');

        $service->ingestMatchRecord([
            'external_id' => 'fd-castilla-1',
            'home_team' => 'Real Madrid Castilla',
            'away_team' => 'Sevilla',
            'date' => '2026-05-02T19:00:00Z',
            'league_code' => 'PD',
            'league_name' => 'Primera Division',
            'country' => 'Spain',
            'season' => '2025-2026',
            'status' => 'SCHEDULED',
        ], 'football-data.org');

        $this->assertSame(2, FootballMatch::count());
        $this->assertDatabaseHas('teams', ['name' => 'Real Madrid Castilla']);
    }

    public function test_parlay_detail_page_loads(): void
    {
        $parlay = Parlay::create([
            'title' => 'Combiné de test',
            'date_for' => now()->toDateString(),
            'risk_level' => 'EQUILIBRE',
            'risk_score' => 5.0,
            'combined_odds' => 3.2,
            'combined_prob' => 0.31,
            'selections_json' => [[
                'match_id' => 1,
                'match_label' => 'Équipe A – Équipe B',
                'league' => 'Premier League',
                'match_date_utc' => now()->utc()->toIso8601String(),
                'selection_label' => 'Victoire A',
                'model_prob_pct' => 55.0,
                'odds' => 1.8,
                'price_basis' => 'bookmaker',
            ]],
            'model_version' => 'M3_MARKET_SPECIALISTS_v6',
            'status' => 'PENDING',
        ]);

        $this->get('/parlays/'.$parlay->id)->assertOk()->assertSee('Combiné de test', false);
    }

    public function test_predictions_are_generated_for_api_football_matches(): void
    {
        $match = app(FootballDataService::class)->ingestMatchRecord([
            'external_id' => 'api-football:fixture:999',
            'home_team' => 'Cameroon',
            'away_team' => 'Egypt',
            'date' => now()->addDays(2)->utc()->toIso8601String(),
            'league_code' => 'AF6',
            'league_name' => 'Africa Cup of Nations',
            'country' => 'World',
            'season' => '2026-2027',
            'status' => 'SCHEDULED',
        ], 'api-football');

        $this->assertNotNull($match);

        Http::fake([
            '*127.0.0.1:8001/v1/predict*' => Http::response([
                'match_id' => (string) $match->id,
                'status' => 'ok',
                'availability_status' => 'PYTHON_READY',
                'data_quality' => 38.0,
                'available_markets' => ['1N2'],
                'missing_data' => [],
                'uncertainty' => 'high',
                'model' => [
                    'version' => 'M3_MARKET_SPECIALISTS_v6',
                    'family' => 'pure',
                    'calibration_version' => 'calib_v8_shrunk_team_lambdas',
                ],
                'computed_at' => now()->utc()->toIso8601String(),
                'data_snapshot_hash' => str_repeat('a', 64),
                'expected_goals' => ['home' => 1.62, 'away' => 1.05, 'total' => 2.67],
                'probabilities' => [
                    'home' => ['p' => 0.52, 'ci80' => [0.45, 0.59], 'ci95' => [0.40, 0.64]],
                    'draw' => ['p' => 0.26, 'ci80' => [0.20, 0.32], 'ci95' => [0.18, 0.34]],
                    'away' => ['p' => 0.22, 'ci80' => [0.16, 0.28], 'ci95' => [0.14, 0.30]],
                ],
                'markets' => [
                    '1N2' => ['home' => 0.52, 'draw' => 0.26, 'away' => 0.22],
                    'double_chance' => ['1X' => 0.78, 'X2' => 0.48, '12' => 0.74],
                    'totals' => ['over_2_5' => 0.51, 'under_2_5' => 0.49],
                    'btts' => ['yes' => 0.54, 'no' => 0.46],
                ],
                'top_scores' => [['score' => '1-0', 'p' => 0.12]],
                'matrix' => ['max_goals' => 10, 'truncated_mass' => 0.0000001],
                'quality' => ['score' => 38.0, 'tier' => 'insufficient', 'confidence_tier' => 'low'],
                'confidence_tier' => 'low',
                'abstain' => false,
                'limited_data_mode' => true,
                'abstain_reason' => 'unverified_data_quality',
                'warnings' => ['limited_data:unverified_data_quality'],
                'duration_ms' => 12,
                'uncertainty_method' => 'unavailable',
                'uncertainty_replicates' => 0,
            ]),
        ]);

        $this->artisan('oracle:generate-predictions')->assertExitCode(0);

        $this->assertSame(1, Prediction::where('match_id', $match->id)->where('is_official', true)->count());
        $this->assertDatabaseHas('predictions', [
            'match_id' => $match->id,
            'is_official' => 1,
            'is_abstained' => 0,
            'prediction_origin' => 'PYTHON_ESTIMATE',
            'published_market' => '1N2',
            'published_selection' => 'home',
        ]);
        $this->assertSame('PYTHON_READY', $match->fresh()->analysis_status);
    }
}
