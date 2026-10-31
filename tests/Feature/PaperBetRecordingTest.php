<?php

namespace Tests\Feature;

use App\Models\FootballMatch;
use App\Models\OddsSnapshot;
use App\Models\PaperBet;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\SampleMatchesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaperBetRecordingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->seed(SampleMatchesSeeder::class);
    }

    public function test_paper_bet_uses_the_selected_snapshot_and_leaves_clv_unknown_without_closing_odds(): void
    {
        $match = FootballMatch::whereIn('status', ['SCHEDULED', 'TIMED'])->firstOrFail();
        $snapshot = OddsSnapshot::where('match_id', $match->id)
            ->where('selection', 'AWAY')
            ->where('is_closing', false)
            ->firstOrFail();

        $this->withoutMiddleware()->post('/odds/paper-bet', [
            'match_id' => $match->id,
            'market' => '1N2',
            'selection' => 'AWAY',
            'odds_snapshot_id' => $snapshot->id,
        ])->assertRedirect('/odds')->assertSessionHas('success');

        $this->assertDatabaseHas('paper_bets', [
            'match_id' => $match->id,
            'prediction_id' => $match->officialPrediction->id,
            'selection' => 'AWAY',
            'placed_odds' => $snapshot->decimal_odds,
            'odds_snapshot_id' => $snapshot->id,
            'closing_odds' => null,
            'clv' => null,
        ]);
    }

    public function test_paper_bet_rejects_a_snapshot_for_a_different_selection(): void
    {
        $match = FootballMatch::whereIn('status', ['SCHEDULED', 'TIMED'])->firstOrFail();
        $existingBets = PaperBet::count();
        $homeSnapshot = OddsSnapshot::where('match_id', $match->id)
            ->where('selection', 'HOME')
            ->where('is_closing', false)
            ->firstOrFail();

        $this->withoutMiddleware()->post('/odds/paper-bet', [
            'match_id' => $match->id,
            'market' => '1N2',
            'selection' => 'AWAY',
            'odds_snapshot_id' => $homeSnapshot->id,
        ])->assertRedirect('/odds')->assertSessionHas('error');

        $this->assertDatabaseCount('paper_bets', $existingBets);
    }
}
