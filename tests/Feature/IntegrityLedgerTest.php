<?php

namespace Tests\Feature;

use App\Models\FootballMatch;
use App\Models\League;
use App\Models\ModelVersion;
use App\Models\Prediction;
use App\Models\PredictionHashChain;
use App\Models\Season;
use App\Models\Team;
use App\Services\Integrity\HashChainService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class IntegrityLedgerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-21 12:00:00', 'Africa/Douala'));
    }

    public function test_rebuild_restores_a_broken_chain_in_chronological_order(): void
    {
        $service = app(HashChainService::class);
        $predictions = [];

        foreach (['A', 'B', 'C'] as $index => $suffix) {
            $prediction = $this->createPrediction($this->createMatch($suffix), $suffix, 1.5 + $index / 10);
            $service->chainPrediction($prediction);
            $predictions[] = $prediction->fresh();
        }

        $this->assertSame('valid', $service->verifyLedgerIntegrity()['status']);

        // Reproduit une suppression au milieu du registre : le bloc suivant
        // pointe alors vers un hash qui n'existe plus.
        DB::table('prediction_hash_chains')
            ->where('prediction_id', $predictions[1]->id)
            ->delete();

        $this->assertSame('compromised', $service->verifyLedgerIntegrity()['status']);

        $result = $service->rebuildLedger();

        $this->assertSame(3, $result['total']);
        $this->assertSame(3, $result['chained']);

        $integrity = $service->verifyLedgerIntegrity();
        $this->assertSame('valid', $integrity['status']);
        $this->assertSame(3, $integrity['total_blocks']);

        $blocks = PredictionHashChain::orderBy('id')->get();
        $this->assertSame(str_repeat('0', 64), $blocks[0]->prev_hash);
        $this->assertSame($blocks[0]->current_hash, $blocks[1]->prev_hash);
        $this->assertSame($blocks[1]->current_hash, $blocks[2]->prev_hash);
        $this->assertSame($blocks[1]->current_hash, Prediction::find($predictions[1]->id)->content_hash);
    }

    public function test_rebuild_is_idempotent(): void
    {
        $service = app(HashChainService::class);
        $prediction = $this->createPrediction($this->createMatch('IDEMPOTENT'), 'IDEMPOTENT');
        $service->chainPrediction($prediction);

        $first = $service->rebuildLedger();
        $second = $service->rebuildLedger();

        $this->assertSame($first['last_hash'], $second['last_hash']);
        $this->assertSame('valid', $service->verifyLedgerIntegrity()['status']);
    }

    public function test_purge_keeps_matches_whose_predictions_are_chained(): void
    {
        $service = app(HashChainService::class);

        $protectedMatch = $this->createMatch('PROTECTED');
        $prediction = $this->createPrediction($protectedMatch, 'PROTECTED');
        $service->chainPrediction($prediction);

        $orphanMatch = $this->createMatch('ORPHAN');

        $this->artisan('oracle:purge-demo-data')->assertExitCode(0);

        $this->assertDatabaseHas('matches', ['id' => $protectedMatch->id]);
        $this->assertDatabaseMissing('matches', ['id' => $orphanMatch->id]);
        $this->assertSame('valid', $service->verifyLedgerIntegrity()['status']);
    }

    private function createMatch(string $suffix): FootballMatch
    {
        $league = League::firstOrCreate(
            ['code' => 'PL'],
            ['name' => 'Premier League', 'country' => 'England', 'tier' => '1', 'is_active' => true]
        );
        $season = Season::firstOrCreate(
            ['league_id' => $league->id, 'name' => '2025-2026'],
            ['year_start' => 2025, 'year_end' => 2026, 'is_current' => true]
        );
        $home = Team::create(['name' => 'Home '.$suffix, 'short_name' => 'H'.substr(md5($suffix), 0, 3), 'country' => 'England']);
        $away = Team::create(['name' => 'Away '.$suffix, 'short_name' => 'A'.substr(md5($suffix), 0, 3), 'country' => 'England']);

        return FootballMatch::create([
            'external_id' => null,
            'data_source' => 'demo',
            'league_id' => $league->id,
            'season_id' => $season->id,
            'home_team_id' => $home->id,
            'away_team_id' => $away->id,
            'match_date_utc' => now()->addHours(6),
            'status' => 'SCHEDULED',
            'quality_score' => 90,
            'quality_tier' => 'usable',
        ]);
    }

    private function createPrediction(FootballMatch $match, string $suffix, float $homeLambda = 1.6): Prediction
    {
        $model = ModelVersion::firstOrCreate(
            ['version_name' => 'ledger-test'],
            ['algorithm' => 'Dixon-Coles', 'status' => 'champion']
        );

        return Prediction::create([
            'match_id' => $match->id,
            'model_version_id' => $model->id,
            'is_official' => true,
            'content_hash' => str_repeat('c', 64),
            'home_lambda' => $homeLambda,
            'away_lambda' => 1.1,
            'prob_home' => 0.55,
            'prob_draw' => 0.25,
            'prob_away' => 0.20,
            'quality_score' => 90,
            'quality_tier' => 'usable',
            'confidence_tier' => 'high',
            'is_abstained' => false,
        ]);
    }
}
