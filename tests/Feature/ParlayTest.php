<?php

namespace Tests\Feature;

use App\Models\Parlay;
use App\Models\FootballMatch;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\SampleMatchesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ParlayTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [['content' => ['parts' => [['text' => 'Analyse du risque du combiné probabiliste.']]]]],
            ]),
        ]);
        $this->seed(DatabaseSeeder::class);
        $this->seed(SampleMatchesSeeder::class);
    }

    public function test_parlays_page_loads_and_generates_combinations(): void
    {
        $response = $this->get('/parlays');
        $response->assertStatus(200);
        $response->assertSee('Combinés Quotidiens');
        $response->assertSee('PROBABILISTE');

        // Doit avoir généré au moins un combiné en base
        $this->assertGreaterThan(0, Parlay::count());
    }

    public function test_parlay_regenerate_endpoint(): void
    {
        $response = $this->withoutMiddleware()->post('/parlays/generate');
        $response->assertRedirect('/parlays?date=' . now()->toDateString());
        $response->assertSessionHas('success');

        $parlay = Parlay::first();
        $this->assertNotNull($parlay);
        $this->assertNotNull($parlay->combined_odds);
        $this->assertNotNull($parlay->combined_prob);
        $this->assertNotNull($parlay->risk_score);
        $this->assertIsArray($parlay->selections_json);
    }

    public function test_dashboard_shows_at_least_ten_increasing_reliability_picks_across_leagues(): void
    {
        $upcoming = FootballMatch::with('officialPrediction')
            ->whereIn('status', ['SCHEDULED', 'TIMED'])
            ->get();
        $this->assertNotEmpty($upcoming);

        for ($index = 0; $index < 6; $index++) {
            $baseMatch = $upcoming[$index % $upcoming->count()];
            $match = FootballMatch::create([
                'external_id' => 'big-parlay-test-'.$index,
                'data_source' => 'test',
                'league_id' => $baseMatch->league_id,
                'season_id' => $baseMatch->season_id,
                'home_team_id' => $baseMatch->home_team_id,
                'away_team_id' => $baseMatch->away_team_id,
                'match_date_utc' => now()->utc()->addDays(10 + $index),
                'status' => 'SCHEDULED',
                'quality_score' => 92.5,
                'quality_tier' => 'usable',
            ]);

            $prediction = $baseMatch->officialPrediction->replicate();
            $prediction->match_id = $match->id;
            $prediction->save();
        }

        $response = $this->get('/');

        $response->assertOk()
            ->assertSee('Gros combinés')
            ->assertSee('Fiabilité croissante');

        $response->assertViewHas('bigParlayPicks', function (array $picks): bool {
            $this->assertGreaterThanOrEqual(10, count($picks));
            $this->assertGreaterThanOrEqual(2, collect($picks)->pluck('league_key')->unique()->count());

            $probabilities = array_column($picks, 'prob');
            $sortedProbabilities = $probabilities;
            sort($sortedProbabilities);
            $this->assertSame($sortedProbabilities, $probabilities);

            return true;
        });
    }
}
