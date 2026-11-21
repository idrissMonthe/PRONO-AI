<?php

namespace Tests\Feature;

use App\Models\Bookmaker;
use App\Models\FootballMatch;
use App\Models\League;
use App\Models\Market;
use App\Models\ModelVersion;
use App\Models\OddsSnapshot;
use App\Models\Parlay;
use App\Models\Prediction;
use App\Models\Season;
use App\Models\Team;
use App\Models\TeamAlias;
use App\Models\TeamMatchStat;
use App\Models\TeamRating;
use App\Services\Deduplication\TeamAndMatchMergeService;
use App\Services\Integrity\HashChainService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TeamAndMatchMergeTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_merges_a_duplicate_team_and_its_duplicated_fixture(): void
    {
        $league = League::create([
            'code' => 'PL',
            'name' => 'Premier League',
            'country' => 'England',
            'tier' => '1',
            'is_active' => true,
        ]);
        $season = Season::create([
            'league_id' => $league->id,
            'name' => '2026-2027',
            'year_start' => 2026,
            'year_end' => 2027,
            'is_current' => true,
        ]);

        $canonical = Team::create([
            'league_id' => $league->id,
            'name' => 'Canonical FC',
            'short_name' => 'CAN',
            'country' => 'England',
        ]);
        $duplicate = Team::create([
            'league_id' => $league->id,
            'name' => 'Canonical',
            'short_name' => 'CAN2',
            'country' => 'England',
        ]);
        $opponent = Team::create([
            'league_id' => $league->id,
            'name' => 'Opponent FC',
            'short_name' => 'OPP',
            'country' => 'England',
        ]);

        $kickoff = Carbon::parse('2026-11-21 18:00:00');

        $canonicalMatch = FootballMatch::create([
            'external_id' => 'fdo:test:1',
            'data_source' => 'football-data.org',
            'last_synced_at' => now(),
            'league_id' => $league->id,
            'season_id' => $season->id,
            'home_team_id' => $canonical->id,
            'away_team_id' => $opponent->id,
            'match_date_utc' => $kickoff,
            'status' => 'FINISHED',
            'home_score' => 2,
            'away_score' => 1,
            'quality_score' => 90,
            'quality_tier' => 'usable',
        ]);

        $duplicateMatch = FootballMatch::create([
            'external_id' => 'fduk:test:1',
            'data_source' => 'football-data-uk',
            'last_synced_at' => now()->subDay(),
            'league_id' => $league->id,
            'season_id' => $season->id,
            'home_team_id' => $duplicate->id,
            'away_team_id' => $opponent->id,
            'match_date_utc' => $kickoff,
            'status' => 'FINISHED',
            'home_score' => 2,
            'away_score' => 1,
            'quality_score' => 90,
            'quality_tier' => 'usable',
        ]);

        $bookmaker = Bookmaker::create([
            'name' => 'Test Bookmaker',
            'code' => 'TEST',
            'type' => 'low_margin',
            'is_active' => true,
        ]);
        $market = Market::create([
            'code' => '1X2',
            'name' => '1X2',
            'category' => 'main',
        ]);

        foreach ([$canonicalMatch, $duplicateMatch] as $match) {
            OddsSnapshot::create([
                'match_id' => $match->id,
                'bookmaker_id' => $bookmaker->id,
                'market_id' => $market->id,
                'selection' => 'HOME',
                'decimal_odds' => 2.0,
                'snapshot_time_utc' => now(),
                'source' => 'test',
            ]);
        }

        TeamMatchStat::create([
            'match_id' => $canonicalMatch->id,
            'team_id' => $canonical->id,
            'provider' => 'football-data.org',
            'is_home' => true,
            'shots' => 10,
        ]);
        TeamMatchStat::create([
            'match_id' => $duplicateMatch->id,
            'team_id' => $duplicate->id,
            'provider' => 'football-data-uk',
            'is_home' => true,
            'shots' => 12,
        ]);
        TeamMatchStat::create([
            'match_id' => $duplicateMatch->id,
            'team_id' => $opponent->id,
            'provider' => 'football-data-uk',
            'is_home' => false,
            'shots' => 8,
        ]);

        $model = ModelVersion::create([
            'version_name' => 'merge-test',
            'algorithm' => 'Dixon-Coles',
            'status' => 'champion',
        ]);

        $canonicalPrediction = Prediction::create($this->predictionPayload($canonicalMatch, $model));
        $duplicatePrediction = Prediction::create($this->predictionPayload($duplicateMatch, $model));

        $chain = app(HashChainService::class);
        $chain->chainPrediction($canonicalPrediction);
        $chain->chainPrediction($duplicatePrediction);

        TeamAlias::create([
            'team_id' => $duplicate->id,
            'alias' => 'Canonical Old',
            'source' => 'test',
            'confidence' => 1,
            'is_validated' => true,
        ]);
        TeamAlias::create([
            'team_id' => $duplicate->id,
            'alias' => 'Canonical Reserves',
            'source' => 'test',
            'confidence' => 1,
            'is_validated' => true,
        ]);

        TeamRating::create([
            'team_id' => $canonical->id,
            'as_of_date' => '2026-11-21',
            'elo_rating' => 1500,
        ]);
        TeamRating::create([
            'team_id' => $duplicate->id,
            'as_of_date' => '2026-11-21',
            'elo_rating' => 1510,
        ]);
        TeamRating::create([
            'team_id' => $duplicate->id,
            'as_of_date' => '2026-11-20',
            'elo_rating' => 1490,
        ]);

        $parlay = Parlay::create([
            'title' => 'Test parlay',
            'date_for' => '2026-11-21',
            'risk_level' => 'MODERE',
            'risk_score' => 5,
            'combined_odds' => 2,
            'combined_prob' => 0.5,
            'selections_json' => [
                ['match_id' => $duplicateMatch->id, 'selection' => 'HOME'],
            ],
            'model_version' => 'test',
            'status' => 'PENDING',
        ]);

        DB::table('provider_entity_maps')->insert([
            ['entity_type' => 'team', 'internal_id' => $duplicate->id, 'provider' => 'test', 'external_id' => 'team:duplicate'],
            ['entity_type' => 'match', 'internal_id' => $duplicateMatch->id, 'provider' => 'test', 'external_id' => 'match:duplicate'],
        ]);

        $stats = app(TeamAndMatchMergeService::class)->merge([$duplicate->id => $canonical->id]);

        $this->assertSame(1, $stats['teams_merged']);
        $this->assertSame(1, $stats['matches_merged']);
        $this->assertSame(1, $stats['predictions_moved']);
        $this->assertTrue($stats['ledger_rebuilt']);

        $this->assertDatabaseMissing('teams', ['id' => $duplicate->id]);
        $this->assertDatabaseHas('matches', ['id' => $canonicalMatch->id]);
        $this->assertDatabaseMissing('matches', ['id' => $duplicateMatch->id]);
        $this->assertSame(1, FootballMatch::where('league_id', $league->id)->count());

        $this->assertSame(2, DB::table('odds_snapshots')->where('match_id', $canonicalMatch->id)->count());
        $this->assertSame(2, DB::table('predictions')->where('match_id', $canonicalMatch->id)->count());
        $this->assertSame(1, DB::table('predictions')->where('match_id', $canonicalMatch->id)->where('is_official', true)->count());
        $this->assertSame(2, DB::table('team_match_stats')->where('match_id', $canonicalMatch->id)->count());

        $this->assertSame(2, TeamAlias::where('team_id', $canonical->id)->count());
        $this->assertSame(2, TeamRating::where('team_id', $canonical->id)->count());

        $this->assertSame($canonicalMatch->id, $parlay->fresh()->selections_json[0]['match_id']);
        $this->assertSame(
            $canonical->id,
            DB::table('provider_entity_maps')->where('external_id', 'team:duplicate')->value('internal_id')
        );
        $this->assertSame(
            $canonicalMatch->id,
            DB::table('provider_entity_maps')->where('external_id', 'match:duplicate')->value('internal_id')
        );

        $integrity = app(HashChainService::class)->verifyLedgerIntegrity();
        $this->assertSame('valid', $integrity['status']);
        $this->assertSame(2, $integrity['total_blocks']);
    }

    /**
     * @return array<string,mixed>
     */
    private function predictionPayload(FootballMatch $match, ModelVersion $model): array
    {
        return [
            'match_id' => $match->id,
            'model_version_id' => $model->id,
            'is_official' => true,
            'content_hash' => str_repeat('c', 64),
            'home_lambda' => 1.6,
            'away_lambda' => 1.1,
            'prob_home' => 0.55,
            'prob_draw' => 0.25,
            'prob_away' => 0.20,
            'quality_score' => 90,
            'quality_tier' => 'usable',
            'confidence_tier' => 'high',
            'is_abstained' => false,
        ];
    }
}
