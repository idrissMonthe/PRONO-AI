<?php

namespace Tests\Feature;

use App\Models\Parlay;
use App\Models\Bookmaker;
use App\Models\FootballMatch;
use App\Models\Market;
use App\Models\OddsSnapshot;
use App\Models\Prediction;
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
            ->assertSee('Version prudente')
            ->assertSee('Version équilibrée')
            ->assertSee('Version audacieuse')
            ->assertSee('Fiabilité croissante');

        $response->assertViewHas('bigParlayPicks', function (array $tiers): bool {
            foreach (['prudent', 'equilibre', 'audacieux'] as $risk) {
                $this->assertGreaterThanOrEqual(10, count($tiers[$risk]));
                $this->assertGreaterThanOrEqual(2, collect($tiers[$risk])->pluck('league_key')->unique()->count());

                $probabilities = array_column($tiers[$risk], 'prob');
                $sortedProbabilities = $probabilities;
                sort($sortedProbabilities);
                $this->assertSame($sortedProbabilities, $probabilities);
            }

            return true;
        });
    }

    public function test_dashboard_uses_complete_no_vig_market_for_abstained_predictions(): void
    {
        FootballMatch::whereIn('status', ['SCHEDULED', 'TIMED'])
            ->update(['match_date_utc' => now()->utc()->addDays(30)]);

        $baseMatch = FootballMatch::with('officialPrediction')
            ->whereIn('status', ['SCHEDULED', 'TIMED'])
            ->firstOrFail();
        $match = FootballMatch::create([
            'external_id' => 'market-fallback-test',
            'data_source' => 'test',
            'league_id' => $baseMatch->league_id,
            'season_id' => $baseMatch->season_id,
            'home_team_id' => $baseMatch->home_team_id,
            'away_team_id' => $baseMatch->away_team_id,
            'match_date_utc' => now()->utc()->addMinutes(10),
            'status' => 'SCHEDULED',
            'quality_score' => 20,
            'quality_tier' => 'low',
        ]);
        $prediction = $baseMatch->officialPrediction->replicate();
        $prediction->match_id = $match->id;
        $prediction->is_abstained = true;
        $prediction->save();

        $marketId = Market::where('code', '1N2')->value('id');
        $bookmaker = Bookmaker::firstOrFail();
        $snapshotTime = now()->utc();
        foreach (['HOME' => 2.0, 'DRAW' => 3.0, 'AWAY' => 4.0] as $selection => $price) {
            OddsSnapshot::create([
                'match_id' => $match->id,
                'bookmaker_id' => $bookmaker->id,
                'market_id' => $marketId,
                'selection' => $selection,
                'decimal_odds' => $price,
                'snapshot_time_utc' => $snapshotTime,
                'source' => 'test',
            ]);
        }

        $this->get('/')
            ->assertOk()
            ->assertSee('Marché sans marge')
            ->assertViewHas('dailyPicks', function (array $groups) use ($match): bool {
                $pick = collect($groups['prudent'])->first(
                    fn (array $candidate): bool => $candidate['match']->id === $match->id
                );

                $this->assertNotNull($pick);
                $this->assertSame('Marché sans marge', $pick['source']);
                $this->assertSame('1X', $pick['key']);
                $this->assertEqualsWithDelta(10 / 13, $pick['prob'], 0.001);
                $this->assertFalse(collect($groups)->flatten(1)->contains(
                    fn (array $candidate): bool => $candidate['match']->id === $match->id
                        && $candidate['key'] === 'exact_score'
                ));

                return true;
            })
            ->assertViewHas('bigParlayPicks', function (array $tiers) use ($match): bool {
                $pick = collect($tiers['prudent'])->first(
                    fn (array $candidate): bool => $candidate['match']->id === $match->id
                );

                $this->assertNotNull($pick);
                $this->assertSame('Marché sans marge', $pick['source']);
                $this->assertSame($match->homeTeam->short_name.' ou nul', $pick['label']);
                $this->assertEqualsWithDelta(10 / 13, $pick['prob'], 0.001);

                $balancedPick = collect($tiers['equilibre'])->first(
                    fn (array $candidate): bool => $candidate['match']->id === $match->id
                );
                $audaciousPick = collect($tiers['audacieux'])->first(
                    fn (array $candidate): bool => $candidate['match']->id === $match->id
                );
                $this->assertGreaterThan($balancedPick['prob'], $pick['prob']);
                $this->assertLessThan($balancedPick['prob'], $audaciousPick['prob']);

                return true;
            });
    }

    public function test_dashboard_includes_model_based_two_goal_margin_picks(): void
    {
        FootballMatch::whereIn('status', ['SCHEDULED', 'TIMED'])
            ->update(['match_date_utc' => now()->utc()->addDays(30)]);
        Prediction::where('is_official', true)->update(['is_abstained' => true]);

        $baseMatch = FootballMatch::with('officialPrediction')
            ->whereIn('status', ['SCHEDULED', 'TIMED'])
            ->firstOrFail();
        $match = FootballMatch::create([
            'external_id' => 'goal-margin-test',
            'data_source' => 'test',
            'league_id' => $baseMatch->league_id,
            'season_id' => $baseMatch->season_id,
            'home_team_id' => $baseMatch->home_team_id,
            'away_team_id' => $baseMatch->away_team_id,
            'match_date_utc' => now()->utc()->addMinutes(10),
            'status' => 'SCHEDULED',
            'quality_score' => 95,
            'quality_tier' => 'usable',
        ]);
        $prediction = $baseMatch->officialPrediction->replicate();
        $prediction->match_id = $match->id;
        $prediction->is_abstained = false;
        $prediction->derived_markets_json = [
            '1N2' => ['home' => 0.90, 'draw' => 0.06, 'away' => 0.04],
            'double_chance' => ['1X' => 0.96, 'X2' => 0.10],
            'totals' => ['over_1_5' => 0.60, 'under_4_5' => 0.75],
            'btts' => ['yes' => 0.20, 'no' => 0.80],
            'combos' => [
                'home_win_over_2_5' => 0.12,
                'away_win_over_2_5' => 0.08,
                'home_win_btts_yes' => 0.10,
                'away_win_btts_yes' => 0.06,
            ],
            'goal_margin' => [
                'home_by_2_plus' => 0.75,
                'away_by_2_plus' => 0.02,
                'home_by_3_plus' => 0.45,
                'away_by_3_plus' => 0.01,
            ],
        ];
        $prediction->top_scores_json = [];
        $prediction->save();

        $this->get('/')
            ->assertOk()
            ->assertViewHas('upcomingMatches', function ($matches) use ($match): bool {
                $dashboardMatch = $matches->firstWhere('id', $match->id);
                $this->assertNotNull($dashboardMatch);
                $this->assertFalse($dashboardMatch->officialPrediction->is_abstained);
                $this->assertEqualsWithDelta(
                    0.75,
                    $dashboardMatch->officialPrediction->derived_markets_json['goal_margin']['home_by_2_plus'],
                    0.0001
                );

                return true;
            })
            ->assertViewHas('dailyPicks', function (array $groups) use ($match): bool {
                $pick = collect($groups['audacieux'])->first(
                    fn (array $candidate): bool => $candidate['match']->id === $match->id
                );

                $this->assertNotNull($pick);
                $this->assertSame('home_by_2_plus', $pick['key']);
                $this->assertSame('Marge de buts', $pick['market']);
                $this->assertEqualsWithDelta(0.75, $pick['prob'], 0.0001);

                return true;
            })
            ->assertViewHas('bigParlayPicks', function (array $tiers) use ($match): bool {
                $pick = collect($tiers['audacieux'])->first(
                    fn (array $candidate): bool => $candidate['match']->id === $match->id
                );

                $this->assertNotNull($pick);
                $this->assertSame('Marge de buts', $pick['market']);
                $this->assertSame($match->homeTeam->short_name.' gagne par 2 buts ou plus', $pick['label']);
                $this->assertEqualsWithDelta(0.75, $pick['prob'], 0.0001);

                return true;
            });
    }
}
