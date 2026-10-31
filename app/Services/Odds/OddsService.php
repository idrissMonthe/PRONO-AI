<?php

namespace App\Services\Odds;

use App\Services\Prediction\PredictionClient;

class OddsService
{
    protected PredictionClient $predictionClient;

    public function __construct(PredictionClient $predictionClient)
    {
        $this->predictionClient = $predictionClient;
    }

    /**
     * Devigs 1N2 or 2-way odds using Shin, Power, or Proportional method (CDC 25.3).
     */
    public function removeMargin(array $decimalOdds, string $method = 'shin'): array
    {
        return $this->predictionClient->devig($decimalOdds, $method);
    }

    /**
     * Computes Expected Value (EV) and robustness according to CDC Section 25.4.
     * EV = p * o - 1.
     * An edge is qualified as "robuste" only if EV remains positive using the lower bound of CI (ci80_low).
     * Otherwise it is "indicatif".
     */
    public function evaluateValue(float $modelProb, float $ci80Low, float $marketOdds): array
    {
        $evCentral = ($modelProb * $marketOdds) - 1.0;
        $evLowerBound = ($ci80Low * $marketOdds) - 1.0;

        $isPositiveEdge = $evCentral > 0;
        $isRobust = $isPositiveEdge && ($evLowerBound > 0);

        return [
            'model_prob' => round($modelProb, 4),
            'market_odds' => round($marketOdds, 3),
            'fair_odds' => $modelProb > 0 ? round(1.0 / $modelProb, 3) : 999.0,
            'ev_pct' => round($evCentral * 100, 2),
            'ev_lower_bound_pct' => round($evLowerBound * 100, 2),
            'classification' => $isRobust ? 'robuste' : ($isPositiveEdge ? 'indicatif' : 'defavorable'),
            'has_value' => $isPositiveEdge,
        ];
    }

    /**
     * Closing Line Value (CLV) calculation (CDC Section 25.5 & 29.1):
     * CLV = ln(cote_prise / cote_cloture_juste)
     */
    public function calculateClv(float $placedOdds, float $closingFairOdds): float
    {
        if ($closingFairOdds <= 1.0 || $placedOdds <= 1.0) {
            return 0.0;
        }
        return round(log($placedOdds / $closingFairOdds), 4);
    }

    /**
     * Educational Fractional Kelly Criterion (CDC Section 25.8 & 29.1)
     * f* = (p * o - 1) / (o - 1)
     * Max fraction 0.25, max 2.0% bankroll.
     */
    public function calculateKellyStake(
        float $prob,
        float $decimalOdds,
        float $bankroll = 1000.0,
        float $fraction = 0.25,
        float $maxCapPct = 0.02
    ): array {
        if ($decimalOdds <= 1.0 || $prob <= 0.0 || $prob > 1.0 || $bankroll <= 0.0) {
            return ['fraction_recommended' => 0.0, 'virtual_stake' => 0.0];
        }

        $fraction = min(0.25, max(0.0, $fraction));
        $maxCapPct = min(0.02, max(0.0, $maxCapPct));

        $b = $decimalOdds - 1.0;
        $q = 1.0 - $prob;
        $fStar = ($b * $prob - $q) / $b;

        if ($fStar <= 0) {
            return ['fraction_recommended' => 0.0, 'virtual_stake' => 0.0];
        }

        $adjustedF = min($maxCapPct, $fStar * $fraction);
        $stakeUnits = round($bankroll * $adjustedF, 2);

        return [
            'f_star_raw' => round($fStar, 4),
            'fraction_applied' => $fraction,
            'bankroll_pct' => round($adjustedF * 100, 2),
            'virtual_stake' => $stakeUnits,
        ];
    }
}

