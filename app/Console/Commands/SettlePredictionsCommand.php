<?php

namespace App\Console\Commands;

use App\Services\Prediction\PredictionSettlementService;
use Illuminate\Console\Command;

class SettlePredictionsCommand extends Command
{
    protected $signature = 'oracle:settle-predictions';
    protected $description = 'Compare les prédictions officielles aux résultats football-data.org.';

    public function handle(PredictionSettlementService $service): int
    {
        $count = $service->settleFinishedPredictions();
        $this->info("{$count} évaluation(s) de marché enregistrée(s).");
        return self::SUCCESS;
    }
}
