<?php

namespace App\Console\Commands;

use App\Services\Prediction\PredictionHistoryService;
use Illuminate\Console\Command;

class PredictionHistoryCommand extends Command
{
    protected $signature = 'oracle:prediction-history
                            {--settle : Règle les snapshots dont le match est terminé}
                            {--report : Affiche les performances historiques}';

    protected $description = 'Règle et évalue l’historique immuable des pronostics publiés';

    public function handle(PredictionHistoryService $history): int
    {
        if ($this->option('settle') || (! $this->option('settle') && ! $this->option('report'))) {
            $counters = $history->settlePending();
            $this->info(sprintf(
                'Règlement : %d réglé(s), %d en attente, %d non réglable(s).',
                $counters['settled'],
                $counters['pending'],
                $counters['unsupported']
            ));
        }

        if ($this->option('report') || ! $this->option('settle')) {
            $report = $history->performance();

            $this->table(
                ['Portée', 'N', 'Brier', 'Log Loss', 'Taux de réussite'],
                collect([
                    'Global' => $report['overall'],
                ])->merge(collect($report['by_market'])->mapWithKeys(fn ($row, $key) => ['marché:'.$key => $row]))
                    ->merge(collect($report['by_category'])->mapWithKeys(fn ($row, $key) => ['catégorie:'.$key => $row]))
                    ->map(fn ($row, $key) => [
                        $key,
                        $row['n'],
                        $row['brier'] ?? '—',
                        $row['log_loss'] ?? '—',
                        $row['hit_rate'] ?? '—',
                    ])
                    ->values()
                    ->all()
            );

            $this->line(sprintf(
                'Snapshots : %d · en attente : %d · remboursés : %d · demi-gains : %d · demi-pertes : %d',
                $report['snapshots'],
                $report['overall']['pending'],
                $report['overall']['void'],
                $report['overall']['half_won'],
                $report['overall']['half_lost'],
            ));
        }

        return self::SUCCESS;
    }
}
