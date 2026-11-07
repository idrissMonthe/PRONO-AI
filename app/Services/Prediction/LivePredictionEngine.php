<?php

namespace App\Services\Prediction;

use App\Models\FootballMatch;
use Carbon\Carbon;

/**
 * Projection in-play séparée du modèle pré-match.
 * Le score observé devient une donnée de vraisemblance et non un simple
 * affichage : le rythme observé corrige le prior pré-match pour les buts restants.
 */
class LivePredictionEngine
{
    public function project(FootballMatch $match, Carbon $now): array
    {
        $minutes = max(1, min(90, (int) floor(($now->timestamp - $match->match_date_utc->timestamp) / 60)));
        $elapsedFraction = max(1 / 90, min(1.0, $minutes / 90));
        $remainingFraction = max(0.0, (90 - $minutes) / 90);
        $priorWeight = 4.0;

        $homePrior = max(0.05, (float) ($match->officialPrediction?->home_lambda ?? 1.35));
        $awayPrior = max(0.05, (float) ($match->officialPrediction?->away_lambda ?? 1.10));
        $homeObservedRate = (int) $match->home_score / $elapsedFraction;
        $awayObservedRate = (int) $match->away_score / $elapsedFraction;

        $homeRate = (($homePrior * $priorWeight) + $homeObservedRate) / ($priorWeight + 1.0);
        $awayRate = (($awayPrior * $priorWeight) + $awayObservedRate) / ($priorWeight + 1.0);

        $redCardHome = (int) $match->stats->where('team_id', $match->home_team_id)->sum('red_cards');
        $redCardAway = (int) $match->stats->where('team_id', $match->away_team_id)->sum('red_cards');
        if ($redCardHome > 0) $homeRate *= 0.78;
        if ($redCardAway > 0) $awayRate *= 0.78;
        if ($redCardHome > 0) $awayRate *= 1.18;
        if ($redCardAway > 0) $homeRate *= 1.18;

        $remainingHome = max(0.0, min(4.0, $homeRate * $remainingFraction));
        $remainingAway = max(0.0, min(4.0, $awayRate * $remainingFraction));

        return [
            'minute' => $minutes,
            'remaining_fraction' => $remainingFraction,
            'remaining_home_lambda' => $remainingHome,
            'remaining_away_lambda' => $remainingAway,
            'remaining_total_lambda' => $remainingHome + $remainingAway,
            'confidence' => $minutes >= 15 && $minutes <= 80 ? 'medium' : 'low',
            'warnings' => array_values(array_filter([
                $redCardHome > 0 || $redCardAway > 0 ? 'red_card_adjustment_applied' : null,
                $minutes < 15 ? 'early_live_sample' : null,
            ])),
        ];
    }

    public function probabilityAtLeast(float $lambda, int $minimumGoals): float
    {
        $below = 0.0;
        $term = exp(-$lambda);
        for ($goals = 0; $goals < $minimumGoals; $goals++) {
            if ($goals > 0) $term *= $lambda / $goals;
            $below += $term;
        }
        return min(1.0, max(0.0, 1.0 - $below));
    }
}
