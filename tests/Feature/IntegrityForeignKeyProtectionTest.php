<?php

namespace Tests\Feature;

use App\Models\FootballMatch;
use App\Models\League;
use App\Models\ModelVersion;
use App\Models\Prediction;
use App\Models\Season;
use App\Models\Team;
use App\Services\Integrity\HashChainService;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class IntegrityForeignKeyProtectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_direct_sql_deletion_of_a_match_with_a_chained_prediction_is_blocked(): void
    {
        [$match, $prediction] = $this->chainedPrediction();

        try {
            DB::table('matches')->where('id', $match->id)->delete();
            $this->fail('La suppression directe du match aurait dû être refusée par la clé étrangère.');
        } catch (QueryException $exception) {
            $this->assertMatchesRegularExpression('/foreign key/i', $exception->getMessage());
        }

        $this->assertDatabaseHas('matches', ['id' => $match->id]);
        $this->assertDatabaseHas('predictions', ['id' => $prediction->id]);
        $this->assertDatabaseHas('prediction_hash_chains', ['prediction_id' => $prediction->id]);
    }

    public function test_direct_sql_deletion_of_a_chained_prediction_is_blocked(): void
    {
        [$match, $prediction] = $this->chainedPrediction();

        try {
            DB::table('predictions')->where('id', $prediction->id)->delete();
            $this->fail('La suppression directe de la prédiction aurait dû être refusée par la clé étrangère.');
        } catch (QueryException $exception) {
            $this->assertMatchesRegularExpression('/foreign key/i', $exception->getMessage());
        }

        $this->assertDatabaseHas('matches', ['id' => $match->id]);
        $this->assertDatabaseHas('predictions', ['id' => $prediction->id]);
        $this->assertDatabaseHas('prediction_hash_chains', ['prediction_id' => $prediction->id]);
    }

    /**
     * @return array{0:FootballMatch,1:Prediction}
     */
    private function chainedPrediction(): array
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
        $home = Team::create(['league_id' => $league->id, 'name' => 'Home FK', 'short_name' => 'HFK', 'country' => 'England']);
        $away = Team::create(['league_id' => $league->id, 'name' => 'Away FK', 'short_name' => 'AFK', 'country' => 'England']);

        $match = FootballMatch::create([
            'external_id' => 'fk:test:'.uniqid(),
            'data_source' => 'manual',
            'league_id' => $league->id,
            'season_id' => $season->id,
            'home_team_id' => $home->id,
            'away_team_id' => $away->id,
            'match_date_utc' => Carbon::parse('2026-11-21 18:00:00'),
            'status' => 'SCHEDULED',
            'quality_score' => 90,
            'quality_tier' => 'usable',
        ]);

        $model = ModelVersion::create([
            'version_name' => 'fk-test',
            'algorithm' => 'Dixon-Coles',
            'status' => 'champion',
        ]);

        $prediction = Prediction::create([
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
        ]);

        app(HashChainService::class)->chainPrediction($prediction);

        return [$match, $prediction];
    }
}
