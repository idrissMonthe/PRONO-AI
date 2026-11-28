<?php

namespace Tests\Feature;

use App\Models\FootballMatch;
use App\Models\Market;
use App\Models\OddsSnapshot;
use App\Services\Odds\OddsService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Vérifie le chemin `fixtures.csv` : rencontres à venir importées sans score,
 * cotes écrites en non-closing, datation issue du fichier (jamais de l'heure
 * d'import) et alimentation réelle du blend marché.
 */
class FootballDataUkFixturesTest extends TestCase
{
    use RefreshDatabase;

    private string $cache;

    private string $futureDate;

    private string $pastDate;

    private CarbonImmutable $lastModified;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cache = sys_get_temp_dir().'/fduk-fixtures-'.uniqid();
        File::makeDirectory($this->cache, 0775, true);

        $this->futureDate = CarbonImmutable::now('Europe/London')->addDays(3)->format('d/m/Y');
        $this->pastDate = CarbonImmutable::now('Europe/London')->subDays(2)->format('d/m/Y');
        // RFC 7231 n'a qu'une précision de seconde : la valeur attendue est
        // figée ici, jamais recalculée après l'import.
        $this->lastModified = CarbonImmutable::now('UTC')->subHours(3)->startOfSecond();

        config([
            'imports.football_data_uk.enabled' => true,
            'imports.football_data_uk.cache_path' => $this->cache,
            'imports.football_data_uk.fixtures_url' => 'https://www.football-data.co.uk/fixtures.csv',
            'imports.football_data_uk.fixtures_cache_ttl' => 900,
            'imports.football_data_uk.fixtures_divisions' => [],
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
        // Une rencontre à venir complète, une rencontre déjà jouée (le fichier
        // peut être en retard) et une division non configurée.
        return "Div,Date,Time,HomeTeam,AwayTeam,B365H,B365D,B365A,AvgH,AvgD,AvgA,B365>2.5,B365<2.5\n"
            ."E0,{$this->futureDate},15:00,Arsenal,Chelsea,2.10,3.40,3.60,2.15,3.45,3.55,1.85,1.95\n"
            ."E0,{$this->pastDate},15:00,Liverpool,Everton,1.55,4.20,6.50,1.60,4.10,6.20,1.70,2.10\n"
            ."E2,{$this->futureDate},15:00,AFC Wimbledon,Blackpool,2.50,3.30,2.60,2.52,3.29,2.64,1.80,2.00\n";
    }

    private function import(): void
    {
        Http::fake([
            'www.football-data.co.uk/fixtures.csv' => Http::response($this->csv(), 200, [
                'Last-Modified' => $this->lastModified->toRfc7231String(),
            ]),
        ]);

        $this->artisan('oracle:sync-football-data-uk-fixtures')->assertExitCode(0);
    }

    public function test_imports_upcoming_match_with_non_closing_odds_dated_by_the_file(): void
    {
        $this->import();

        // Une seule rencontre retenue : celle à venir d'une division configurée.
        $this->assertSame(1, FootballMatch::count());

        $match = FootballMatch::firstOrFail();
        $this->assertSame('SCHEDULED', $match->status);
        $this->assertNull($match->home_score);
        $this->assertNull($match->away_score);
        $this->assertSame('football-data-uk', $match->data_source);

        $odds = OddsSnapshot::all();
        $this->assertTrue($odds->every(fn (OddsSnapshot $row): bool => $row->is_closing === false));
        $this->assertTrue($odds->every(fn (OddsSnapshot $row): bool => $row->is_opening === false));
        $this->assertTrue($odds->every(fn (OddsSnapshot $row): bool => $row->source === 'football-data-uk'));

        // Bet365 (3) + consensus moyen (3) + Plus/Moins 2,5 Bet365 (2).
        $this->assertSame(8, $odds->count());
        $this->assertSame(2, $odds->where('selection', 'HOME')->count());

        // La datation vient de l'en-tête du fichier, pas de l'heure d'import.
        foreach ($odds as $row) {
            $this->assertSame(
                $this->lastModified->timestamp,
                $row->snapshot_time_utc->utc()->startOfSecond()->timestamp,
                'La cote doit être datée du relevé publié, jamais de l’exécution.'
            );
        }
    }

    public function test_upcoming_odds_feed_the_market_blend(): void
    {
        $this->import();

        $match = FootballMatch::firstOrFail();
        $marketId = (int) Market::where('code', '1N2')->value('id');

        // C'est exactement l'appel utilisé par GeneratePredictionsCommand.
        $set = app(OddsService::class)->latestComplete1N2Set($match->oddsSnapshots, $marketId);

        $this->assertNotNull($set, 'Le blend marché doit retrouver un set 1N2 non-closing complet.');
        $this->assertCount(3, $set);
        $this->assertSame(
            [2.10, 3.40, 3.60],
            [(float) $set['HOME']->decimal_odds, (float) $set['DRAW']->decimal_odds, (float) $set['AWAY']->decimal_odds]
        );

        $probabilities = app(OddsService::class)->proportionalProbabilities([
            (float) $set['HOME']->decimal_odds,
            (float) $set['DRAW']->decimal_odds,
            (float) $set['AWAY']->decimal_odds,
        ]);
        $this->assertEqualsWithDelta(1.0, array_sum($probabilities), 1e-9);
    }

    public function test_reimport_is_idempotent_while_the_file_is_unchanged(): void
    {
        $this->import();
        $matches = FootballMatch::count();
        $odds = OddsSnapshot::count();

        File::delete($this->cache.'/fixtures.csv');
        File::delete($this->cache.'/fixtures.meta');

        $this->import();

        $this->assertSame($matches, FootballMatch::count());
        $this->assertSame($odds, OddsSnapshot::count(), 'Le même relevé ne doit pas créer de doublons.');
    }

    public function test_a_finished_match_keeps_its_score_when_it_reappears_in_fixtures(): void
    {
        $this->import();

        $match = FootballMatch::firstOrFail();
        $match->forceFill([
            'status' => 'FINISHED',
            'home_score' => 2,
            'away_score' => 1,
            'ht_home_score' => 1,
            'ht_away_score' => 0,
            'match_date_utc' => CarbonImmutable::now('UTC')->addDays(3),
        ])->save();

        // Le fichier est rafraîchi et contient encore la rencontre.
        File::delete($this->cache.'/fixtures.csv');
        File::delete($this->cache.'/fixtures.meta');
        $this->import();

        $match->refresh();
        $this->assertSame('FINISHED', $match->status);
        $this->assertSame(2, (int) $match->home_score);
        $this->assertSame(1, (int) $match->away_score);
    }
}
