<?php

namespace App\Console\Commands;

use App\Models\FootballMatch;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PurgeDemoDataCommand extends Command
{
    protected $signature = 'oracle:purge-demo-data {--dry-run : Compte les données sans les supprimer}';
    protected $description = 'Retire les matchs futurs de démonstration des analyses officielles.';

    public function handle(): int
    {
        // Seules les rencontres sans identifiant fournisseur (données de
        // démonstration) sont retirées : les matchs réels api-football et
        // football-data.org sont conservés.
        $query = FootballMatch::whereIn('status', ['SCHEDULED', 'TIMED'])
            ->where('match_date_utc', '>=', now()->utc())
            ->where(function ($q) {
                $q->whereNull('external_id')->orWhereIn('data_source', ['demo', 'test']);
            });

        $count = (clone $query)->count();
        if ($this->option('dry-run')) {
            $this->table(['ID', 'Domicile', 'Extérieur', 'Date UTC', 'Source', 'External ID'],
                $query->with(['homeTeam', 'awayTeam'])->get()->map(fn ($match) => [
                    $match->id,
                    $match->homeTeam->name,
                    $match->awayTeam->name,
                    $match->match_date_utc,
                    $match->data_source,
                    $match->external_id ?: 'NULL',
                ])->all()
            );
            $this->info("Matchs de démonstration détectés : {$count}.");
            return self::SUCCESS;
        }

        DB::transaction(fn () => $query->get()->each->delete());
        $this->info("Matchs de démonstration supprimés : {$count}.");
        return self::SUCCESS;
    }
}
