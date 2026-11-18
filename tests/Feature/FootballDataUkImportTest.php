<?php

namespace Tests\Feature;

use App\Models\FootballMatch;
use App\Models\OddsSnapshot;
use App\Models\TeamMatchStat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Vérifie le connecteur Football-Data.co.uk : lecture des colonnes réelles,
 * écriture des statistiques et des cotes de clôture, déduplication et respect
 * des faits déjà fournis par un autre fournisseur.
 */
class FootballDataUkImportTest extends TestCase
{
    use RefreshDatabase;

    private string $cache;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cache = sys_get_temp_dir().'/fduk-test-'.uniqid();
        File::makeDirectory($this->cache, 0775, true);

        config([
            'imports.football_data_uk.enabled' => true,
            'imports.football_data_uk.base_url' => 'https://www.football-data.co.uk/mmz4281',
            'imports.football_data_uk.cache_path' => $this->cache,
            'imports.football_data_uk.seasons' => ['2627'],
            'imports.football_data_uk.competitions' => [
                'E0' => ['code' => 'PL', 'name' => 'Premier League', 'country' => 'England'],
            ],
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->cache);

        parent::tearDown();
    }

    private function csv(): string
    {
        // En-tête réel : BOM, colonnes statistiques, cotes, puis une colonne
        // absente sur la seconde ligne (valeur manquante, jamais zéro).
        return "\xEF\xBB\xBFDiv,Date,Time,HomeTeam,AwayTeam,FTHG,FTAG,FTR,HTHG,HTAG,HTR,Referee,HxG,AxG,HS,AS,HST,AST,HF,AF,HC,AC,HY,AY,HR,AR,B365H,B365D,B365A,PSH,PSD,PSA,MaxH,MaxD,MaxA,AvgH,AvgD,AvgA,B365>2.5,B365<2.5\n"
            ."E0,21/08/2026,20:00,Arsenal,Coventry,3,0,H,2,0,H,T Bramall,1.88,0.20,20,4,6,1,10,13,8,2,1,1,0,0,1.20,7.00,13.0,1.19,6.77,14.19,1.23,7.40,18.0,1.20,7.50,13.0,1.57,2.38\n"
            ."E0,22/08/2026,15:00,Liverpool,Everton,1,1,D,0,1,D,M Oliver,,,14,9,5,3,11,12,6,5,2,2,0,0,1.55,4.20,6.50,,,,,,,,,,,,\n";
    }

    public function test_imports_statistics_and_closing_odds_without_inventing_missing_values(): void
    {
        Http::fake([
            'www.football-data.co.uk/*' => Http::response($this->csv(), 200),
        ]);

        $this->artisan('oracle:import-football-data-uk', ['--season' => ['2627'], '--division' => ['E0']])
            ->assertExitCode(0);

        $this->assertSame(2, FootballMatch::count());

        $arsenal = TeamMatchStat::whereHas('match', fn ($query) => $query->where('home_score', 3))
            ->firstOrFail();

        $this->assertSame('football-data-uk', $arsenal->provider);
        $this->assertSame(8, (int) $arsenal->corners);
        $this->assertSame(10, (int) $arsenal->fouls);
        $this->assertSame(1, (int) $arsenal->yellow_cards);
        $this->assertSame(0, (int) $arsenal->red_cards);
        $this->assertSame(20, (int) $arsenal->shots);
        $this->assertSame(6, (int) $arsenal->shots_on_target);
        $this->assertEqualsWithDelta(1.88, (float) $arsenal->xg, 1e-6);

        // Seconde rencontre : xG et cotes absents du fichier. La statistique
        // présente est écrite, l'absente reste nulle — jamais zéro.
        $liverpoolMatch = FootballMatch::where('home_score', 1)->where('away_score', 1)->firstOrFail();
        $liverpool = TeamMatchStat::where('match_id', $liverpoolMatch->id)->where('is_home', true)->firstOrFail();
        $this->assertSame(14, (int) $liverpool->shots);
        $this->assertNull($liverpool->xg, 'Une colonne absente ne doit pas devenir 0.');

        // Cotes : un snapshot de clôture par bookmaker réellement présent.
        $closing = OddsSnapshot::where('is_closing', true)->get();
        $this->assertTrue($closing->every(fn (OddsSnapshot $row) => $row->source === 'football-data-uk'));
        // Arsenal : Bet365, Pinnacle, Max et Avg (12 lignes) + Plus/Moins 2,5.
        // Liverpool : seule la colonne Bet365 est renseignée.
        $this->assertSame(3, OddsSnapshot::where('match_id', $liverpoolMatch->id)->count());
        $this->assertSame(17, OddsSnapshot::where('source', 'football-data-uk')->count());
    }

    public function test_reimport_is_idempotent_and_never_duplicates_odds(): void
    {
        Http::fake([
            'www.football-data.co.uk/*' => Http::response($this->csv(), 200),
        ]);

        $this->artisan('oracle:import-football-data-uk', ['--season' => ['2627'], '--division' => ['E0']])
            ->assertExitCode(0);

        $matches = FootballMatch::count();
        $stats = TeamMatchStat::count();
        $odds = OddsSnapshot::count();

        $this->artisan('oracle:import-football-data-uk', ['--season' => ['2627'], '--division' => ['E0']])
            ->assertExitCode(0);

        $this->assertSame($matches, FootballMatch::count());
        $this->assertSame($stats, TeamMatchStat::count());
        $this->assertSame($odds, OddsSnapshot::count());
    }

    public function test_does_not_overwrite_facts_already_measured_by_another_provider(): void
    {
        Http::fake([
            'www.football-data.co.uk/*' => Http::response($this->csv(), 200),
        ]);

        // Une première source a déjà mesuré les corners de la rencontre.
        $this->artisan('oracle:import-football-data-uk', ['--season' => ['2627'], '--division' => ['E0']])
            ->assertExitCode(0);

        $stat = TeamMatchStat::whereHas('match', fn ($query) => $query->where('home_score', 3))->firstOrFail();
        $stat->forceFill(['provider' => 'statsbomb', 'corners' => 99])->save();

        $this->artisan('oracle:import-football-data-uk', ['--season' => ['2627'], '--division' => ['E0']])
            ->assertExitCode(0);

        $stat->refresh();
        $this->assertSame(99, (int) $stat->corners, 'Un autre fournisseur ne doit pas écraser la valeur StatsBomb.');
        $this->assertSame('statsbomb', $stat->provider);
    }
}
