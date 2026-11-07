<?php

namespace App\Console\Commands;

use App\Models\ModelVersion;
use App\Models\BacktestRun;
use App\Models\PredictionEvaluation;
use Illuminate\Console\Command;

class EvaluatePredictionPerformanceCommand extends Command
{
    protected $signature = 'oracle:evaluate-performance';
    protected $description = 'Calcule les métriques réelles et la calibration par marché et par modèle.';

    public function handle(): int
    {
        $evaluations = PredictionEvaluation::with('prediction.modelVersion')
            ->whereIn('status', ['WON', 'LOST'])
            ->get();

        if ($evaluations->isEmpty()) {
            $this->warn('Aucune évaluation terminée à mesurer.');
            return self::SUCCESS;
        }

        $byModel = $evaluations->groupBy(fn ($evaluation) => $evaluation->prediction?->model_version_id);
        foreach ($byModel as $modelId => $modelEvaluations) {
            $model = ModelVersion::find($modelId);
            if (! $model) continue;

            $markets = [];
            foreach ($modelEvaluations->groupBy('market_group') as $market => $rows) {
                $total = $rows->count();
                $won = $rows->where('status', 'WON')->count();
                $brier = $rows->avg(fn ($row) => (float) ($row->brier_score ?? ((float) $row->predicted_probability - ($row->status === 'WON' ? 1 : 0)) ** 2));
                $logLoss = $rows->avg(function ($row): float {
                    $probability = max(0.0001, min(0.9999, (float) $row->predicted_probability));
                    return -log($row->status === 'WON' ? $probability : 1.0 - $probability);
                });
                $ece = $this->calculateEce($rows);
                $markets[$market] = [
                    'sample_size' => $total,
                    'hit_rate' => round($won / max(1, $total), 4),
                    'brier' => round((float) $brier, 6),
                    'log_loss' => round((float) $logLoss, 6),
                    'ece' => round($ece, 6),
                    'last_evaluated_at' => $rows->sortByDesc('evaluated_at_utc')->first()?->evaluated_at_utc?->toIso8601String(),
                ];
            }

            $metrics = $model->validation_metrics ?? [];
            $metrics['evaluated_at'] = now()->utc()->toIso8601String();
            $metrics['sample_size'] = $modelEvaluations->count();
            $metrics['markets'] = $markets;
            $model->update(['validation_metrics' => $metrics]);
            $periodStart = $modelEvaluations->sortBy('evaluated_at_utc')->first()?->evaluated_at_utc?->toDateString();
            $periodEnd = $modelEvaluations->sortByDesc('evaluated_at_utc')->first()?->evaluated_at_utc?->toDateString();
            if ($periodStart && $periodEnd) {
                BacktestRun::create([
                    'model_version_id' => $model->id,
                    'family' => $model->family,
                    'config_hash' => $model->config_hash,
                    'data_hash' => hash('sha256', $modelEvaluations->pluck('prediction_id')->sort()->implode(',')),
                    'period_start' => $periodStart,
                    'period_end' => $periodEnd,
                    'time_step' => 'frozen_prediction_snapshots',
                    'metrics' => ['markets' => $markets, 'leakage_guard' => 'only_features frozen before kickoff'],
                    'report_text' => 'Évaluation walk-forward des snapshots figés avant le coup d’envoi.',
                    'status' => 'COMPLETED',
                ]);
            }
            $this->line("{$model->version_name} : {$modelEvaluations->count()} évaluations mesurées.");
        }

        $this->info('Calibration et performance mises à jour.');
        return self::SUCCESS;
    }

    private function calculateEce($rows): float
    {
        if ($rows->isEmpty()) return 0.0;
        return (float) $rows->groupBy(fn ($row) => (int) min(9, floor((float) $row->predicted_probability * 10)))
            ->sum(function ($bucket) use ($rows): float {
                $confidence = $bucket->avg(fn ($row) => (float) $row->predicted_probability);
                $frequency = $bucket->where('status', 'WON')->count() / max(1, $bucket->count());
                return ($bucket->count() / max(1, $rows->count())) * abs($confidence - $frequency);
            });
    }
}
