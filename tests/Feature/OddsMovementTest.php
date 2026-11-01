<?php

namespace Tests\Feature;

use App\Models\Bookmaker;
use App\Models\FootballMatch;
use App\Models\Market;
use App\Models\OddsSnapshot;
use App\Models\PaperBet;
use App\Services\Odds\OddsMovementService;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\SampleMatchesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OddsMovementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.ai_service.secret', null);
        $this->seed(DatabaseSeeder::class);
        $this->seed(SampleMatchesSeeder::class);
    }

    public function test_market_page_compares_model_to_devigged_opening_and_current_probabilities(): void
    {
        $match = FootballMatch::whereIn('status', ['SCHEDULED', 'TIMED'])->firstOrFail();
        OddsSnapshot::where('match_id', $match->id)->delete();
        $bookmaker = Bookmaker::where('code', 'pinnacle')->firstOrFail();
        $marketId = Market::where('code', '1N2')->value('id');

        $this->createOddsSet($match, $bookmaker, $marketId, now()->subHours(2), [2.40, 3.20, 3.00]);
        $this->createOddsSet($match, $bookmaker, $marketId, now()->subHour(), [2.00, 3.50, 3.70]);

        $response = $this->get('/odds');

        $response->assertOk()
            ->assertSee('Évolution du marché sans marge')
            ->assertSee('Écart modèle / marché')
            ->assertViewHas('oddsMovements', function (array $movements) use ($match): bool {
                $homeMovement = collect($movements)->first(fn (array $movement): bool => $movement['match']->id === $match->id
                    && $movement['selection_code'] === 'HOME');

                $this->assertNotNull($homeMovement);
                $this->assertEqualsWithDelta(2.40, $homeMovement['opening_odds'], 0.001);
                $this->assertEqualsWithDelta(2.00, $homeMovement['current_odds'], 0.001);
                $this->assertNotEquals($homeMovement['opening_probability'], $homeMovement['current_probability']);
                $this->assertEqualsWithDelta(
                    (float) $match->officialPrediction->prob_home - $homeMovement['current_probability'],
                    $homeMovement['model_market_edge'],
                    0.001
                );

                return true;
            });
    }

    public function test_closing_snapshots_update_paper_bet_clv_using_no_vig_closing_market(): void
    {
        $match = FootballMatch::whereIn('status', ['SCHEDULED', 'TIMED'])->firstOrFail();
        $match->forceFill([
            'match_date_utc' => now()->subHour(),
            'status' => 'FINISHED',
        ])->save();
        OddsSnapshot::where('match_id', $match->id)->delete();
        $bookmaker = Bookmaker::where('code', 'pinnacle')->firstOrFail();
        $marketId = Market::where('code', '1N2')->value('id');

        $opening = $this->createOddsSet($match, $bookmaker, $marketId, now()->subHours(5), [2.40, 3.20, 3.00]);
        $closing = $this->createOddsSet($match, $bookmaker, $marketId, now()->subHours(2), [2.00, 3.50, 3.70]);
        $incompleteOpening = $this->createPartialSnapshot($match, $bookmaker, $marketId, now()->subHours(6), 2.60);
        $incompleteClosing = $this->createPartialSnapshot($match, $bookmaker, $marketId, now()->subHour(), 1.90);
        $bet = PaperBet::create([
            'prediction_id' => $match->officialPrediction->id,
            'match_id' => $match->id,
            'market' => '1N2',
            'selection' => 'HOME',
            'placed_odds' => 2.40,
            'odds_snapshot_id' => $opening['HOME']->id,
            'staking_rule' => 'flat_1u',
            'stake_units' => 1,
            'status' => 'PENDING',
            'placed_at_utc' => now()->subHours(4),
        ]);

        $stats = app(OddsMovementService::class)->refreshMarkersAndPaperBetClv();

        $this->assertGreaterThanOrEqual(3, $stats['opening_snapshots']);
        $this->assertGreaterThanOrEqual(3, $stats['closing_snapshots']);
        $this->assertSame(1, $stats['paper_bets_updated']);
        $this->assertSame(3, OddsSnapshot::where('match_id', $match->id)->where('is_opening', true)->count());
        $this->assertSame(3, OddsSnapshot::where('match_id', $match->id)->where('is_closing', true)->count());
        $this->assertEqualsWithDelta(2.00, (float) $bet->fresh()->closing_odds, 0.001);
        $this->assertGreaterThan(0, (float) $bet->fresh()->clv);

        foreach (['HOME', 'DRAW', 'AWAY'] as $selection) {
            $this->assertTrue($opening[$selection]->fresh()->is_opening);
            $this->assertTrue($closing[$selection]->fresh()->is_closing);
        }
        $this->assertFalse($incompleteOpening->fresh()->is_opening);
        $this->assertFalse($incompleteClosing->fresh()->is_closing);
    }

    private function createOddsSet(FootballMatch $match, Bookmaker $bookmaker, int $marketId, $time, array $prices): array
    {
        $snapshots = [];
        foreach (['HOME', 'DRAW', 'AWAY'] as $index => $selection) {
            $snapshots[$selection] = OddsSnapshot::create([
                'match_id' => $match->id,
                'bookmaker_id' => $bookmaker->id,
                'market_id' => $marketId,
                'selection' => $selection,
                'decimal_odds' => $prices[$index],
                'snapshot_time_utc' => $time,
                'source' => 'the-odds-api',
            ]);
        }

        return $snapshots;
    }

    private function createPartialSnapshot(FootballMatch $match, Bookmaker $bookmaker, int $marketId, $time, float $price): OddsSnapshot
    {
        return OddsSnapshot::create([
            'match_id' => $match->id,
            'bookmaker_id' => $bookmaker->id,
            'market_id' => $marketId,
            'selection' => 'HOME',
            'decimal_odds' => $price,
            'snapshot_time_utc' => $time,
            'source' => 'the-odds-api',
        ]);
    }
}
