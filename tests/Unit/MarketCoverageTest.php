<?php

namespace Tests\Unit;

use App\Services\AIExplanation\GeminiExplanationService;
use App\Services\Prediction\PredictionRevisionService;
use Tests\TestCase;

class MarketCoverageTest extends TestCase
{
    private function service(): PredictionRevisionService
    {
        $gemini = $this->createMock(GeminiExplanationService::class);
        $gemini->method('reviewPrediction')->willReturn(['fallback' => true]);
        $gemini->method('analyzeSparseMatch')->willReturn(['decision' => 'no_prediction']);

        return new PredictionRevisionService($gemini);
    }

    public function test_matrix_markets_become_candidates_without_invented_prices(): void
    {
        $candidates = $this->service()->candidatesFromResult([
            'markets' => [
                '1N2' => ['home' => 0.57, 'draw' => 0.21, 'away' => 0.22],
                'double_chance' => ['1X' => 0.78, 'X2' => 0.43, '12' => 0.79],
                'totals' => ['over_0_5' => 0.93, 'under_0_5' => 0.07, 'over_2_5' => 0.61, 'under_2_5' => 0.39],
                'team_totals' => [
                    'home' => ['over_0_5' => 0.84, 'over_1_5' => 0.55, 'under_1_5' => 0.45],
                    'away' => ['over_0_5' => 0.48],
                ],
                'btts' => ['yes' => 0.58, 'no' => 0.42],
                'asian_totals' => [
                    '2' => [
                        'line' => 2.0,
                        'over' => ['win' => 0.46, 'push' => 0.15, 'loss' => 0.39],
                        'under' => ['win' => 0.39, 'push' => 0.15, 'loss' => 0.46],
                    ],
                ],
            ],
        ], ['home_team' => 'PSG', 'away_team' => 'Marseille']);

        $keys = collect($candidates)->pluck('candidate_key');
        $this->assertTrue($keys->contains('double_chance:12'));
        $this->assertTrue($keys->contains('totals:over_0_5:0.5'));
        $this->assertTrue($keys->contains('totals:over_2_5:2.5'));
        $this->assertTrue($keys->contains('team_totals:home_OVER_0.5:0.5'));
        $this->assertTrue($keys->contains('btts:no'));
        $asian = collect($candidates)->firstWhere('market', 'asian_totals');
        $this->assertSame(0.15, $asian['push_probability']);
        $this->assertSame('estimated', $asian['odds_source']);
        $this->assertNull($asian['expected_value']);
    }

    public function test_missing_markets_produce_no_artificial_candidate(): void
    {
        $candidates = $this->service()->candidatesFromResult(['markets' => []], []);

        $this->assertSame([], $candidates);
        $review = $this->service()->review(['markets' => [], 'abstain' => false], ['quality_score' => 80]);
        $this->assertNull($review['python_candidate']);
        $this->assertSame(PredictionRevisionService::STATUS_INSUFFICIENT, $review['gemini_status']);
    }

    public function test_matches_can_publish_different_markets(): void
    {
        $service = $this->service();
        $lowScoring = $service->review([
            'markets' => [
                '1N2' => ['home' => 0.48, 'draw' => 0.28, 'away' => 0.24],
                'totals' => ['over_2_5' => 0.61, 'under_2_5' => 0.39],
                'btts' => ['yes' => 0.41, 'no' => 0.59],
            ],
        ], ['quality_score' => 80]);
        $homeGoals = $service->review([
            'markets' => [
                '1N2' => ['home' => 0.58, 'draw' => 0.24, 'away' => 0.18],
                'team_totals' => ['home' => ['over_1_5' => 0.66], 'away' => ['over_0_5' => 0.44]],
                'btts' => ['yes' => 0.40, 'no' => 0.60],
            ],
        ], ['quality_score' => 80]);

        $this->assertSame('totals', $lowScoring['python_candidate']['market']);
        $this->assertSame('over_2_5', $lowScoring['python_candidate']['selection']);
        $this->assertSame('team_totals', $homeGoals['python_candidate']['market']);
        $this->assertNotSame($lowScoring['python_candidate']['market'], $homeGoals['python_candidate']['market']);
        $this->assertFalse($lowScoring['python_candidate']['high_confidence']);
        $this->assertSame('insufficient_sample', $homeGoals['python_candidate']['calibration_status']);
    }

    public function test_btts_no_is_not_high_confidence_without_calibration(): void
    {
        $review = $this->service()->review([
            'markets' => [
                '1N2' => ['home' => 0.46, 'draw' => 0.27, 'away' => 0.27],
                'btts' => ['yes' => 0.22, 'no' => 0.78],
            ],
        ], ['quality_score' => 90]);

        $this->assertSame('btts', $review['python_candidate']['market']);
        $this->assertSame('no', $review['python_candidate']['selection']);
        $this->assertFalse($review['python_candidate']['high_confidence']);
        $this->assertSame('Non suffisamment calibré', $review['python_candidate']['calibration_label']);
    }

    public function test_bookmaker_value_outranks_a_higher_probability_without_value(): void
    {
        $review = $this->service()->review([
            'markets' => [
                '1N2' => ['home' => 0.70, 'draw' => 0.18, 'away' => 0.12],
                'totals' => ['over_2_5' => 0.60, 'under_2_5' => 0.40],
            ],
        ], [
            'quality_score' => 85,
            'odds_context' => [
                '1N2' => ['home' => 1.40],
                'totals' => ['2.5' => ['over' => 1.90]],
            ],
        ]);

        $this->assertSame('totals', $review['python_candidate']['market']);
        $this->assertSame('over_2_5', $review['python_candidate']['selection']);
        $this->assertSame('bookmaker', $review['python_candidate']['odds_source']);
        $this->assertGreaterThan(0, $review['python_candidate']['expected_value']);
    }
}
