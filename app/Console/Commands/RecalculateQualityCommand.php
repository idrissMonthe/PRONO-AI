<?php

namespace App\Console\Commands;

use App\Models\FootballMatch;
use App\Services\FootballData\FootballDataService;
use Illuminate\Console\Command;

class RecalculateQualityCommand extends Command
{
    protected $signature = 'oracle:recalculate-quality';
    protected $description = 'Recalcule la qualité des données à partir des matchs et snapshots persistés.';

    public function handle(FootballDataService $service): int
    {
        $updated = 0;
        FootballMatch::query()->chunkById(100, function ($matches) use ($service, &$updated): void {
            foreach ($matches as $match) {
                $service->refreshQualityScore($match);
                $updated++;
            }
        });

        $this->info("{$updated} match(s) recalculé(s).");
        return self::SUCCESS;
    }
}
