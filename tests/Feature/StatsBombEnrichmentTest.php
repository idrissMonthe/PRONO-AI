<?php

namespace Tests\Feature;

use App\Models\FootballMatch;
use App\Models\TeamMatchStat;
use App\Services\Import\NormalizedMatch;
use App\Services\Import\StatsBombConnector;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Vérifie l'extraction réelle des événements StatsBomb : corners, fautes,
 * cartons, hors-jeu, compositions, temps de jeu et score à la pause.
 */
class StatsBombEnrichmentTest extends TestCase
{
    use RefreshDatabase;

    private function configure(): void
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
    }

    /** @return array<int, array<string, mixed>> */
    private function events(): array
    {
        return [
            [
                'index' => 1, 'period' => 1, 'minute' => 0, 'second' => 0,
                'type' => ['name' => 'Starting XI'],
                'team' => ['id' => 1],
                'tactics' => ['lineup' => [
                    ['player' => ['id' => 101, 'name' => 'Olivier Giroud'], 'position' => ['name' => 'Center Forward']],
                    ['player' => ['id' => 102, 'name' => 'Mesut Özil'], 'position' => ['name' => 'Center Attacking Midfield']],
                ]],
            ],
            [
                'index' => 2, 'period' => 1, 'minute' => 0, 'second' => 0,
                'type' => ['name' => 'Starting XI'],
                'team' => ['id' => 2],
                'tactics' => ['lineup' => [
                    ['player' => ['id' => 201, 'name' => 'Diego Costa'], 'position' => ['name' => 'Center Forward']],
                ]],
            ],
            [
                'index' => 3, 'period' => 1, 'minute' => 12, 'second' => 0,
                'type' => ['name' => 'Pass'],
                'team' => ['id' => 1],
                'player' => ['id' => 102, 'name' => 'Mesut Özil'],
                'pass' => ['type' => ['name' => 'Corner'], 'outcome' => ['name' => 'Incomplete']],
            ],
            [
                'index' => 4, 'period' => 1, 'minute' => 18, 'second' => 0,
                'type' => ['name' => 'Pass'],
                'team' => ['id' => 2],
                'player' => ['id' => 201, 'name' => 'Diego Costa'],
                'pass' => ['type' => ['name' => 'Recovery'], 'outcome' => ['name' => 'Pass Offside']],
            ],
            [
                'index' => 5, 'period' => 1, 'minute' => 25, 'second' => 0,
                'type' => ['name' => 'Foul Committed'],
                'team' => ['id' => 1],
                'player' => ['id' => 101, 'name' => 'Olivier Giroud'],
                'foul_committed' => ['card' => ['name' => 'Yellow Card']],
            ],
            [
                'index' => 6, 'period' => 1, 'minute' => 34, 'second' => 0,
                'type' => ['name' => 'Shot'],
                'team' => ['id' => 1],
                'player' => ['id' => 101, 'name' => 'Olivier Giroud'],
                'shot' => ['statsbomb_xg' => 0.52, 'outcome' => ['name' => 'Goal']],
            ],
            [
                'index' => 7, 'period' => 2, 'minute' => 71, 'second' => 0,
                'type' => ['name' => 'Shot'],
                'team' => ['id' => 2],
                'player' => ['id' => 201, 'name' => 'Diego Costa'],
                'shot' => ['statsbomb_xg' => 0.11, 'outcome' => ['name' => 'Saved']],
            ],
            [
                'index' => 8, 'period' => 2, 'minute' => 60, 'second' => 0,
                'type' => ['name' => 'Substitution'],
                'team' => ['id' => 1],
                'player' => ['id' => 102, 'name' => 'Mesut Özil'],
                'substitution' => [
                    'outcome' => ['name' => 'Tactical'],
                    'replacement' => ['id' => 103, 'name' => 'Theo Walcott'],
                ],
            ],
            [
                'index' => 9, 'period' => 2, 'minute' => 90, 'second' => 0,
                'type' => ['name' => 'Pass'],
                'team' => ['id' => 2],
                'player' => ['id' => 201, 'name' => 'Diego Costa'],
                'pass' => ['type' => ['name' => 'Throw-in'], 'outcome' => ['name' => 'Complete']],
            ],
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function statsBombMatches(): array
    {
        return [[
            'match_id' => 900001,
            'match_date' => '2016-03-01',
            'kick_off' => '20:00:00.000',
            'home_team' => ['home_team_id' => 1, 'home_team_name' => 'Arsenal'],
            'away_team' => ['away_team_id' => 2, 'away_team_name' => 'Chelsea'],
            'home_score' => 1,
            'away_score' => 0,
            'competition' => ['competition_name' => 'Premier League', 'country_name' => 'England'],
            'season' => ['season_name' => '2015/2016'],
            'competition_stage' => ['name' => 'Regular Season'],
        ]];
    }

    private function normalizedMatch(): NormalizedMatch
    {
        return new NormalizedMatch(
            source: StatsBombConnector::SOURCE,
            externalId: 'statsbomb:900001',
            leagueCode: 'PL',
            leagueName: 'Premier League',
            country: 'England',
            seasonName: '2015-2016',
            seasonYearStart: 2015,
            seasonYearEnd: 2016,
            kickoffUtc: CarbonImmutable::parse('2016-03-01', 'UTC')->startOfDay(),
            kickoffTimeKnown: false,
            homeTeam: 'Arsenal',
            awayTeam: 'Chelsea',
            homeScore: 1,
            awayScore: 0,
            metadata: [
                'statsbomb_match_id' => 900001,
                'statsbomb_home_team_id' => 1,
                'statsbomb_away_team_id' => 2,
            ],
        );
    }

    public function test_event_stats_extract_counts_lineups_and_minutes(): void
    {
        $this->configure();
        Http::fake(['*/events/900001.json' => Http::response($this->events())]);

        $stats = StatsBombConnector::fromConfig()->eventStats($this->normalizedMatch());

        $this->assertSame(1, $stats['home']['corners']);
        $this->assertSame(0, $stats['away']['corners']);
        $this->assertSame(1, $stats['home']['fouls']);
        $this->assertSame(1, $stats['away']['offsides']);
        $this->assertSame(0, $stats['home']['offsides']);
        $this->assertSame(1, $stats['home']['yellow_cards']);
        $this->assertSame(0, $stats['home']['red_cards']);
        $this->assertSame(1, $stats['home']['shots']);
        $this->assertSame(1, $stats['home']['shots_on_target']);
        $this->assertEqualsWithDelta(0.52, $stats['home']['xg'], 0.001);
        $this->assertSame(['home' => 1, 'away' => 0], $stats['ht_goals']);
        $this->assertSame(90, $stats['max_minute']);

        $players = collect($stats['players'])->keyBy('external_id');
        $this->assertCount(4, $players);
        $this->assertSame(90, $players['statsbomb:101']['minutes_played']);
        $this->assertSame(60, $players['statsbomb:102']['minutes_played']);
        $this->assertSame(30, $players['statsbomb:103']['minutes_played']);
        $this->assertFalse($players['statsbomb:103']['is_starter']);
        $this->assertTrue($players['statsbomb:101']['is_starter']);
        $this->assertSame(1, $players['statsbomb:101']['goals']);
        $this->assertSame(1, $players['statsbomb:101']['yellow_cards']);
    }

    public function test_import_persists_counts_players_and_halftime_score_idempotently(): void
    {
        $this->configure();
        Http::fake([
            '*/matches/2/27.json' => Http::response($this->statsBombMatches()),
            '*/events/900001.json' => Http::response($this->events()),
        ]);

        $this->artisan('oracle:import-statsbomb', ['--stats' => true])->assertExitCode(0);

        $match = FootballMatch::where('external_id', 'statsbomb:900001')->firstOrFail();
        $this->assertSame(1, (int) $match->ht_home_score);
        $this->assertSame(0, (int) $match->ht_away_score);

        $home = TeamMatchStat::where('match_id', $match->id)->where('team_id', $match->home_team_id)->firstOrFail();
        $away = TeamMatchStat::where('match_id', $match->id)->where('team_id', $match->away_team_id)->firstOrFail();
        $this->assertSame(1, (int) $home->corners);
        $this->assertSame(1, (int) $home->fouls);
        $this->assertSame(1, (int) $home->yellow_cards);
        $this->assertSame(0, (int) $home->offsides);
        $this->assertSame(1, (int) $away->offsides);
        $this->assertSame(1, (int) $away->shots_on_target);

        $this->assertSame(4, DB::table('player_match_stats')->where('match_id', $match->id)->count());
        $this->assertSame(1, DB::table('player_match_stats')->where('external_player_id', 'statsbomb:101')->value('goals'));

        // Une seconde exécution met à jour sans dupliquer.
        $this->artisan('oracle:import-statsbomb', ['--stats' => true])->assertExitCode(0);

        $this->assertSame(1, FootballMatch::count());
        $this->assertSame(2, TeamMatchStat::count());
        $this->assertSame(4, DB::table('player_match_stats')->count());
        $this->assertSame(1, (int) TeamMatchStat::where('team_id', $match->home_team_id)->value('corners'));
    }

    public function test_missing_events_do_not_create_artificial_zeros(): void
    {
        $this->configure();
        Http::fake(['*/events/900001.json' => Http::response([], 404)]);

        $stats = StatsBombConnector::fromConfig()->eventStats($this->normalizedMatch());

        $this->assertNull($stats['home']);
        $this->assertNull($stats['away']);
        $this->assertSame([], $stats['players']);
        $this->assertNull($stats['ht_goals']);
    }
}
