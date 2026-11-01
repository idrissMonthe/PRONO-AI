<?php

namespace App\Services\Prediction;

use App\Models\FootballMatch;
use App\Models\Prediction;
use App\Models\PredictionEvaluation;
use Illuminate\Support\Collection;

class PredictionSettlementService
{
    public function settleFinishedPredictions(): int
    {
        $count = 0;

        FootballMatch::with(['homeTeam', 'awayTeam', 'officialPrediction'])
            ->where('status', 'FINISHED')
            ->whereNotNull('home_score')
            ->whereNotNull('away_score')
            ->whereHas('officialPrediction', fn ($query) => $query->where('is_abstained', false))
            ->orderBy('id')
            ->chunkById(100, function (Collection $matches) use (&$count): void {
                foreach ($matches as $match) {
                    $count += $this->settleMatch($match);
                }
            });

        return $count;
    }

    private function settleMatch(FootballMatch $match): int
    {
        $prediction = $match->officialPrediction;
        if (! $prediction) {
            return 0;
        }

        $home = $match->homeTeam->short_name;
        $away = $match->awayTeam->short_name;
        $homeGoals = (int) $match->home_score;
        $awayGoals = (int) $match->away_score;
        $totalGoals = $homeGoals + $awayGoals;
        $markets = $prediction->derived_markets_json ?? [];
        $rows = [];

        $actualResult = $homeGoals === $awayGoals ? 'draw' : ($homeGoals > $awayGoals ? 'home' : 'away');
        $resultLabels = ['home' => 'Victoire '.$home, 'draw' => 'Match nul', 'away' => 'Victoire '.$away];
        $oneN2 = $markets['1N2'] ?? [
            'home' => (float) $prediction->prob_home,
            'draw' => (float) $prediction->prob_draw,
            'away' => (float) $prediction->prob_away,
        ];
        $this->addSelected($rows, $prediction, $match, '1N2', 'Résultat', $oneN2, $resultLabels, $actualResult);

        $totals = $markets['totals'] ?? [];
        foreach ($totals as $key => $probability) {
            if (! is_numeric($probability) || ! preg_match('/^(over|under)_(\d+)_(\d+)$/', (string) $key, $parts)) {
                continue;
            }
            $threshold = ((int) $parts[2]) . ',' . $parts[3];
            $actual = $totalGoals > ((int) $parts[2] . '.' . $parts[3]) ? 'over' : 'under';
            $labels = ['over' => 'Plus de '.$threshold.' but', 'under' => 'Moins de '.$threshold.' buts'];
            $this->addSelected($rows, $prediction, $match, $key, 'Total buts', [$parts[1] => (float) $probability], $labels, $actual, true);
        }

        $btts = $markets['btts'] ?? [];
        if (isset($btts['yes'], $btts['no'])) {
            $this->addSelected($rows, $prediction, $match, 'btts', 'BTTS', $btts, [
                'yes' => 'Les deux équipes marquent',
                'no' => 'Au moins une équipe ne marque pas',
            ], ($homeGoals > 0 && $awayGoals > 0) ? 'yes' : 'no');
        }

        $combos = $markets['combos'] ?? [];
        foreach ($combos as $key => $probability) {
            if (! is_numeric($probability)) {
                continue;
            }
            $actual = $this->comboHit((string) $key, $homeGoals, $awayGoals) ? 'hit' : 'miss';
            $this->addEvaluation($rows, $prediction, $match, 'Combiné', (string) $key, (float) $probability, (string) $key, $actual, $actual);
        }

        foreach ($rows as $row) {
            PredictionEvaluation::updateOrCreate(
                ['prediction_id' => $prediction->id, 'market_key' => $row['market_key']],
                $row
            );
        }

        return count($rows);
    }

    private function addSelected(array &$rows, Prediction $prediction, FootballMatch $match, string $key, string $group, array $probabilities, array $labels, string $actual, bool $single = false): void
    {
        $probabilities = collect($probabilities)->filter(fn ($value) => is_numeric($value));
        if ($probabilities->isEmpty()) {
            return;
        }
        $selectedKey = $probabilities->sortDesc()->keys()->first();
        $predictedLabel = $labels[$selectedKey] ?? $selectedKey;
        $actualLabel = $labels[$actual] ?? $actual;
        $status = $selectedKey === $actual ? 'WON' : 'LOST';
        $this->addEvaluation($rows, $prediction, $match, $group, $key, (float) $probabilities[$selectedKey], $predictedLabel, $actualLabel, $status);
    }

    private function addEvaluation(array &$rows, Prediction $prediction, FootballMatch $match, string $group, string $key, float $probability, string $predictedLabel, string $actualLabel, string $status): void
    {
        $rows[] = [
            'match_id' => $match->id,
            'market_group' => $group,
            'market_key' => $key,
            'predicted_label' => $predictedLabel,
            'predicted_probability' => round($probability, 4),
            'actual_label' => $actualLabel,
            'status' => $status,
            'evaluated_at_utc' => now()->utc(),
        ];
    }

    private function comboHit(string $key, int $homeGoals, int $awayGoals): bool
    {
        $homeWin = $homeGoals > $awayGoals;
        $awayWin = $awayGoals > $homeGoals;
        $btts = $homeGoals > 0 && $awayGoals > 0;
        if ($key === 'home_win_btts_yes') return $homeWin && $btts;
        if ($key === 'away_win_btts_yes') return $awayWin && $btts;
        if (! preg_match('/^(home|away)_win_(over|under)_(\d+)_(\d+)$/', $key, $parts)) return false;
        $win = $parts[1] === 'home' ? $homeWin : $awayWin;
        $threshold = (float) ($parts[3].'.'.$parts[4]);
        $totalCondition = $parts[2] === 'over' ? ($homeGoals + $awayGoals > $threshold) : ($homeGoals + $awayGoals <= $threshold);
        return $win && $totalCondition;
    }
}
