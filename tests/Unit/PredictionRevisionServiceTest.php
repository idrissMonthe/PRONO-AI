<?php

namespace Tests\Unit;

use App\Services\Prediction\PredictionRevisionService;
use App\Services\AIExplanation\GeminiExplanationService;
use Tests\TestCase;

class PredictionRevisionServiceTest extends TestCase
{
    private function service(): PredictionRevisionService
    {
        $gemini = $this->createMock(GeminiExplanationService::class);
        $gemini->method('reviewPrediction')->willReturn(['fallback' => true]);
        $gemini->method('analyzeSparseMatch')->willReturn(['decision' => 'no_prediction']);
        return new PredictionRevisionService($gemini);
    }

    private function predictionResult(array $markets): array
    {
        return ['markets' => $markets, 'top_scores' => []];
    }

    public function test_btts_with_real_bookmaker_odds_stays_available_without_replacing_python(): void
    {
        $review = $this->service()->review($this->predictionResult([
            '1N2' => ['home' => 0.55, 'draw' => 0.25, 'away' => 0.20],
            'btts' => ['yes' => 0.58, 'no' => 0.42],
        ]), [
            'match_id' => 'match-1',
            'quality_score' => 85,
            'odds_context' => [
                '1N2' => ['home' => 2.00, 'draw' => 3.40, 'away' => 4.00],
                'btts' => ['yes' => 1.75, 'no' => 2.10],
            ],
        ]);

        $this->assertSame(PredictionRevisionService::STATUS_FAILED, $review['gemini_status']);
        $this->assertSame('1N2', $review['python_candidate']['market']);
        $this->assertSame('1N2', $review['final_candidate']['market']);
        $btts = collect($review['candidates'])->firstWhere('market', 'btts');
        $this->assertSame('bookmaker', $btts['odds_source']);
        $this->assertNull($review['alternative']);
    }

    public function test_asian_handicap_candidate_requires_a_bookmaker_price(): void
    {
        $candidates = $this->service()->candidatesFromResult([
            'markets' => [
                '1N2' => ['home' => 0.55, 'draw' => 0.25, 'away' => 0.20],
                'asian_handicap' => [
                    'm0_5' => ['home' => ['win' => 0.62, 'half_win' => 0.06], 'away' => ['win' => 0.32, 'half_win' => 0.06]],
                ],
            ],
        ], [
            'home_team' => 'Équipe A',
            'away_team' => 'Équipe B',
            'handicap' => [['selection' => 'HOME', 'line' => -0.5, 'odds' => 1.88]],
        ]);

        $handicap = collect($candidates)->firstWhere('market', 'asian_handicap');
        $this->assertNotNull($handicap);
        $this->assertSame('AH_HOME_-0.5', $handicap['selection']);
        $this->assertSame('bookmaker', $handicap['odds_source']);
        $this->assertSame(1.88, $handicap['odds']);
    }

    public function test_market_disagreement_discards_the_candidate(): void
    {
        $candidates = $this->service()->candidatesFromResult([
            'markets' => [
                '1N2' => ['home' => 0.55, 'draw' => 0.25, 'away' => 0.20],
                'asian_handicap' => [
                    '1_5' => ['home' => ['win' => 0.90, 'half_win' => 0.02], 'away' => ['win' => 0.08, 'half_win' => 0.02]],
                ],
            ],
        ], [
            'home_team' => 'Équipe A',
            'away_team' => 'Équipe B',
            'handicap' => [['selection' => 'HOME', 'line' => 1.5, 'odds' => 2.04]],
        ]);

        // 91 % contre une cote qui n'implique que 49 % : trop d'écart.
        $this->assertNull(collect($candidates)->firstWhere('market', 'asian_handicap'));
    }

    public function test_keep_decision(): void
    {
        $review = $this->service()->review($this->predictionResult(['1N2' => ['home' => 0.66, 'draw' => 0.20, 'away' => 0.14], 'btts' => ['yes' => 0.55]]), ['quality_score' => 85]);
        $this->assertSame(PredictionRevisionService::STATUS_FAILED, $review['gemini_status']);
        $this->assertSame('1N2', $review['python_candidate']['market']);
    }

    public function test_unavailable_review_does_not_replace_the_python_pick(): void
    {
        $review = $this->service()->review($this->predictionResult(['1N2' => ['home' => 0.42, 'draw' => 0.30, 'away' => 0.28], 'double_chance' => ['1X' => 0.78, 'X2' => 0.58]]), ['quality_score' => 85]);
        $this->assertSame(PredictionRevisionService::STATUS_FAILED, $review['gemini_status']);
        $this->assertSame('1N2', $review['final_candidate']['market']);
        $this->assertSame('home', $review['final_candidate']['selection']);
    }

    public function test_low_quality_keeps_the_candidate_in_limited_data_mode(): void
    {
        config()->set('services.prediction.allow_limited_data', true);

        $review = $this->service()->review($this->predictionResult(['1N2' => ['home' => 0.66, 'draw' => 0.20, 'away' => 0.14]]), ['quality_score' => 40]);

        $this->assertSame(PredictionRevisionService::STATUS_FAILED, $review['gemini_status']);
        $this->assertSame('1N2', $review['final_candidate']['market']);
        $this->assertContains('limited_data_mode', $review['risk_factors']);
        $this->assertFalse($review['parlay_eligible']);
    }

    public function test_low_quality_is_published_with_low_confidence_even_when_the_old_flag_is_off(): void
    {
        config()->set('services.prediction.allow_limited_data', false);

        $review = $this->service()->review($this->predictionResult(['1N2' => ['home' => 0.66, 'draw' => 0.20, 'away' => 0.14]]), ['quality_score' => 40]);

        $this->assertSame(PredictionRevisionService::STATUS_FAILED, $review['gemini_status']);
        $this->assertSame('1N2', $review['final_candidate']['market']);
        $this->assertContains('limited_data_mode', $review['risk_factors']);
    }

    public function test_insufficient_data_decision_when_no_market_is_calculable(): void
    {
        $review = $this->service()->review($this->predictionResult([]), ['quality_score' => 90]);
        $this->assertSame(PredictionRevisionService::STATUS_INSUFFICIENT, $review['gemini_status']);
        $this->assertNull($review['python_candidate']);
        $this->assertFalse($review['parlay_eligible']);
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
        $this->assertSame(PredictionRevisionService::STATUS_WITH_ALTERNATIVE, $review['gemini_status']);
        $this->assertSame('1N2', $review['python_candidate']['market']);
        $this->assertSame(0.42, $review['python_candidate']['probability']);
        $this->assertSame('double_chance', $review['alternative']['market']);
        $this->assertSame(0.78, $review['alternative']['probability']);
        $this->assertSame('PYTHON', $review['alternative']['origin']);
        $this->assertSame('google_gemini', $review['provider']);
        $this->assertFalse($review['parlay_eligible']);
    }

    public function test_ai_can_take_over_a_python_abstention_with_explicit_origin(): void
    {
        $gemini = $this->createMock(GeminiExplanationService::class);
        $gemini->method('analyzeSparseMatch')->willReturn([
            'decision' => 'propose',
            'candidate' => [
                'candidate_key' => 'btts:yes',
                'market' => 'btts',
                'selection' => 'yes',
                'label' => 'Les deux équipes marquent',
                'line' => null,
                'probability' => 0.61,
                'origin' => 'AI_ESTIMATE',
            ],
            'reasoning' => 'Les informations disponibles permettent une estimation prudente.',
            'supporting_factors' => ['recent_form_available'],
            'risk_factors' => ['python_history_limited'],
            'missing_data' => ['complete_team_history'],
            'confidence' => 'low',
            'provider' => 'google_gemini',
            'model' => 'test-model',
            'fallback' => false,
        ]);
        $review = (new PredictionRevisionService($gemini))->review([
            'abstain' => true,
            'markets' => ['1N2' => ['home' => 0.45, 'draw' => 0.28, 'away' => 0.27]],
            'top_scores' => [],
        ], ['quality_score' => 45, 'missing_data' => ['complete_team_history']]);

        $this->assertSame('PYTHON_ESTIMATE', $review['prediction_origin']);
        $this->assertSame('1N2', $review['python_candidate']['market']);
        $this->assertSame('1N2', $review['final_candidate']['market']);
        $this->assertSame('AI_ESTIMATE', $review['alternative']['origin']);
        $this->assertNull($review['alternative']['probability']);
        $this->assertSame(0.61, $review['alternative']['ai_probability']);
        $this->assertSame(PredictionRevisionService::STATUS_WITH_ALTERNATIVE, $review['gemini_status']);
    }

    public function test_double_chance_is_not_the_default_published_market(): void
    {
        // Sans 1N2, la double chance est le pronostic Python. L'absence de
        // réponse Gemini ne la remplace pas par un autre marché.
        $review = $this->service()->review($this->predictionResult([
            'double_chance' => ['1X' => 0.75, 'X2' => 0.57],
            'btts' => ['yes' => 0.66, 'no' => 0.34],
            'totals' => ['over_2_5' => 0.60, 'under_2_5' => 0.40],
        ]), ['quality_score' => 85]);

        $this->assertSame(PredictionRevisionService::STATUS_FAILED, $review['gemini_status']);
        $this->assertSame('double_chance', $review['python_candidate']['market']);
        $this->assertSame('double_chance', $review['final_candidate']['market']);
        $this->assertNull($review['alternative']);
    }

    public function test_double_chance_survives_when_it_is_the_only_reliable_market(): void
    {
        $review = $this->service()->review($this->predictionResult([
            'double_chance' => ['1X' => 0.75, 'X2' => 0.57],
            'btts' => ['yes' => 0.44, 'no' => 0.56],
        ]), ['quality_score' => 85]);

        $this->assertSame(PredictionRevisionService::STATUS_FAILED, $review['gemini_status']);
        $this->assertSame('double_chance', $review['final_candidate']['market']);
    }

    public function test_python_pick_validated_by_gemini_keeps_its_probability(): void
    {
        $review = $this->reviewed([
            'fallback' => false,
            'decision' => 'validated',
            'reasoning' => 'Le pronostic domicile est cohérent avec les buts attendus.',
            'provider' => 'google_gemini',
            'model' => 'test-model',
        ], ['1N2' => ['home' => 0.66, 'draw' => 0.20, 'away' => 0.14]]);

        $this->assertSame(PredictionRevisionService::STATUS_VALIDATED, $review['gemini_status']);
        $this->assertSame(0.66, $review['python_candidate']['probability']);
        $this->assertSame('PYTHON', $review['python_candidate']['origin']);
        $this->assertNull($review['alternative']);
        $this->assertTrue($review['parlay_eligible']);
        $this->assertTrue($this->serviceWith($this->geminiReturning([
            'fallback' => false,
            'decision' => 'validated',
            'provider' => 'google_gemini',
        ]))->pythonPickEligibleForParlay(new \App\Models\Prediction([
            'review_decision' => PredictionRevisionService::STATUS_VALIDATED,
        ])));
    }

    public function test_gemini_can_refuse_without_an_alternative(): void
    {
        $review = $this->reviewed([
            'fallback' => false,
            'decision' => 'not_validated',
            'candidate_key' => null,
            'reasoning' => 'La marge entre les issues est trop faible.',
            'provider' => 'google_gemini',
        ], ['1N2' => ['home' => 0.40, 'draw' => 0.32, 'away' => 0.28]]);

        $this->assertSame(PredictionRevisionService::STATUS_WITHOUT_ALTERNATIVE, $review['gemini_status']);
        $this->assertSame('home', $review['python_candidate']['selection']);
        $this->assertNull($review['alternative']);
        $this->assertFalse($review['parlay_eligible']);
    }

    public function test_insufficient_information_is_not_a_validation(): void
    {
        $review = $this->reviewed([
            'fallback' => false,
            'decision' => 'insufficient_information',
            'reasoning' => 'Les absences ne sont pas confirmées.',
            'provider' => 'google_gemini',
        ], ['1N2' => ['home' => 0.58, 'draw' => 0.24, 'away' => 0.18]]);

        $this->assertSame(PredictionRevisionService::STATUS_INSUFFICIENT, $review['gemini_status']);
        $this->assertSame('1N2', $review['python_candidate']['market']);
        $this->assertNull($review['alternative']);
        $this->assertFalse($review['parlay_eligible']);
        $this->assertStringContainsString('pas pu être effectuée', $review['reasoning']);
    }

    public function test_gemini_api_failure_is_not_a_validation(): void
    {
        $review = $this->reviewed([
            'fallback' => true,
            'decision' => 'keep',
            'provider' => 'deterministic_fallback',
            'risk_factors' => ['ai_review_unavailable'],
        ], ['1N2' => ['home' => 0.61, 'draw' => 0.22, 'away' => 0.17]]);

        $this->assertSame(PredictionRevisionService::STATUS_FAILED, $review['gemini_status']);
        $this->assertSame(0.61, $review['python_candidate']['probability']);
        $this->assertFalse($review['parlay_eligible']);
        $this->assertNotSame(PredictionRevisionService::STATUS_VALIDATED, $review['gemini_status']);
    }

    public function test_alternative_uses_the_python_probability_instead_of_an_invented_one(): void
    {
        $review = $this->reviewed([
            'fallback' => false,
            'decision' => 'not_validated',
            'candidate_key' => 'btts:yes',
            'alternative_probability' => 0.99,
            'reasoning' => 'Les deux équipes ont des buts attendus proches.',
            'provider' => 'google_gemini',
        ], [
            '1N2' => ['home' => 0.48, 'draw' => 0.28, 'away' => 0.24],
            'btts' => ['yes' => 0.57, 'no' => 0.43],
        ]);

        $this->assertSame(PredictionRevisionService::STATUS_WITH_ALTERNATIVE, $review['gemini_status']);
        $this->assertSame(0.48, $review['python_candidate']['probability']);
        $this->assertSame(0.57, $review['alternative']['probability']);
        $this->assertSame('PYTHON', $review['alternative']['origin']);
        $this->assertNotSame(0.99, $review['alternative']['probability']);
    }

    public function test_alternative_python_cannot_price_stays_an_ai_estimate(): void
    {
        $review = $this->reviewed([
            'fallback' => false,
            'decision' => 'not_validated',
            'alternative_market' => 'btts',
            'alternative_selection' => 'yes',
            'alternative_probability' => 0.61,
            'reasoning' => 'Estimation prudente, hors marché calculé.',
            'provider' => 'google_gemini',
        ], ['1N2' => ['home' => 0.52, 'draw' => 0.26, 'away' => 0.22]]);

        $this->assertSame('AI_ESTIMATE', $review['alternative']['origin']);
        $this->assertNull($review['alternative']['probability']);
        $this->assertSame(0.61, $review['alternative']['ai_probability']);
        $this->assertSame('1N2', $review['final_candidate']['market']);
        $this->assertFalse($review['alternative']['technically_valid']);
    }

    public function test_python_pick_stays_visible_for_every_gemini_outcome(): void
    {
        $markets = ['1N2' => ['home' => 0.57, 'draw' => 0.24, 'away' => 0.19]];
        $responses = [
            ['fallback' => false, 'decision' => 'validated', 'provider' => 'google_gemini'],
            ['fallback' => false, 'decision' => 'not_validated', 'candidate_key' => 'btts:yes', 'provider' => 'google_gemini'],
            ['fallback' => false, 'decision' => 'not_validated', 'provider' => 'google_gemini'],
            ['fallback' => false, 'decision' => 'insufficient_information', 'provider' => 'google_gemini'],
            ['fallback' => true, 'decision' => 'review_failed', 'provider' => 'deterministic_fallback'],
        ];

        foreach ($responses as $response) {
            $review = $this->reviewed($response, $markets + ['btts' => ['yes' => 0.56, 'no' => 0.44]]);
            $this->assertSame('home', $review['python_candidate']['selection']);
            $this->assertSame('home', $review['final_candidate']['selection']);
            $this->assertSame(0.57, $review['python_candidate']['probability']);
        }
    }

    public function test_parlays_follow_the_validation_status(): void
    {
        $service = $this->service();
        $validated = new \App\Models\Prediction(['review_decision' => PredictionRevisionService::STATUS_VALIDATED]);
        $refused = new \App\Models\Prediction([
            'review_decision' => PredictionRevisionService::STATUS_WITHOUT_ALTERNATIVE,
            'published_market' => '1N2',
            'published_selection' => 'home',
        ]);
        $withAlternative = new \App\Models\Prediction([
            'review_decision' => PredictionRevisionService::STATUS_WITH_ALTERNATIVE,
            'market_metrics_json' => [
                'gemini_review' => [
                    'alternative' => [
                        'market' => 'btts',
                        'selection' => 'yes',
                        'label' => 'Les deux équipes marquent',
                        'origin' => 'PYTHON',
                        'probability' => 0.57,
                        'technically_valid' => true,
                        'market_recognized' => true,
                    ],
                ],
            ],
        ]);
        $aiAlternative = new \App\Models\Prediction([
            'review_decision' => PredictionRevisionService::STATUS_WITH_ALTERNATIVE,
            'market_metrics_json' => [
                'gemini_review' => [
                    'alternative' => [
                        'market' => 'btts',
                        'selection' => 'yes',
                        'origin' => 'AI_ESTIMATE',
                        'probability' => null,
                        'ai_probability' => 0.61,
                        'technically_valid' => false,
                        'market_recognized' => true,
                    ],
                ],
            ],
        ]);

        $this->assertTrue($service->pythonPickEligibleForParlay($validated));
        $this->assertFalse($service->pythonPickEligibleForParlay($refused));
        $this->assertFalse($service->pythonPickEligibleForParlay(new \App\Models\Prediction([
            'review_decision' => PredictionRevisionService::STATUS_FAILED,
        ])));
        $this->assertNull($service->alternativeForParlay($withAlternative));

        config()->set('services.hybrid_prediction.allow_gemini_alternative_in_parlays', true);
        $this->assertSame('btts', $service->alternativeForParlay($withAlternative)['market']);

        config()->set('services.hybrid_prediction.allow_ai_in_parlays', false);
        $this->assertNull($service->alternativeForParlay($aiAlternative));
        config()->set('services.hybrid_prediction.allow_ai_in_parlays', true);
        $this->assertSame('AI_ESTIMATE', $service->alternativeForParlay($aiAlternative)['origin']);
    }

    public function test_history_payload_keeps_python_decision_and_alternative_apart(): void
    {
        $first = $this->reviewed([
            'fallback' => false,
            'decision' => 'not_validated',
            'candidate_key' => 'btts:yes',
            'reasoning' => 'Le marché des deux équipes est plus cohérent.',
            'provider' => 'google_gemini',
        ], [
            '1N2' => ['home' => 0.46, 'draw' => 0.29, 'away' => 0.25],
            'btts' => ['yes' => 0.63, 'no' => 0.37],
        ]);
        $stored = $this->serviceWith($this->geminiReturning([
            'fallback' => false,
            'decision' => 'validated',
            'provider' => 'google_gemini',
        ]))->storedReview($first);

        $second = $this->reviewed([
            'fallback' => false,
            'decision' => 'validated',
            'reasoning' => 'Cohérent.',
            'provider' => 'google_gemini',
        ], ['1N2' => ['home' => 0.70, 'draw' => 0.18, 'away' => 0.12]]);

        $this->assertSame(PredictionRevisionService::STATUS_WITH_ALTERNATIVE, $stored['status']);
        $this->assertSame('1N2', $stored['python']['market']);
        $this->assertSame('btts', $stored['alternative']['market']);
        $this->assertSame('PYTHON', $stored['alternative']['origin']);
        $this->assertSame('1N2', $stored['published_selection']['market']);
        $this->assertSame(PredictionRevisionService::STATUS_VALIDATED, $second['gemini_status']);
        $this->assertSame(PredictionRevisionService::STATUS_WITH_ALTERNATIVE, $first['gemini_status']);
    }

    private function reviewed(array $response, array $markets): array
    {
        return $this->serviceWith($this->geminiReturning($response))->review($this->predictionResult($markets), ['quality_score' => 85]);
    }

    private function geminiReturning(array $response): GeminiExplanationService
    {
        $gemini = $this->createMock(GeminiExplanationService::class);
        $gemini->method('reviewPrediction')->willReturn($response);
        $gemini->method('analyzeSparseMatch')->willReturn(['decision' => 'no_prediction']);

        return $gemini;
    }

    private function serviceWith(GeminiExplanationService $gemini): PredictionRevisionService
    {
        return new PredictionRevisionService($gemini);
    }
}
