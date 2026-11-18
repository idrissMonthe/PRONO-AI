<?php

namespace Tests\Unit;

use App\Services\AIExplanation\GeminiExplanationService;
use App\Services\Prediction\MarketNormalizationService;
use App\Services\Prediction\PredictionRevisionService;
use PHPUnit\Framework\TestCase;

class MarketNormalizationTest extends TestCase
{
    public function test_normalizes_python_market_ids_to_canonical_laravel_names(): void
    {
        $this->assertSame('1N2', MarketNormalizationService::normalizeMarketId('1x2'));
        $this->assertSame('totals', MarketNormalizationService::normalizeMarketId('total_goals'));
        $this->assertSame('dnb', MarketNormalizationService::normalizeMarketId('draw_no_bet'));
        $this->assertSame('team_totals', MarketNormalizationService::normalizeMarketId('team_total_goals'));
        $this->assertSame('half_time_result', MarketNormalizationService::normalizeMarketId('ht_1x2'));
        $this->assertSame('half_time_totals', MarketNormalizationService::normalizeMarketId('ht_total_goals'));
        $this->assertSame('corners_total', MarketNormalizationService::normalizeMarketId('total_corners'));
        $this->assertSame('cards_total', MarketNormalizationService::normalizeMarketId('total_cards'));
    }

    public function test_identifies_cards_and_discipline_markets_strictly(): void
    {
        $this->assertTrue(MarketNormalizationService::isCardsMarket('total_yellow_cards', 'cards'));
        $this->assertTrue(MarketNormalizationService::isCardsMarket('total_cards', 'discipline'));
        $this->assertTrue(MarketNormalizationService::isCardsMarket('team_total_cards', 'cards'));
        $this->assertTrue(MarketNormalizationService::isCardsMarket(null, 'yellow_cards'));
        $this->assertTrue(MarketNormalizationService::isCardsMarket('cards_handicap'));

        $this->assertFalse(MarketNormalizationService::isCardsMarket('1N2', 'result'));
        $this->assertFalse(MarketNormalizationService::isCardsMarket('totals', 'goals'));
        $this->assertFalse(MarketNormalizationService::isCardsMarket('btts', 'goals'));
        $this->assertFalse(MarketNormalizationService::isCardsMarket('corners_total', 'corners'));
    }

    public function test_formats_user_friendly_selection_labels(): void
    {
        $label1 = MarketNormalizationService::getSelectionLabel('1N2', 'home', 'Paris SG', 'Marseille');
        $this->assertSame('Victoire Paris SG', $label1);

        $label2 = MarketNormalizationService::getSelectionLabel('totals', 'over_2_5');
        $this->assertSame('Plus de 2,5 buts', $label2);

        $label3 = MarketNormalizationService::getSelectionLabel('dnb', 'away', 'Paris SG', 'Marseille');
        $this->assertSame('Victoire Marseille (Remboursé si nul)', $label3);

        $label4 = MarketNormalizationService::getSelectionLabel('btts', 'yes');
        $this->assertSame('Les deux équipes marquent', $label4);
    }

    public function test_candidates_from_result_excludes_none_coverage_and_insufficient_status(): void
    {
        $geminiMock = $this->createMock(GeminiExplanationService::class);
        $service = new PredictionRevisionService($geminiMock);

        $pythonResult = [
            'market_predictions' => [
                [
                    'market_id' => 'player_carded',
                    'selection' => 'player_1',
                    'probability' => 0.65,
                    'coverage' => 'none', // DOIT ÊTRE EXCLU
                    'market_status' => 'supported',
                ],
                [
                    'market_id' => 'total_corners',
                    'selection' => 'over_8_5',
                    'probability' => 0.68,
                    'coverage' => 'full',
                    'market_status' => 'insufficient', // DOIT ÊTRE EXCLU
                ],
                [
                    'market_id' => 'total_goals',
                    'selection' => 'over_2_5',
                    'probability' => 0.62,
                    'coverage' => 'full',
                    'market_status' => 'supported', // DOIT ÊTRE CONSERVÉ
                    'reliability' => 0.72,
                ],
            ],
            'markets' => [
                '1N2' => ['home' => 0.58, 'draw' => 0.24, 'away' => 0.18],
            ],
        ];

        $candidates = $service->candidatesFromResult($pythonResult);

        $keys = array_column($candidates, 'candidate_key');
        $this->assertNotContains('player_carded:player_1', $keys);
        $this->assertNotContains('total_corners:over_8_5', $keys);
        $this->assertContains('total_goals:over_2_5', $keys);
        $this->assertContains('1N2:home', $keys);
    }
}
