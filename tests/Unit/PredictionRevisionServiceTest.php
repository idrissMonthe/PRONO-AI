<?php

namespace Tests\Unit;

use App\Services\Prediction\PredictionRevisionService;
use App\Services\AIExplanation\GeminiExplanationService;
use PHPUnit\Framework\TestCase;

class PredictionRevisionServiceTest extends TestCase
{
    private function service(): PredictionRevisionService
    {
        $gemini = $this->createMock(GeminiExplanationService::class);
        $gemini->method('reviewPrediction')->willReturn(['fallback' => true]);
        return new PredictionRevisionService($gemini);
    }

    private function predictionResult(array $markets): array
    {
        return ['markets' => $markets, 'top_scores' => []];
    }

    public function test_keep_decision(): void
    {
        $review = $this->service()->review($this->predictionResult(['1N2' => ['home' => 0.66, 'draw' => 0.20, 'away' => 0.14], 'btts' => ['yes' => 0.55]]), ['quality_score' => 85]);
        $this->assertSame('keep', $review['decision']);
    }

    public function test_replace_decision_uses_an_existing_market_candidate(): void
    {
        $review = $this->service()->review($this->predictionResult(['1N2' => ['home' => 0.42, 'draw' => 0.30, 'away' => 0.28], 'double_chance' => ['1X' => 0.78, 'X2' => 0.58]]), ['quality_score' => 85]);
        $this->assertSame('replace', $review['decision']);
        $this->assertSame('double_chance', $review['final_candidate']['market']);
    }

    public function test_reject_decision_for_low_quality(): void
    {
        $review = $this->service()->review($this->predictionResult(['1N2' => ['home' => 0.66, 'draw' => 0.20, 'away' => 0.14]]), ['quality_score' => 40]);
        $this->assertSame('reject', $review['decision']);
    }

    public function test_insufficient_data_decision_when_no_market_is_calculable(): void
    {
        $review = $this->service()->review($this->predictionResult([]), ['quality_score' => 90]);
        $this->assertSame('insufficient_data', $review['decision']);
    }

    public function test_gemini_can_replace_only_with_an_existing_candidate(): void
    {
        $gemini = $this->createMock(GeminiExplanationService::class);
        $gemini->method('reviewPrediction')->willReturn([
            'fallback' => false,
            'decision' => 'replace',
            'candidate_key' => 'double_chance:1X',
            'reasoning' => 'Le marché alternatif est cohérent.',
            'supporting_factors' => ['candidate_calculated_by_python'],
            'risk_factors' => [],
            'required_recalculation' => true,
            'confidence_in_revision' => 'medium',
            'provider' => 'google_gemini',
            'model' => 'test-model',
        ]);
        $review = (new PredictionRevisionService($gemini))->review($this->predictionResult([
            '1N2' => ['home' => 0.42, 'draw' => 0.30, 'away' => 0.28],
            'double_chance' => ['1X' => 0.78, 'X2' => 0.58],
        ]), ['quality_score' => 85]);
        $this->assertSame('replace', $review['decision']);
        $this->assertSame(0.78, $review['final_candidate']['probability']);
        $this->assertSame('google_gemini', $review['provider']);
    }
}
