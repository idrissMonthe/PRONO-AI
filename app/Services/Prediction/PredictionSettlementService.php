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
            ->whereHas('officialPrediction', fn ($query) => $query->where('is_official', true))
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
        if ($prediction->published_market && $prediction->published_selection && $prediction->published_probability !== null) {
            $published = $this->publishedOutcome($prediction, $homeGoals, $awayGoals, $actualResult, $home, $away);
            if ($published) {
                $this->addEvaluation($rows, $prediction, $match, 'FINAL', 'published', (float) $prediction->published_probability, $published['label'], $published['actual_label'], $published['hit'] ? 'WON' : 'LOST');
            }
        }
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
            $this->addEvaluation($rows, $prediction, $match, 'Combiné', (string) $key, (float) $probability, (string) $key, $actual, $actual === 'hit' ? 'WON' : 'LOST');
        }

        $teamTotals = $markets['team_totals'] ?? [];
        foreach (['home' => $homeGoals, 'away' => $awayGoals] as $side => $goals) {
            foreach ($teamTotals[$side] ?? [] as $key => $probability) {
                if (! is_numeric($probability) || ! preg_match('/^(over|under)_(\d+)_(\d+)$/', (string) $key, $parts)) {
                    continue;
                }

                $threshold = (float) ($parts[2].'.'.$parts[3]);
                $actual = $parts[1] === 'over' ? $goals > $threshold : $goals <= $threshold;
                $sideName = $side === 'home' ? $home : $away;
                $label = $sideName.' '.($parts[1] === 'over' ? 'plus de ' : 'moins de ').$parts[2].','.$parts[3].' but';
                $this->addBinaryEvaluation(
                    $rows,
                    $match,
                    'Buts par équipe',
                    "team_total_{$side}_{$key}",
                    (float) $probability,
                    $label,
                    $actual,
                    $sideName.' respecte le seuil',
                    $sideName.' ne respecte pas le seuil'
                );
            }
        }

        $cleanSheet = $markets['clean_sheet'] ?? [];
        foreach (['home' => $awayGoals, 'away' => $homeGoals] as $side => $opponentGoals) {
            if (! is_numeric($cleanSheet[$side] ?? null)) {
                continue;
            }

            $defendingTeam = $side === 'home' ? $home : $away;
            $opponent = $side === 'home' ? $away : $home;
            $this->addBinaryEvaluation(
                $rows,
                $match,
                'Clean sheet',
                "clean_sheet_{$side}",
                (float) $cleanSheet[$side],
                $opponent.' ne marque pas',
                $opponentGoals === 0,
                $defendingTeam.' garde sa cage inviolée',
                $opponent.' marque'
            );
        }

        $goalMargins = $markets['goal_margin'] ?? [];
        foreach ([1, 2, 3] as $margin) {
            foreach (['home' => $homeGoals - $awayGoals, 'away' => $awayGoals - $homeGoals] as $side => $goalDifference) {
                $key = "{$side}_by_{$margin}_plus";
                if (! is_numeric($goalMargins[$key] ?? null)) {
                    continue;
                }

                $teamName = $side === 'home' ? $home : $away;
                $this->addBinaryEvaluation(
                    $rows,
                    $match,
                    'Marge de buts',
                    $key,
                    (float) $goalMargins[$key],
                    $teamName.' gagne par '.$margin.' but'.($margin > 1 ? 's' : '').' ou plus',
                    $goalDifference >= $margin,
                    $teamName.' atteint la marge',
                    $teamName.' n’atteint pas la marge'
                );
            }
        }

        foreach ($rows as $row) {
            PredictionEvaluation::updateOrCreate(
                ['prediction_id' => $prediction->id, 'market_key' => $row['market_key']],
                $row
            );
        }

        $initial = $prediction->supersedes;
        if ($initial) {
            $baselineRows = [];
            $initialOneN2 = [
                'home' => (float) $initial->prob_home,
                'draw' => (float) $initial->prob_draw,
                'away' => (float) $initial->prob_away,
            ];
            $this->addSelected($baselineRows, $initial, $match, 'INITIAL_1N2', 'PYTHON_INITIAL', $initialOneN2, $resultLabels, $actualResult);
            foreach ($baselineRows as $row) {
                PredictionEvaluation::updateOrCreate(
                    ['prediction_id' => $initial->id, 'market_key' => $row['market_key']],
                    $row
                );
            }
        }

        return count($rows);
    }

    private function publishedOutcome(Prediction $prediction, int $homeGoals, int $awayGoals, string $actualResult, string $home, string $away): ?array
    {
        $market = $prediction->published_market;
        $selection = $prediction->published_selection;
        $hit = false;
        $label = $market.' '.$selection;
        $actualLabel = $actualResult;
        if ($market === '1N2') {
            $hit = $selection === $actualResult;
            $label = ['home' => 'Victoire '.$home, 'draw' => 'Match nul', 'away' => 'Victoire '.$away][$selection] ?? $label;
            $actualLabel = ['home' => 'Victoire '.$home, 'draw' => 'Match nul', 'away' => 'Victoire '.$away][$actualResult] ?? $actualResult;
        } elseif ($market === 'double_chance') {
            $hit = $selection === '1X' ? in_array($actualResult, ['home', 'draw'], true) : ($selection === 'X2' ? in_array($actualResult, ['draw', 'away'], true) : $actualResult !== 'draw');
            $label = $selection;
            $actualLabel = $actualResult;
        } elseif ($market === 'btts') {
            $btts = $homeGoals > 0 && $awayGoals > 0;
            $hit = $selection === 'yes' ? $btts : ! $btts;
            $label = $selection === 'yes' ? 'Les deux équipes marquent' : 'Au moins une équipe ne marque pas';
            $actualLabel = $btts ? 'BTTS oui' : 'BTTS non';
        } elseif ($market === 'totals' && preg_match('/^(over|under)_(\d+)_(\d+)$/', $selection, $parts)) {
            $threshold = (float) ($parts[2].'.'.$parts[3]);
            $total = $homeGoals + $awayGoals;
            $hit = $parts[1] === 'over' ? $total > $threshold : $total <= $threshold;
            $label = ($parts[1] === 'over' ? 'Plus de ' : 'Moins de ').str_replace('.', ',', (string) $threshold).' buts';
            $actualLabel = 'Total réel '.$total;
        } elseif ($market === 'exact_score') {
            $actualScore = $homeGoals.'-'.$awayGoals;
            $hit = $selection === $actualScore;
            $label = 'Score exact '.$selection;
            $actualLabel = 'Score exact '.$actualScore;
        } else {
            return null;
        }
        return ['hit' => $hit, 'label' => $label, 'actual_label' => $actualLabel];
    }

    private function addBinaryEvaluation(
        array &$rows,
        FootballMatch $match,
        string $group,
        string $key,
        float $yesProbability,
        string $yesLabel,
        bool $actualYes,
        string $actualYesLabel,
        string $actualNoLabel
    ): void {
        $predictsYes = $yesProbability >= 0.5;
        $this->addEvaluation(
            $rows,
            $match->officialPrediction,
            $match,
            $group,
            $key,
            $predictsYes ? $yesProbability : 1.0 - $yesProbability,
            $predictsYes ? $yesLabel : 'Non : '.$yesLabel,
            $actualYes ? $actualYesLabel : $actualNoLabel,
            $predictsYes === $actualYes ? 'WON' : 'LOST'
        );
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
        $probability = max(0.0001, min(0.9999, $probability));
        $hit = $status === 'WON';
        $rows[] = [
            'match_id' => $match->id,
            'market_group' => $group,
            'market_key' => $key,
            'predicted_label' => $predictedLabel,
            'predicted_probability' => round($probability, 4),
            'brier_score' => round(($probability - ($hit ? 1.0 : 0.0)) ** 2, 6),
            'log_loss' => round(-log($hit ? $probability : (1.0 - $probability)), 6),
            'probability_bucket' => $this->probabilityBucket($probability),
            'actual_label' => $actualLabel,
            'status' => $status,
            'evaluated_at_utc' => now()->utc(),
        ];
    }

    private function probabilityBucket(float $probability): string
    {
        if ($probability < 0.50) return '0-49%';
        if ($probability < 0.60) return '50-59%';
        if ($probability < 0.70) return '60-69%';
        if ($probability < 0.80) return '70-79%';
        if ($probability < 0.90) return '80-89%';
        return '90-100%';
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
