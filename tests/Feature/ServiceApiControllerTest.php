<?php

namespace Tests\Feature;

use App\Services\FootballData\FootballDataService;
use App\Services\Odds\OddsService;
use App\Services\Prediction\PredictionClient;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class ServiceApiControllerTest extends TestCase
{
    public function test_prediction_endpoint_validates_and_forwards_the_request(): void
    {
        $client = Mockery::mock(PredictionClient::class);
        $client->shouldReceive('predict')
            ->once()
            ->with(Mockery::on(fn (array $payload) => $payload['home_team'] === 'Paris FC'
                && $payload['away_team'] === 'Lyon'))
            ->andReturn(['match_id' => '42', 'confidence_tier' => 'high']);
        $this->app->instance(PredictionClient::class, $client);

        $this->postJson('/api/predictions', [
            'match_id' => '42',
            'home_team' => 'Paris FC',
            'away_team' => 'Lyon',
            'quality_score' => 85,
        ])->assertOk()->assertJson(['match_id' => '42', 'confidence_tier' => 'high']);
    }

    public function test_prediction_endpoint_rejects_missing_teams(): void
    {
        $this->postJson('/api/predictions', ['home_team' => 'Paris FC'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['away_team']);
    }

    public function test_prediction_endpoint_rejects_a_single_lambda_override(): void
    {
        $this->postJson('/api/predictions', [
            'home_team' => 'Paris FC',
            'away_team' => 'Lyon',
            'lambda_home_override' => 1.5,
        ])->assertUnprocessable()->assertJsonValidationErrors([
            'lambda_home_override',
            'lambda_away_override',
        ]);
    }

    public function test_prediction_evaluation_endpoint_forwards_valid_items(): void
    {
        $client = Mockery::mock(PredictionClient::class);
        $client->shouldReceive('evaluate')
            ->once()
            ->with([['p_home' => 0.5, 'p_draw' => 0.3, 'p_away' => 0.2, 'outcome' => 'HOME']])
            ->andReturn(['sample_size' => 1, 'mean_rps' => 0.2]);
        $this->app->instance(PredictionClient::class, $client);

        $this->postJson('/api/predictions/evaluate', [
            'items' => [['p_home' => 0.5, 'p_draw' => 0.3, 'p_away' => 0.2, 'outcome' => 'HOME']],
        ])->assertOk()->assertJson(['sample_size' => 1, 'mean_rps' => 0.2]);
    }

    public function test_prediction_evaluation_endpoint_does_not_invent_metrics_when_service_is_down(): void
    {
        $client = Mockery::mock(PredictionClient::class);
        $client->shouldReceive('evaluate')->once()->andThrow(new \RuntimeException('Le service d’évaluation est indisponible.'));
        $this->app->instance(PredictionClient::class, $client);

        $this->postJson('/api/predictions/evaluate', [
            'items' => [['p_home' => 0.5, 'p_draw' => 0.3, 'p_away' => 0.2, 'outcome' => 'HOME']],
        ])->assertStatus(503)->assertJson(['error' => 'Le service d’évaluation est indisponible.']);
    }

    public function test_prediction_evaluation_endpoint_rejects_probabilities_that_do_not_sum_to_one(): void
    {
        $this->postJson('/api/predictions/evaluate', [
            'items' => [['p_home' => 0.5, 'p_draw' => 0.3, 'p_away' => 0.3, 'outcome' => 'HOME']],
        ])->assertUnprocessable()->assertJsonValidationErrors(['items.0']);
    }

    public function test_unavailable_prediction_service_does_not_substitute_a_local_model(): void
    {
        Http::fake(['*' => Http::response([], 503)]);
        config()->set('services.ai_service.url', 'http://127.0.0.1:8001');
        config()->set('services.ai_service.secret', 'test-service-secret');
        $client = new PredictionClient;

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('AI Service prediction failed with HTTP 503.');

        $client->predict([
            'match_id' => 'python-model-required',
            'home_team' => 'Home FC',
            'away_team' => 'Away FC',
        ]);
    }

    public function test_odds_value_endpoint_returns_the_service_result(): void
    {
        $service = Mockery::mock(OddsService::class);
        $service->shouldReceive('evaluateValue')
            ->once()
            ->with(0.5, 0.4, 2.2)
            ->andReturn(['ev_pct' => 10, 'classification' => 'robuste']);
        $this->app->instance(OddsService::class, $service);

        $this->postJson('/api/odds/value', [
            'model_prob' => 0.5,
            'ci80_low' => 0.4,
            'market_odds' => 2.2,
        ])->assertOk()->assertJson(['ev_pct' => 10, 'classification' => 'robuste']);
    }

    public function test_odds_endpoint_rejects_probabilities_outside_their_range(): void
    {
        $this->postJson('/api/odds/value', [
            'model_prob' => 1.2,
            'ci80_low' => 0.4,
            'market_odds' => 2.2,
        ])->assertUnprocessable()->assertJsonValidationErrors(['model_prob']);
    }

    public function test_kelly_endpoint_rejects_fraction_above_documented_limit(): void
    {
        $this->postJson('/api/odds/kelly', [
            'prob' => 0.9,
            'decimal_odds' => 10,
            'fraction' => 0.5,
        ])->assertUnprocessable()->assertJsonValidationErrors(['fraction']);
    }

    public function test_kelly_service_never_exceeds_documented_caps(): void
    {
        $service = app(OddsService::class);

        $result = $service->calculateKellyStake(0.9, 10, 1000, 1.0, 0.5);

        $this->assertSame(0.25, $result['fraction_applied']);
        $this->assertSame(2.0, $result['bankroll_pct']);
        $this->assertSame(20.0, $result['virtual_stake']);
    }

    public function test_import_date_is_recognized_by_the_data_quality_service(): void
    {
        $result = (new FootballDataService)->calculateQualityScore([
            'home_team' => 'Paris FC',
            'away_team' => 'Lyon',
            'date' => '2026-10-28T20:00:00Z',
        ], [2.4, 3.2, 3.0]);

        $this->assertNotContains('date_missing', $result['missing']);
    }

    public function test_matches_without_odds_are_not_marked_usable(): void
    {
        $result = (new FootballDataService)->calculateQualityScore([
            'home_team' => 'Paris FC',
            'away_team' => 'Lyon',
            'date' => '2026-10-28T20:00:00Z',
        ]);

        $this->assertSame('degraded', $result['tier']);
        $this->assertContains('market_odds_partial', $result['missing']);
    }
}
