<?php

namespace Tests\Feature;

use App\Models\FootballMatch;
use App\Models\ImportRun;
use App\Models\League;
use App\Models\ProviderEntityMap;
use appmodelsproviderentitymap;
use App\Models\Season;
use App\Models\TeamMatchStat;
use App\Services\FootballData\FootballDataService;
use App\Services\Import\NormalizedMatch;
use App\Services\Import\StatsBombConnector;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MultiSourceImportTest extends TestCase
{
    use RefreshDatabase;

    private string $statsbombCache;

    protected function setUp(): void
    {
        parent::setUp();
        // Cache d'événements isolé par test : les fichiers réels de
        // `storage/app/statsbomb-cache` fausseraient les scénarios simulés.
        $this->statsbombCache = sys_get_temp_dir().'/prono-sb-'.spl_object_hash($this);
        config(['imports.statsbomb.cache_path' => $this->statsbombCache]);
    }

    protected function tearDown(): void
    {
        \Illuminate\Support\Facades\File::deleteDirectory($this->statsbombCache);
        parent::tearDown();
    }

    private function openFootballPayload(): array
    {
        return [
            'name' => 'English Premier League 2025/26',
            'matches' => [
                [
                    'round' => 'Matchday 1',
                    'date' => '2025-08-15',
                    'time' => '20:00',
                    'team1' => 'Liverpool FC',
                    'team2' => 'AFC Bournemouth',
                    'score' => ['ft' => [4, 2], 'ht' => [1, 0]],
                ],
                [
                    'round' => 'Matchday 1',
                    'date' => '2025-08-16',
                    'team1' => 'Arsenal FC',
                    'team2' => 'Chelsea FC',
                    'score' => [0, 0],
                ],
            ],
        ];
    }

    private function configureOpenFootball(): void
    {
        config([
            'imports.openfootball.enabled' => true,
            'imports.openfootball.base_url' => 'https://raw.githubusercontent.com/openfootball/football.json/master',
            'imports.openfootball.seasons' => ['2025-26'],
            'imports.openfootball.competitions' => [
                'en.1.json' => ['code' => 'PL', 'name' => 'Premier League', 'country' => 'England'],
            ],
        ]);
    }

    public function test_openfootball_import_is_traceable_and_idempotent(): void
    {
        $this->configureOpenFootball();
        Http::fake([
            'raw.githubusercontent.com/openfootball/football.json/*' => Http::response($this->openFootballPayload()),
        ]);

        $this->artisan('oracle:import-openfootball')->assertExitCode(0);

        $this->assertSame(2, FootballMatch::count());
        $match = FootballMatch::where('external_id', 'like', 'openfootball:%')->firstOrFail();
        $this->assertSame('openfootball', $match->data_source);
        $this->assertSame('FINISHED', $match->status);
        $season = Season::findOrFail($match->season_id);
        $this->assertSame('2025-2026', $season->name);
        $this->assertSame(2025, $season->year_start);
        $this->assertDatabaseHas('provider_entity_maps', [
            'entity_type' => 'match',
            'provider' => 'openfootball',
            'external_id' => $match->external_id,
        ]);

        $this->artisan('oracle:import-openfootball')->assertExitCode(0);
        $this->assertSame(2, FootballMatch::count(), 'Une seconde exécution ne doit pas dupliquer les matchs.');
        $this->assertSame(2, ImportRun::where('source', 'openfootball')->count());
    }

    public function test_a_divergent_score_from_another_source_never_overwrites_the_official_score(): void
    {
        $this->configureOpenFootball();
        $footballData = app(FootballDataService::class);
        $league = League::create(['name' => 'Premier League', 'code' => 'PL', 'country' => 'England', 'tier' => '1']);
        $season = Season::create(['league_id' => $league->id, 'name' => '2025-2026', 'year_start' => 2025, 'year_end' => 2026]);
        $home = $footballData->resolveTeam('Liverpool FC', $league);
        $away = $footballData->resolveTeam('AFC Bournemouth', $league);

        $existing = FootballMatch::create([
            'external_id' => 'fd-123',
            'data_source' => 'football-data.org',
            'league_id' => $league->id,
            'season_id' => $season->id,
            'home_team_id' => $home->id,
            'away_team_id' => $away->id,
            'match_date_utc' => '2025-08-15 20:00:00',
            'status' => 'FINISHED',
            'home_score' => 2,
            'away_score' => 1,
        ]);

        Http::fake([
            'raw.githubusercontent.com/openfootball/football.json/*' => Http::response([
                'name' => 'English Premier League 2025/26',
                'matches' => [[
                    'date' => '2025-08-15',
                    'time' => '20:00',
                    'team1' => 'Liverpool FC',
                    'team2' => 'AFC Bournemouth',
                    'score' => ['ft' => [0, 0]],
                ]],
            ]),
        ]);

        $this->artisan('oracle:import-openfootball')->assertExitCode(0);

        $existing->refresh();
        $this->assertSame(2, (int) $existing->home_score);
        $this->assertSame(1, (int) $existing->away_score);
        $this->assertSame('football-data.org', $existing->data_source);
        $this->assertSame(1, ImportRun::where('source', 'openfootball')->firstOrFail()->divergent);
        $this->assertDatabaseHas('provider_entity_maps', [
            'provider' => 'openfootball',
            'internal_id' => $existing->id,
        ]);
    }

    public function test_openfootball_world_parses_football_txt_without_inventing_kickoff_time(): void
    {
        config([
            'imports.openfootball_world.enabled' => true,
            'imports.openfootball_world.base_url' => 'https://raw.githubusercontent.com/openfootball/world/master',
            'imports.openfootball_world.leagues' => [
                'senegal-2025-26' => [
                    'path' => 'africa/senegal/2025-26_sn1.txt',
                    'code' => 'SN1',
                    'name' => 'Ligue 1 Sénégal',
                    'country' => 'Senegal',
                    'season' => '2025-2026',
                ],
            ],
        ]);

        Http::fake([
            'raw.githubusercontent.com/openfootball/world/*' => Http::response(<<<'TXT'
= Senegal | Ligue 1 2025/26

▪ Matchday 1
  Sat Nov 1 2025
           Teunguéth FC                    v US Gorée                        0-0
TXT),
        ]);

        $this->artisan('oracle:import-openfootball-world')->assertExitCode(0);

        $match = FootballMatch::where('data_source', 'openfootball-world')->firstOrFail();
        $this->assertSame('0-0', $match->home_score.'-'.$match->away_score);
        $this->assertSame('00:00:00', $match->match_date_utc->format('H:i:s'));
        $this->assertSame('SN1', League::findOrFail($match->league_id)->code);
    }

    public function test_statsbomb_import_links_match_and_stores_shot_stats(): void
    {
        config([
            'imports.statsbomb.enabled' => true,
            'imports.statsbomb.base_url' => 'https://raw.githubusercontent.com/statsbomb/open-data/master/data',
            'imports.statsbomb.stats_limit' => 5,
            'imports.statsbomb.seasons' => [[
                'competition_id' => 2,
                'season_id' => 27,
                'code' => 'PL',
                'name' => 'Premier League',
                'country' => 'England',
                'season' => '2015-2016',
            ]],
        ]);

        Http::fake([
            '*/matches/2/27.json' => Http::response([[
                'match_id' => 3754023,
                'match_date' => '2015-08-29',
                'kick_off' => '15:00:00.000',
                'competition' => ['competition_id' => 2, 'competition_name' => 'Premier League', 'country_name' => 'England'],
                'season' => ['season_id' => 27, 'season_name' => '2015/2016'],
                'home_team' => ['home_team_id' => 1, 'home_team_name' => 'Arsenal'],
                'away_team' => ['away_team_id' => 2, 'away_team_name' => 'Chelsea'],
                'home_score' => 2,
                'away_score' => 1,
            ]]),
            '*/events/3754023.json' => Http::response([
                ['type' => ['name' => 'Shot'], 'team' => ['id' => 1], 'shot' => ['statsbomb_xg' => 0.35, 'outcome' => ['name' => 'Goal']]],
                ['type' => ['name' => 'Shot'], 'team' => ['id' => 1], 'shot' => ['statsbomb_xg' => 0.10, 'outcome' => ['name' => 'Saved']]],
                ['type' => ['name' => 'Shot'], 'team' => ['id' => 2], 'shot' => ['statsbomb_xg' => 0.08, 'outcome' => ['name' => 'Blocked']]],
                ['type' => ['name' => 'Pass'], 'team' => ['id' => 2]],
            ]),
        ]);

        $this->artisan('oracle:import-statsbomb', ['--stats' => true])->assertExitCode(0);

        $match = FootballMatch::firstOrFail();
        $this->assertSame('statsbomb', $match->data_source);

        $home = TeamMatchStat::where('match_id', $match->id)->where('is_home', true)->firstOrFail();
        $this->assertSame(2, (int) $home->shots);
        $this->assertSame(2, (int) $home->shots_on_target);
        $this->assertEqualsWithDelta(0.45, (float) $home->xg, 0.001);

        $away = TeamMatchStat::where('match_id', $match->id)->where('is_home', false)->firstOrFail();
        $this->assertSame(1, (int) $away->shots);
        $this->assertSame(0, (int) $away->shots_on_target);
    }

    public function test_statsbomb_partial_stats_import_exits_successfully(): void
    {
        config([
            'imports.statsbomb.enabled' => true,
            'imports.statsbomb.base_url' => 'https://raw.githubusercontent.com/statsbomb/open-data/master/data',
            'imports.statsbomb.stats_limit' => 5,
            'imports.statsbomb.retry_attempts' => 2,
            'imports.statsbomb.retry_pause_ms' => 0,
            'imports.statsbomb.seasons' => [[
                'competition_id' => 2,
                'season_id' => 27,
                'code' => 'PL',
                'name' => 'Premier League',
                'country' => 'England',
                'season' => '2015-2016',
            ]],
        ]);

        Http::fake([
            '*/matches/2/27.json' => Http::response([
                [
                    'match_id' => 3754023,
                    'match_date' => '2015-08-29',
                    'kick_off' => '15:00:00.000',
                    'competition' => ['competition_id' => 2, 'competition_name' => 'Premier League', 'country_name' => 'England'],
                    'season' => ['season_id' => 27, 'season_name' => '2015/2016'],
                    'home_team' => ['home_team_id' => 1, 'home_team_name' => 'Arsenal'],
                    'away_team' => ['away_team_id' => 2, 'away_team_name' => 'Chelsea'],
                    'home_score' => 2,
                    'away_score' => 1,
                ],
                [
                    'match_id' => 3754217,
                    'match_date' => '2015-08-30',
                    'kick_off' => '16:00:00.000',
                    'competition' => ['competition_id' => 2, 'competition_name' => 'Premier League', 'country_name' => 'England'],
                    'season' => ['season_id' => 27, 'season_name' => '2015/2016'],
                    'home_team' => ['home_team_id' => 3, 'home_team_name' => 'Liverpool'],
                    'away_team' => ['away_team_id' => 4, 'away_team_name' => 'Manchester City'],
                    'home_score' => 0,
                    'away_score' => 0,
                ],
            ]),
            '*/events/3754023.json' => Http::response([
                ['type' => ['name' => 'Shot'], 'team' => ['id' => 1], 'shot' => ['statsbomb_xg' => 0.30, 'outcome' => ['name' => 'Goal']]],
            ]),
            // Fichier d'événements définitivement indisponible pour ce match.
            '*/events/3754217.json' => Http::response([], 500),
        ]);

        $this->artisan('oracle:import-statsbomb', ['--stats' => true])->assertExitCode(0);

        // Les deux matchs sont en base, un seul a des statistiques.
        $this->assertSame(2, FootballMatch::count());
        $this->assertSame(2, TeamMatchStat::count());

        $run = ImportRun::latest('id')->firstOrFail();
        $this->assertSame('partial', $run->status);
        $this->assertSame(2, (int) $run->imported);
        $this->assertSame(1, (int) $run->metadata['stats_imported']);
        $this->assertStringContainsString('3754217', (string) $run->error);
    }

    public function test_statsbomb_offset_pages_through_stats_batches(): void
    {
        config([
            'imports.statsbomb.enabled' => true,
            'imports.statsbomb.base_url' => 'https://raw.githubusercontent.com/statsbomb/open-data/master/data',
            'imports.statsbomb.stats_limit' => 2,
            'imports.statsbomb.retry_attempts' => 1,
            'imports.statsbomb.retry_pause_ms' => 0,
            'imports.statsbomb.seasons' => [[
                'competition_id' => 2,
                'season_id' => 27,
                'code' => 'PL',
                'name' => 'Premier League',
                'country' => 'England',
                'season' => '2015-2016',
            ]],
        ]);

        $matches = [];
        $events = [];
        foreach ([3754023, 3754217, 3754300] as $index => $matchId) {
            $matches[] = [
                'match_id' => $matchId,
                'match_date' => sprintf('2015-08-%02d', 29 + $index),
                'kick_off' => '15:00:00.000',
                'competition' => ['competition_id' => 2, 'competition_name' => 'Premier League', 'country_name' => 'England'],
                'season' => ['season_id' => 27, 'season_name' => '2015/2016'],
                'home_team' => ['home_team_id' => 10 + $index, 'home_team_name' => 'Home '.$index],
                'away_team' => ['away_team_id' => 20 + $index, 'away_team_name' => 'Away '.$index],
                'home_score' => 1,
                'away_score' => 1,
            ];
            $events['*/events/'.$matchId.'.json'] = Http::response([
                ['type' => ['name' => 'Shot'], 'team' => ['id' => 10 + $index], 'shot' => ['statsbomb_xg' => 0.25, 'outcome' => ['name' => 'Goal']]],
            ]);
        }

        Http::fake(['*/matches/2/27.json' => Http::response($matches)] + $events);

        $this->artisan('oracle:import-statsbomb', ['--stats' => true, '--limit' => 1, '--offset' => 1])->assertExitCode(0);

        // Les trois matchs sont importés, seul celui en position 2 reçoit des statistiques.
        $this->assertSame(3, FootballMatch::count());
        $this->assertSame(2, TeamMatchStat::count());

        $withStats = ProviderEntityMap::where([
            'provider' => StatsBombConnector::SOURCE,
            'external_id' => 'statsbomb:3754217',
        ])->firstOrFail();

        $this->assertSame(2, TeamMatchStat::where('match_id', $withStats->internal_id)->count());
        $this->assertSame(0, TeamMatchStat::where('match_id', '!=', $withStats->internal_id)->count());
    }

    public function test_statsbomb_import_exits_failure_when_nothing_is_imported(): void
    {
        config([
            'imports.statsbomb.enabled' => true,
            'imports.statsbomb.base_url' => 'https://raw.githubusercontent.com/statsbomb/open-data/master/data',
            'imports.statsbomb.retry_attempts' => 1,
            'imports.statsbomb.retry_pause_ms' => 0,
            'imports.statsbomb.seasons' => [[
                'competition_id' => 2,
                'season_id' => 27,
                'code' => 'PL',
                'name' => 'Premier League',
                'country' => 'England',
                'season' => '2015-2016',
            ]],
        ]);

        Http::fake([
            '*/matches/2/27.json' => Http::response([], 404),
        ]);

        $this->artisan('oracle:import-statsbomb')->assertExitCode(1);

        $this->assertSame(0, FootballMatch::count());
        $this->assertSame('failed', ImportRun::latest('id')->firstOrFail()->status);
    }

    public function test_statsbomb_retries_a_failed_event_download(): void
    {
        config([
            'imports.statsbomb.retry_attempts' => 2,
            'imports.statsbomb.retry_pause_ms' => 0,
        ]);

        Http::fake([
            '*/events/3754023.json' => Http::sequence()
                ->pushStatus(503)
                ->push([
                    ['type' => ['name' => 'Shot'], 'team' => ['id' => 1], 'shot' => ['statsbomb_xg' => 0.40, 'outcome' => ['name' => 'Goal']]],
                ]),
        ]);

        $match = new NormalizedMatch(
            source: StatsBombConnector::SOURCE,
            externalId: 'statsbomb:3754023',
            leagueCode: 'PL',
            leagueName: 'Premier League',
            country: 'England',
            seasonName: '2015-2016',
            seasonYearStart: 2015,
            seasonYearEnd: 2016,
            kickoffUtc: CarbonImmutable::parse('2015-08-29', 'UTC')->startOfDay(),
            kickoffTimeKnown: false,
            homeTeam: 'Arsenal',
            awayTeam: 'Chelsea',
            homeScore: 2,
            awayScore: 1,
            metadata: [
                'statsbomb_match_id' => 3754023,
                'statsbomb_home_team_id' => 1,
                'statsbomb_away_team_id' => 2,
            ],
        );

        $stats = StatsBombConnector::fromConfig()->shotStats($match);

        // La première réponse (503) est ignorée, la seconde est exploitée.
        $this->assertNotNull($stats['home']);
        $this->assertSame(1, $stats['home']['shots']);
        $this->assertEqualsWithDelta(0.40, $stats['home']['xg'], 0.001);
        $this->assertSame(0, $stats['away']['shots']);
    }
}
