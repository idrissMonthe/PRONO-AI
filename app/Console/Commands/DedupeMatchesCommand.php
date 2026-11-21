<?php

namespace App\Console\Commands;

use App\Models\FootballMatch;
use App\Services\Integrity\HashChainService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class DedupeMatchesCommand extends Command
{
    protected $signature = 'oracle:dedupe-matches {--dry-run : Affiche les doublons sans modifier la base}';
    protected $description = 'Fusionne les lignes de matchs identiques en conservant la ligne API.';

    public function handle(): int
    {
        $groups = FootballMatch::query()
            ->select(['league_id', 'home_team_id', 'away_team_id', 'match_date_utc'])
            ->groupBy(['league_id', 'home_team_id', 'away_team_id', 'match_date_utc'])
            ->havingRaw('COUNT(*) > 1')
            ->get();

        $merged = 0;
        foreach ($groups as $group) {
            $matches = FootballMatch::where([
                'league_id' => $group->league_id,
                'home_team_id' => $group->home_team_id,
                'away_team_id' => $group->away_team_id,
                'match_date_utc' => $group->match_date_utc,
            ])->orderByRaw('CASE WHEN external_id IS NULL THEN 1 ELSE 0 END')->orderBy('id')->get();

            $canonical = $matches->first();
            foreach ($matches->skip(1) as $duplicate) {
                $this->line("Fusion match #{$duplicate->id} dans #{$canonical->id}");
                if ($this->option('dry-run')) {
                    continue;
                }

                DB::transaction(function () use ($canonical, $duplicate): void {
                    DB::table('odds_snapshots')->where('match_id', $duplicate->id)->update(['match_id' => $canonical->id]);
                    DB::table('paper_bets')->where('match_id', $duplicate->id)->update(['match_id' => $canonical->id]);
                    DB::table('predictions')->where('match_id', $duplicate->id)->update(['match_id' => $canonical->id]);

                    foreach (DB::table('team_match_stats')->where('match_id', $duplicate->id)->get() as $stat) {
                        $exists = DB::table('team_match_stats')->where('match_id', $canonical->id)->where('team_id', $stat->team_id)->exists();
                        if ($exists) {
                            DB::table('team_match_stats')->where('id', $stat->id)->delete();
                        } else {
                            DB::table('team_match_stats')->where('id', $stat->id)->update(['match_id' => $canonical->id]);
                        }
                    }

                    $duplicate->delete();
                });
                $merged++;
            }
        }

        if (! $this->option('dry-run') && $merged > 0) {
            // Les prédictions déplacées changent de match_id, donc leur
            // empreinte : la chaîne est réécrite dans l'ordre chronologique
            // pour rester vérifiable après la fusion.
            $result = app(HashChainService::class)->rebuildLedger();
            $this->info("Chaîne d’intégrité reconstruite : {$result['chained']} bloc(s).");
        }

        $this->info($this->option('dry-run') ? "Doublons détectés : {$merged} ligne(s)." : "Doublons fusionnés : {$merged}.");
        return self::SUCCESS;
    }
}
