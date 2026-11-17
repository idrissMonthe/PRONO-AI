<?php

namespace Tests\Feature;

use App\Models\FootballMatch;
use App\Models\League;
use App\Models\ModelVersion;
use App\Models\Prediction;
use App\Models\PredictionEvaluation;
use App\Models\Season;
use App\Models\Team;
use App\Services\Prediction\PredictionSettlementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MarketSettlementTest extends TestCase
{
    use RefreshDatabase;

    public function test_asian_total_push_is_neither_a_win_nor_a_loss(): void
    {
        $league = League::create(['name' => 'Ligue', 'code' => 'SET', 'country' => 'France', 'tier' => '1', 'is_active' => true]);
        $season = Season::create(['league_id' => $league->id, 'name' => '2025-2026', 'year_start' => 2025, 'year_end' => 2026, 'is_current' => true]);
        $home = Team::create(['name' => 'Domicile', 'short_name' => 'DOM', 'country' => 'France']);
        $away = Team::create(['name' => 'Extérieur', 'short_name' => 'EXT', 'country' => 'France']);
        $model = ModelVersion::create(['version_name' => 'settlement', 'algorithm' => 'Dixon-Coles', 'status' => 'champion']);
        $match = FootballMatch::create([
            'external_id' => 'settle-asian',
            'data_source' => 'football-data.org',
            'league_id' => $league->id,
            'season_id' => $season->id,
            'home_team_id' => $home->id,
            'away_team_id' => $away->id,
            'match_date_utc' => now()->subDay(),
            'status' => 'FINISHED',
            'home_score' => 1,
            'away_score' => 1,
            'quality_score' => 80,
            'quality_tier' => 'usable',
        ]);
        Prediction::create([
            'match_id' => $match->id,
            'model_version_id' => $model->id,
            'is_official' => true,
            'content_hash' => str_repeat('a', 64),
            'home_lambda' => 1.2,
            'away_lambda' => 1.1,
            'prob_home' => 0.40,
            'prob_draw' => 0.28,
            'prob_away' => 0.32,
            'published_market' => 'asian_totals',
            'published_selection' => 'over_2_0',
            'published_probability' => 0.46,
            'is_abstained' => false,
            'quality_score' => 80,
            'quality_tier' => 'usable',
        ]);

        app(PredictionSettlementService::class)->settleFinishedPredictions();

        $push = PredictionEvaluation::query()->where('market_key', 'published')->first();
        $this->assertNotNull($push);
        $this->assertSame('PUSH', $push->status);
        $this->assertNull($push->brier_score);
        $family = PredictionEvaluation::query()->where('market_group', 'Ligne asiatique')->first();
        $this->assertSame('PUSH', $family->status);
        $this->assertNotSame('WON', $push->status);
        $this->assertNotSame('LOST', $push->status);
    }

    public function test_existing_half_line_total_still_settles_as_win_or_loss(): void
    {
        $league = League::create(['name' => 'Ligue 2', 'code' => 'SE2', 'country' => 'France', 'tier' => '1', 'is_active' => true]);
        $season = Season::create(['league_id' => $league->id, 'name' => '2025-2026', 'year_start' => 2025, 'year_end' => 2026, 'is_current' => true]);
        $home = Team::create(['name' => 'Home', 'short_name' => 'HOM', 'country' => 'France']);
        $away = Team::create(['name' => 'Away', 'short_name' => 'AWA', 'country' => 'France']);
        $model = ModelVersion::create(['version_name' => 'settlement-half', 'algorithm' => 'Dixon-Coles', 'status' => 'champion']);
        $match = FootballMatch::create([
            'external_id' => 'settle-half',
            'data_source' => 'football-data.org',
            'league_id' => $league->id,
            'season_id' => $season->id,
            'home_team_id' => $home->id,
            'away_team_id' => $away->id,
            'match_date_utc' => now()->subDay(),
            'status' => 'FINISHED',
            'home_score' => 2,
            'away_score' => 1,
            'quality_score' => 80,
            'quality_tier' => 'usable',
        ]);
        Prediction::create([
            'match_id' => $match->id,
            'model_version_id' => $model->id,
            'is_official' => true,
            'content_hash' => str_repeat('b', 64),
            'home_lambda' => 1.4,
            'away_lambda' => 1.1,
            'prob_home' => 0.50,
            'prob_draw' => 0.25,
            'prob_away' => 0.25,
            'published_market' => 'totals',
            'published_selection' => 'over_2_5',
            'published_probability' => 0.61,
            'derived_markets_json' => ['totals' => ['over_2_5' => 0.61, 'under_2_5' => 0.39]],
            'is_abstained' => false,
            'quality_score' => 80,
            'quality_tier' => 'usable',
        ]);

        app(PredictionSettlementService::class)->settleFinishedPredictions();

        $this->assertSame('WON', PredictionEvaluation::query()->where('market_key', 'published')->value('status'));
    }
}
