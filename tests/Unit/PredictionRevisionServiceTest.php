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

    public function test_btts_with_real_bookmaker_odds_can_be_published(): void
    {
        // Identifiant de match dont la rotation désigne BTTS.
        $matchId = collect(range(1, 200))->map(fn (int $i) => 'match-' . $i)
            ->first(fn (string $id) => crc32($id) % 5 === 0);
        $this->assertNotNull($matchId);

        $review = $this->service()->review($this->predictionResult([
            '1N2' => ['home' => 0.55, 'draw' => 0.25, 'away' => 0.20],
            'btts' => ['yes' => 0.58, 'no' => 0.42],
        ]), [
            'match_id' => $matchId,
            'quality_score' => 85,
            'odds_context' => [
                '1N2' => ['home' => 2.00, 'draw' => 3.40, 'away' => 4.00],
                'btts' => ['yes' => 1.75, 'no' => 2.10],
            ],
        ]);

        $this->assertSame('replace', $review['decision']);
        $this->assertSame('btts', $review['final_candidate']['market']);
        $this->assertSame('bookmaker', $review['final_candidate']['odds_source']);
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
        $this->assertSame('keep', $review['decision']);
    }

    public function test_replace_decision_uses_an_existing_market_candidate(): void
    {
        $review = $this->service()->review($this->predictionResult(['1N2' => ['home' => 0.42, 'draw' => 0.30, 'away' => 0.28], 'double_chance' => ['1X' => 0.78, 'X2' => 0.58]]), ['quality_score' => 85]);
        $this->assertSame('replace', $review['decision']);
        $this->assertSame('double_chance', $review['final_candidate']['market']);
    }

    public function test_low_quality_keeps_the_candidate_in_limited_data_mode(): void
    {
        config()->set('services.prediction.allow_limited_data', true);

        $review = $this->service()->review($this->predictionResult(['1N2' => ['home' => 0.66, 'draw' => 0.20, 'away' => 0.14]]), ['quality_score' => 40]);

        $this->assertSame('keep', $review['decision']);
        $this->assertSame('1N2', $review['final_candidate']['market']);
        $this->assertContains('limited_data_mode', $review['risk_factors']);
    }

    public function test_low_quality_is_published_with_low_confidence_even_when_the_old_flag_is_off(): void
    {
        config()->set('services.prediction.allow_limited_data', false);

        $review = $this->service()->review($this->predictionResult(['1N2' => ['home' => 0.66, 'draw' => 0.20, 'away' => 0.14]]), ['quality_score' => 40]);

        $this->assertSame('keep', $review['decision']);
        $this->assertSame('1N2', $review['final_candidate']['market']);
        $this->assertContains('limited_data_mode', $review['risk_factors']);
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

        $this->assertSame('AI_ESTIMATE', $review['prediction_origin']);
        $this->assertSame('btts', $review['final_candidate']['market']);
        $this->assertSame(0.61, $review['final_candidate']['probability']);
    }

    public function test_double_chance_is_not_the_default_published_market(): void
    {
        // Sans 1N2, la double chance est la plus probable : elle ne doit pas
        // rester la sélection publiée si BTTS ou les totaux sont comparables.
        $review = $this->service()->review($this->predictionResult([
            'double_chance' => ['1X' => 0.75, 'X2' => 0.57],
            'btts' => ['yes' => 0.66, 'no' => 0.34],
            'totals' => ['over_2_5' => 0.60, 'under_2_5' => 0.40],
        ]), ['quality_score' => 85]);

        $this->assertSame('replace', $review['decision']);
        $this->assertNotSame('double_chance', $review['final_candidate']['market']);
        $this->assertContains('market_diversification', $review['risk_factors']);
    }

    public function test_double_chance_survives_when_it_is_the_only_reliable_market(): void
    {
        $review = $this->service()->review($this->predictionResult([
            'double_chance' => ['1X' => 0.75, 'X2' => 0.57],
            'btts' => ['yes' => 0.44, 'no' => 0.56],
        ]), ['quality_score' => 85]);

        $this->assertSame('keep', $review['decision']);
        $this->assertSame('double_chance', $review['final_candidate']['market']);
    }
}
