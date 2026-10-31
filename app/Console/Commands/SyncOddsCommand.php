<?php

namespace App\Console\Commands;

use App\Services\Odds\TheOddsApiService;
use Illuminate\Console\Command;
use Throwable;

class SyncOddsCommand extends Command
{
    protected $signature = 'oracle:sync-odds {--date-from= : Date de début Y-m-d} {--date-to= : Date de fin Y-m-d}';
    protected $description = 'Synchronise les cotes 1N2 réelles depuis The Odds API.';

    public function handle(TheOddsApiService $service): int
    {
        try {
            $result = $service->sync($this->option('date-from'), $this->option('date-to'));
            $this->table(['Indicateur', 'Valeur'], [
                ['Événements reçus', $result['received']],
                ['Matchs rapprochés', $result['matched']],
                ['Snapshots enregistrés', $result['snapshots']],
                ['Matchs non rapprochés', $result['unmatched']],
            ]);
            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());
            return self::FAILURE;
        }
    }
}
