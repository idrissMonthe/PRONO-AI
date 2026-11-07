<?php

namespace App\Console\Commands;

use App\Models\SystemAlert;
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
            SystemAlert::resolve('provider.football-data.org.live_stale');
            return self::SUCCESS;
        } catch (Throwable $exception) {
            SystemAlert::raise('provider.football-data.org.live_stale', 'Les scores live ne peuvent plus être actualisés : '.$exception->getMessage(), 'critical', 'football-data.org');
            $this->error($exception->getMessage());
            return self::FAILURE;
        }
    }
}
