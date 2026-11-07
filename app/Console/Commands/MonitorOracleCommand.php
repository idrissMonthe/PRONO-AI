<?php

namespace App\Console\Commands;

use App\Models\FootballMatch;
use App\Models\OddsSnapshot;
use App\Models\Prediction;
use App\Models\SystemAlert;
use App\Services\Prediction\PredictionClient;
use Carbon\Carbon;
use Illuminate\Console\Command;

class MonitorOracleCommand extends Command
{
    protected $signature = 'oracle:monitor';
    protected $description = 'Contrôle la fraîcheur des fournisseurs, du modèle et des prédictions.';

    public function handle(PredictionClient $predictionClient): int
    {
        $now = now()->utc();
        $lastMatchSync = FootballMatch::whereNotNull('last_synced_at')->max('last_synced_at');
        $lastOddsSnapshot = OddsSnapshot::max('snapshot_time_utc');
        $readiness = $predictionClient->modelReadiness();

        $this->checkFreshness(
            'provider.football-data.org.stale',
            $lastMatchSync,
            180,
            'Aucun match football-data.org n’a été synchronisé depuis plus de 3 heures.',
            'football-data.org'
        );
        $this->checkFreshness(
            'provider.the-odds-api.stale',
            $lastOddsSnapshot,
            180,
            'Aucune cote récente n’a été reçue depuis plus de 3 heures.',
            'the-odds-api'
        );

        if (! ($readiness['model_loaded'] ?? false)) {
            SystemAlert::raise('model.not_ready', 'Le modèle IA n’est pas chargé ou entraîné.', 'critical', 'ai-service');
        } else {
            SystemAlert::resolve('model.not_ready');
        }

        $future = FootballMatch::whereIn('status', ['SCHEDULED', 'TIMED'])
            ->where('match_date_utc', '>=', $now)
            ->count();
        $abstained = Prediction::where('is_official', true)
            ->whereHas('match', fn ($query) => $query->whereIn('status', ['SCHEDULED', 'TIMED'])->where('match_date_utc', '>=', $now))
            ->where('is_abstained', true)
            ->count();
        if ($future >= 5 && $abstained / max(1, $future) >= 0.90) {
            SystemAlert::raise('predictions.abstention_rate', "{$abstained}/{$future} prédictions futures sont actuellement abstentionnistes.", 'warning', 'prediction-engine');
        } else {
            SystemAlert::resolve('predictions.abstention_rate');
        }

        $this->info('Surveillance Oracle terminée.');
        return self::SUCCESS;
    }

    private function checkFreshness(string $key, mixed $lastSeen, int $maxAgeMinutes, string $message, string $source): void
    {
        if (! $lastSeen || Carbon::parse($lastSeen)->diffInMinutes(now()->utc()) > $maxAgeMinutes) {
            SystemAlert::raise($key, $message, 'warning', $source, ['last_seen' => $lastSeen]);
        } else {
            SystemAlert::resolve($key);
        }
    }
}
