<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class NormalizeOfficialPredictionsCommand extends Command
{
    protected $signature = 'oracle:normalize-official-predictions {--dry-run : Compte les lignes sans les modifier}';

    protected $description = 'Ne conserve qu’une seule prédiction officielle par rencontre (la plus récente).';

    public function handle(): int
    {
        $rows = DB::table('predictions')
            ->selectRaw('match_id, MAX(id) as keep_id, COUNT(*) as total')
            ->where('is_official', 1)
            ->groupBy('match_id')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        $toDemote = (int) $rows->sum(fn ($row) => $row->total - 1);

        if ($this->option('dry-run')) {
            $this->info("{$rows->count()} rencontre(s) concernée(s), {$toDemote} ligne(s) à démettre.");
            $this->table(
                ['Match', 'Officielles', 'Conservée'],
                $rows->take(20)->map(fn ($row) => [$row->match_id, $row->total, $row->keep_id])->all()
            );

            return self::SUCCESS;
        }

        DB::transaction(function () use ($rows): void {
            foreach ($rows as $row) {
                DB::table('predictions')
                    ->where('match_id', $row->match_id)
                    ->where('is_official', 1)
                    ->where('id', '!=', $row->keep_id)
                    ->update(['is_official' => 0]);
            }
        });

        $this->info("{$toDemote} prédiction(s) officielle(s) en doublon démarquée(s) sur {$rows->count()} rencontre(s).");

        return self::SUCCESS;
    }
}
