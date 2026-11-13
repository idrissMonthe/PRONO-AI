<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ReleaseScheduleLocksCommand extends Command
{
    protected $signature = 'oracle:release-schedule-locks';

    protected $description = 'Libère les verrous `withoutOverlapping` de la planification (démarrage de conteneur).';

    public function handle(): int
    {
        if (! DB::getSchemaBuilder()->hasTable('cache_locks')) {
            return self::SUCCESS;
        }

        $released = DB::table('cache_locks')
            ->where('key', 'like', '%framework/schedule-%')
            ->delete();

        $this->info("Verrous de planification libérés : {$released}.");

        return self::SUCCESS;
    }
}
