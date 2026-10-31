<?php

namespace Tests\Feature;

use App\Models\Bookmaker;
use App\Models\FootballMatch;
use App\Models\League;
use App\Models\Market;
use App\Models\ModelVersion;
use App\Models\OddsSnapshot;
use App\Models\Prediction;
use App\Models\Season;
use App\Models\Team;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MatchModalAndFiltersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.gemini.api_key', 'test-key');
        config()->set('services.gemini.model', 'test-model');
    }

    public function test_matches_page_filters_by_today_in_africa_douala_timezone(): void
    {
        $league = League::create(['code' => 'PL', 'name' => 'Premier League', 'country' => 'England', 'tier' => '1', 'is_active' => true]);
        $season = Season::create(['league_id' => $league->id, 'name' => '2025-2026', 'year_start' => 2025, 'year_end' => 2026, 'is_current' => true]);
        $teamA = Team::create(['name' => 'Team Alpha', 'short_name' => 'Alpha', 'country' => 'England']);
        $teamB = Team::create(['name' => 'Team Beta', 'short_name' => 'Beta', 'country' => 'England']);

        $nowDouala = Carbon::now('Africa/Douala');

        // Match aujourd'hui (15:00 WAT)
        $matchToday = FootballMatch::create([
            'external_id' => 'test_fd_1',
            'data_source' => 'football-data.org',
            'league_id' => $league->id,
            'season_id' => $season->id,
            'home_team_id' => $teamA->id,
            'away_team_id' => $teamB->id,
            'match_date_utc' => $nowDouala->copy()->setTime(15, 0, 0)->utc(),
            'status' => 'SCHEDULED',
            'quality_score' => 90.0,
            'quality_tier' => 'usable',
        ]);

        // Match dans 3 jours
        $matchFuture = FootballMatch::create([
            'external_id' => 'test_fd_2',
            'data_source' => 'football-data.org',
            'league_id' => $league->id,
            'season_id' => $season->id,
            'home_team_id' => $teamB->id,
            'away_team_id' => $teamA->id,
            'match_date_utc' => $nowDouala->copy()->addDays(3)->setTime(15, 0, 0)->utc(),
            'status' => 'SCHEDULED',
            'quality_score' => 90.0,
            'quality_tier' => 'usable',
        ]);

        // 1. Filtre Aujourd'hui (par défaut)
        $response = $this->get(route('matches.index', ['date_filter' => 'today']));
        $response->assertStatus(200);
        $response->assertSee('Team Alpha');
        $response->assertDontSee('test_fd_2');

        // 2. Filtre À venir
        $responseUpcoming = $this->get(route('matches.index', ['date_filter' => 'upcoming']));
        $responseUpcoming->assertStatus(200);
        $responseUpcoming->assertSee('Team Beta');
    }

    public function test_cancelled_or_finished_matches_do_not_appear_as_upcoming(): void
    {
        $league = League::create(['code' => 'PD', 'name' => 'La Liga', 'country' => 'Spain', 'tier' => '1', 'is_active' => true]);
        $season = Season::create(['league_id' => $league->id, 'name' => '2025-2026', 'year_start' => 2025, 'year_end' => 2026, 'is_current' => true]);
        $teamA = Team::create(['name' => 'Madrid FC', 'short_name' => 'Madrid', 'country' => 'Spain']);
        $teamB = Team::create(['name' => 'Sevilla FC', 'short_name' => 'Sevilla', 'country' => 'Spain']);

        // Match annulé
        FootballMatch::create([
            'external_id' => 'test_cancelled_1',
            'data_source' => 'football-data.org',
            'league_id' => $league->id,
            'season_id' => $season->id,
            'home_team_id' => $teamA->id,
            'away_team_id' => $teamB->id,
            'match_date_utc' => now()->addDay(),
            'status' => 'CANCELLED',
            'quality_score' => 90.0,
            'quality_tier' => 'usable',
        ]);

        $response = $this->get(route('matches.index', ['date_filter' => 'upcoming']));
        $response->assertStatus(200);
        $response->assertDontSee('Madrid FC');
    }

    public function test_match_modal_data_endpoint_returns_json_with_all_four_tabs(): void
    {
        $league = League::create(['code' => 'FL1', 'name' => 'Ligue 1', 'country' => 'France', 'tier' => '1', 'is_active' => true]);
        $season = Season::create(['league_id' => $league->id, 'name' => '2025-2026', 'year_start' => 2025, 'year_end' => 2026, 'is_current' => true]);
        $teamA = Team::create(['name' => 'PSG', 'short_name' => 'PSG', 'country' => 'France']);
        $teamB = Team::create(['name' => 'Marseille', 'short_name' => 'OM', 'country' => 'France']);
        $modelVersion = ModelVersion::create([
            'version_name' => 'M3_DIXON_COLES',
            'algorithm' => 'dixon_coles',
            'is_active' => true,
        ]);

        $match = FootballMatch::create([
            'external_id' => 'test_modal_1',
            'data_source' => 'football-data.org',
            'league_id' => $league->id,
            'season_id' => $season->id,
            'home_team_id' => $teamA->id,
            'away_team_id' => $teamB->id,
            'match_date_utc' => now()->addDays(2),
            'status' => 'SCHEDULED',
            'quality_score' => 95.0,
            'quality_tier' => 'usable',
        ]);

        Prediction::create([
            'match_id' => $match->id,
            'model_version_id' => $modelVersion->id,
            'calibration_version' => 'calib_v2',
            'is_official' => true,
            'freeze_time_utc' => now()->addDay(),
            'content_hash' => 'dummy_hash',
            'home_lambda' => 1.85,
            'away_lambda' => 1.15,
            'prob_home' => 0.52,
            'prob_draw' => 0.26,
            'prob_away' => 0.22,
            'intervals_json' => [
                'ci80' => ['home' => [0.48, 0.56], 'draw' => [0.23, 0.29], 'away' => [0.18, 0.26]],
            ],
            'derived_markets_json' => [
                'totals' => ['over_2_5' => 0.58, 'under_2_5' => 0.42],
                'btts' => ['yes' => 0.55, 'no' => 0.45],
            ],
            'top_scores_json' => [
                ['score' => '2-1', 'p' => 0.12],
                ['score' => '1-1', 'p' => 0.11],
            ],
            'quality_score' => 95.0,
            'quality_tier' => 'usable',
            'confidence_tier' => 'high',
            'is_abstained' => false,
            'execution_time_ms' => 12,
        ]);

        $bookmaker = Bookmaker::create(['code' => 'pinnacle', 'name' => 'Pinnacle']);
        $market1N2 = Market::create(['code' => '1N2', 'name' => 'Résultat 1N2']);

        OddsSnapshot::create([
            'match_id' => $match->id,
            'bookmaker_id' => $bookmaker->id,
            'market_id' => $market1N2->id,
            'selection' => 'HOME',
            'decimal_odds' => 2.10,
            'snapshot_time_utc' => now(),
            'is_closing' => false,
            'source' => 'football-data.org',
        ]);
        OddsSnapshot::create([
            'match_id' => $match->id,
            'bookmaker_id' => $bookmaker->id,
            'market_id' => $market1N2->id,
            'selection' => 'DRAW',
            'decimal_odds' => 3.60,
            'snapshot_time_utc' => now(),
            'is_closing' => false,
            'source' => 'football-data.org',
        ]);
        OddsSnapshot::create([
            'match_id' => $match->id,
            'bookmaker_id' => $bookmaker->id,
            'market_id' => $market1N2->id,
            'selection' => 'AWAY',
            'decimal_odds' => 3.80,
            'snapshot_time_utc' => now(),
            'is_closing' => false,
            'source' => 'football-data.org',
        ]);

        $response = $this->getJson(route('matches.modal-data', $match->id));
        $response->assertStatus(200);

        // Vérification des 4 onglets obligatoires
        $response->assertJsonStructure([
            'match_info' => ['id', 'home_team', 'away_team', 'league', 'match_date_douala', 'status', 'data_source'],
            'pronostic' => ['expected_goals', 'probabilities', 'markets', 'top_scores', 'model'],
            'signaux_ia' => ['text', 'provider', 'verified'],
            'cotes' => ['has_odds', 'summary'],
            'forme' => ['home_form_letters', 'away_form_letters'],
        ]);

        $this->assertSame('PSG', $response->json('match_info.home_team'));
        $this->assertSame(0.52, (float) $response->json('pronostic.probabilities.home.p'));
        $this->assertTrue($response->json('cotes.has_odds'));
    }
}
