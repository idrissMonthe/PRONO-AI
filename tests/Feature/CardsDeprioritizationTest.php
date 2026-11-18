<?php

namespace Tests\Feature;

use App\Services\AIExplanation\GeminiExplanationService;
use App\Services\Prediction\PredictionRevisionService;
use Tests\TestCase;

class CardsDeprioritizationTest extends TestCase
{
    public function test_initial_candidate_prefers_football_markets_over_cards_even_if_cards_has_higher_probability(): void
    {
        $geminiMock = $this->createMock(GeminiExplanationService::class);
        $service = new PredictionRevisionService($geminiMock);

        $pythonResult = [
            'market_predictions' => [
                [
                    'market_id' => 'total_yellow_cards',
                    'selection' => 'over_3_5',
                    'probability' => 0.81, // Forte probabilité
                    'coverage' => 'full',
                    'market_status' => 'supported',
                    'reliability' => 0.70,
                    'family' => 'cards',
                ],
                [
                    'market_id' => 'total_goals',
                    'selection' => 'over_1_5',
                    'probability' => 0.72, // Moins forte mais marché de but clé
                    'coverage' => 'full',
                    'market_status' => 'supported',
                    'reliability' => 0.75,
                    'family' => 'goals',
                ],
            ],
            'markets' => [
                '1N2' => ['home' => 0.65, 'draw' => 0.22, 'away' => 0.13],
            ],
        ];

        $candidates = $service->candidatesFromResult($pythonResult);
        
        // Réflection pour tester la méthode privée initialCandidate
        $reflector = new \ReflectionClass(PredictionRevisionService::class);
        $method = $reflector->getMethod('initialCandidate');
        $method->setAccessible(true);

        $initial = $method->invoke($service, $candidates);

        $this->assertNotNull($initial);
        // Le pronostic officiel ne doit PAS être le marché de cartons
        $this->assertNotSame('total_yellow_cards', $initial['market']);
        $this->assertNotSame('cards', $initial['family']);
    }

    public function test_gemini_alternative_refuses_insufficient_or_trivial_markets(): void
    {
        $geminiMock = $this->createMock(GeminiExplanationService::class);
        $service = new PredictionRevisionService($geminiMock);

        $candidates = [
            [
                'candidate_key' => '1N2:home',
                'market' => '1N2',
                'selection' => 'home',
                'label' => 'Victoire domicile',
                'probability' => 0.60,
                'odds' => 1.55,
                'odds_source' => 'bookmaker',
                'coverage' => 'full',
                'market_status' => 'supported',
                'family' => 'result',
            ],
            [
                'candidate_key' => 'player_carded:player_x',
                'market' => 'player_carded',
                'selection' => 'player_x',
                'label' => 'Carton joueur',
                'probability' => 0.45,
                'odds' => 2.10,
                'odds_source' => 'bookmaker',
                'coverage' => 'full',
                'market_status' => 'insufficient', // Insuffisant
                'family' => 'cards',
            ],
        ];

        $python = $candidates[0];

        $reflector = new \ReflectionClass(PredictionRevisionService::class);
        $method = $reflector->getMethod('resolveAlternative');
        $method->setAccessible(true);

        // Proposition de marché avec statut insuffisant
        $proposalInsufficient = [
            'candidate_key' => 'player_carded:player_x',
            'market' => 'player_carded',
            'selection' => 'player_x',
            'reasoning' => 'Je propose ce carton',
        ];

        $resolved = $method->invoke($service, $proposalInsufficient, $candidates, $python);
        $this->assertNull($resolved, "Une alternative portant sur un marché 'insufficient' doit être rejetée.");
    }
}
