<?php

namespace App\Console\Commands;

use App\Services\FootballData\FootballDataService;
use App\Services\Prediction\PredictionSettlementService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Throwable;

class SyncLiveScoresCommand extends Command
{
    protected $signature = 'oracle:sync-live-scores';
    protected $description = 'Actualise les statuts et scores récents depuis football-data.org.';

    public function handle(FootballDataService $service): int
    {
        try {
            $date = Carbon::now('Africa/Douala')->toDateString();
            $result = $service->syncFromFootballDataOrg($date, $date);
            $settled = app(PredictionSettlementService::class)->settleFinishedPredictions();
            $result['evaluations'] = $settled;
            $this->info("Scores live actualisés : {$result['imported']} match(s).");
            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());
            return self::FAILURE;
        }
    }
}
