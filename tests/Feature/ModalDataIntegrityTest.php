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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ModalDataIntegrityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.gemini.api_key', 'test-key');
        config()->set('services.gemini.model', 'test-model');
        config()->set('services.ai_service.secret', 'test-secret');
        config()->set('services.ai_service.url', 'http://127.0.0.1:8001');
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [[
                    'content' => ['parts' => [['text' => 'Synthèse limitée aux données reçues.']]],
                ]],
            ]),
            '127.0.0.1:8001/*' => Http::response([
                'expected_goals' => ['home' => null, 'away' => null],
                'probabilities' => [
                    'home' => ['p' => null],
                    'draw' => ['p' => null],
                    'away' => ['p' => null],
                ],
                'markets' => [],
                'top_scores' => [],
                'quality' => ['score' => null, 'tier' => null],
                'confidence_tier' => 'low',
                'abstain' => true,
                'model' => ['version' => 'M3_DIXON_COLES', 'calibration_version' => 'calib_v2'],
            ]),
        ]);
    }

    public function test_modal_payload_keeps_real_values_and_does_not_invent_a_score_matrix(): void
    {
        $match = $this->matchWithPrediction([
            'home_lambda' => 1.85,
            'away_lambda' => 1.15,
            'prob_home' => 0.56,
            'prob_draw' => 0.25,
            'prob_away' => 0.19,
            'derived_markets_json' => [
                'totals' => ['over_2_5' => 0.61, 'under_2_5' => 0.39],
                'btts' => ['yes' => 0.54],
            ],
            'top_scores_json' => [['score' => '2-1', 'p' => 0.12]],
        ], true);

        $payload = $this->getJson(route('matches.modal-data', $match->id))->assertOk()->json();

        $this->assertEquals(0.56, $payload['pronostic']['probabilities']['home']['p']);
        $this->assertEquals(0.61, $payload['pronostic']['markets']['totals']['over_2_5']);
        $this->assertSame('2-1', $payload['pronostic']['top_scores'][0]['score']);
        $this->assertEquals(2.1, $payload['cotes']['summary']['home']['bookmaker_odds']);
        $this->assertNotNull($payload['cotes']['summary']['home']['fair_odds']);
        $this->assertNotNull($payload['cotes']['summary']['home']['ev_pct']);
        $this->assertNull($payload['score_matrix']);
    }

    public function test_missing_markets_and_scores_stay_absent(): void
    {
        $match = $this->matchWithPrediction([
            'prob_home' => 0.56,
            'prob_draw' => 0.25,
            'prob_away' => 0.19,
            'derived_markets_json' => null,
            'top_scores_json' => [],
        ], false);

        $payload = $this->getJson(route('matches.modal-data', $match->id))->assertOk()->json();

        $this->assertEquals(0.56, $payload['pronostic']['probabilities']['home']['p']);
        $this->assertSame([], $payload['pronostic']['markets']);
        $this->assertSame([], $payload['pronostic']['top_scores']);
        $this->assertNull($payload['score_matrix']);
    }

    public function test_bookmaker_odds_remain_visible_when_model_probabilities_are_absent(): void
    {
        $match = $this->scheduledMatch();
        $this->oneN2Odds($match);

        $payload = $this->getJson(route('matches.modal-data', $match->id))->assertOk()->json();

        $this->assertTrue($payload['cotes']['has_odds']);
        $this->assertEquals(2.1, $payload['cotes']['summary']['home']['bookmaker_odds']);
        $this->assertNull($payload['cotes']['summary']['home']['fair_odds']);
        $this->assertNull($payload['cotes']['summary']['home']['ev_pct']);
        $this->assertNull($payload['pronostic']['probabilities']['home']['p']);
        $this->assertNull($payload['pronostic']['expected_goals']['home']);
        $this->assertNull($payload['score_matrix']);
    }

    public function test_partial_live_score_is_not_replaced_by_zero(): void
    {
        $match = $this->matchWithPrediction([], false);
        $match->forceFill([
            'status' => 'IN_PLAY',
            'home_score' => 2,
            'away_score' => null,
        ])->save();

        $payload = $this->getJson(route('matches.modal-data', $match->id))->assertOk()->json();

        $this->assertSame(2, $payload['match_info']['home_score']);
        $this->assertNull($payload['match_info']['away_score']);
    }

    public function test_successive_matches_do_not_share_prediction_payloads(): void
    {
        $first = $this->matchWithPrediction([
            'prob_home' => 0.70,
            'top_scores_json' => [['score' => '3-0', 'p' => 0.2]],
        ], false);
        $second = $this->matchWithPrediction([
            'prob_home' => 0.31,
            'top_scores_json' => [],
            'derived_markets_json' => null,
        ], false);

        $firstPayload = $this->getJson(route('matches.modal-data', $first->id))->assertOk()->json();
        $secondPayload = $this->getJson(route('matches.modal-data', $second->id))->assertOk()->json();

        $this->assertEquals(0.70, $firstPayload['pronostic']['probabilities']['home']['p']);
        $this->assertEquals(0.31, $secondPayload['pronostic']['probabilities']['home']['p']);
        $this->assertSame('3-0', $firstPayload['pronostic']['top_scores'][0]['score']);
        $this->assertSame([], $secondPayload['pronostic']['top_scores']);
    }

    public function test_modal_script_no_longer_contains_the_removed_fallback_figures(): void
    {
        $source = file_get_contents(resource_path('views/partials/match-modal.blade.php'));

        $this->assertStringNotContainsString('0.45', $source);
        $this->assertStringNotContainsString('0.28', $source);
        $this->assertStringNotContainsString('0.27', $source);
        $this->assertStringNotContainsString("'52%'", $source);
        $this->assertStringNotContainsString("'48%'", $source);
        $this->assertStringNotContainsString("'54%'", $source);
        $this->assertStringNotContainsString("'4.8%'", $source);
        $this->assertStringNotContainsString('home_score ?? 0', $source);
    }

    private function matchWithPrediction(array $overrides, bool $withOdds): FootballMatch
    {
        $match = $this->scheduledMatch();
        Prediction::create(array_merge([
            'match_id' => $match->id,
            'model_version_id' => $this->model()->id,
            'is_official' => true,
            'content_hash' => str_repeat('a', 64),
            'home_lambda' => 1.4,
            'away_lambda' => 1.1,
            'prob_home' => 0.50,
            'prob_draw' => 0.28,
            'prob_away' => 0.22,
            'derived_markets_json' => [],
            'top_scores_json' => [],
            'quality_score' => 90,
            'quality_tier' => 'usable',
            'confidence_tier' => 'high',
            'is_abstained' => false,
        ], $overrides));
        if ($withOdds) {
            $this->oneN2Odds($match);
        }

        return $match;
    }

    private function scheduledMatch(): FootballMatch
    {
        $league = League::create([
            'code' => 'L'.substr(uniqid(), -6),
            'name' => 'Ligue test',
            'country' => 'France',
            'tier' => '1',
            'is_active' => true,
        ]);
        $season = Season::create([
            'league_id' => $league->id,
            'name' => '2025-2026',
            'year_start' => 2025,
            'year_end' => 2026,
            'is_current' => true,
        ]);
        $home = Team::create(['name' => 'Domicile '.uniqid(), 'short_name' => 'DOM', 'country' => 'France']);
        $away = Team::create(['name' => 'Exterieur '.uniqid(), 'short_name' => 'EXT', 'country' => 'France']);

        return FootballMatch::create([
            'external_id' => 'modal-'.uniqid(),
            'data_source' => 'football-data.org',
            'league_id' => $league->id,
            'season_id' => $season->id,
            'home_team_id' => $home->id,
            'away_team_id' => $away->id,
            'match_date_utc' => now()->addDay(),
            'status' => 'SCHEDULED',
            'quality_score' => 90,
            'quality_tier' => 'usable',
        ]);
    }

    private function oneN2Odds(FootballMatch $match): void
    {
        $bookmaker = Bookmaker::firstOrCreate(['code' => 'pinnacle'], ['name' => 'Pinnacle']);
        $market = Market::firstOrCreate(['code' => '1N2'], ['name' => 'Résultat 1N2']);
        foreach (['HOME' => 2.10, 'DRAW' => 3.40, 'AWAY' => 3.80] as $selection => $odds) {
            OddsSnapshot::create([
                'match_id' => $match->id,
                'bookmaker_id' => $bookmaker->id,
                'market_id' => $market->id,
                'selection' => $selection,
                'decimal_odds' => $odds,
                'snapshot_time_utc' => now(),
                'is_closing' => false,
                'source' => 'the-odds-api',
            ]);
        }
    }

    private function model(): ModelVersion
    {
        return ModelVersion::firstOrCreate(
            ['version_name' => 'modal-integrity'],
            ['algorithm' => 'Dixon-Coles', 'status' => 'champion']
        );
    }
}
