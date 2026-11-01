<?php

namespace App\Console\Commands;

use App\Services\Odds\OddsMovementService;
use Illuminate\Console\Command;

class UpdateOddsMovementCommand extends Command
{
    protected $signature = 'oracle:update-odds-movement';
    protected $description = 'Met à jour les cotes d’ouverture, de clôture et le CLV des pronostics virtuels.';

    public function handle(OddsMovementService $service): int
    {
        $stats = $service->refreshMarkersAndPaperBetClv();
        $this->info(sprintf(
            '%d cote(s) d’ouverture, %d cote(s) de clôture et %d CLV mis à jour.',
            $stats['opening_snapshots'],
            $stats['closing_snapshots'],
            $stats['paper_bets_updated']
        ));

        return self::SUCCESS;
    }
}
