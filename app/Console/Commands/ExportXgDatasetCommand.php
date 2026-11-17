<?php

namespace App\Console\Commands;

use App\Models\FootballMatch;
use App\Models\TeamMatchStat;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Exporte un jeu de données chronologique pour le backtest Python.
 *
 * Le fichier ne contient que des matchs terminés, triés par date de coup
 * d'envoi. Les champs xG restent nuls lorsque la source ne les a pas fournis :
 * le backtest doit alors se rabattre sur Dixon-Coles au lieu d'inventer une
 * valeur.
 */
class ExportXgDatasetCommand extends Command
{
    protected $signature = 'oracle:export-xg-dataset
                            {--output=storage/app/backtest/dataset.json : Chemin du fichier JSON}
                            {--league= : Code de compétition à exporter}
                            {--limit=5000 : Nombre maximal de matchs}';

    protected $description = 'Exporte les matchs terminés (avec xG si disponible) pour le backtest walk-forward';

    public function handle(): int
    {
        $query = FootballMatch::query()
            ->with(['homeTeam:id,name', 'awayTeam:id,name', 'league:id,code'])
            ->where('status', 'FINISHED')
            ->whereNotNull('home_score')
            ->whereNotNull('away_score')
            ->orderBy('match_date_utc')
            ->limit(max(10, (int) $this->option('limit')));

        $leagueCode = $this->option('league');
        if (is_string($leagueCode) && $leagueCode !== '') {
            $query->whereHas('league', fn ($builder) => $builder->where('code', $leagueCode));
        }

        $matches = $query->get();
        if ($matches->isEmpty()) {
            $this->error('Aucun match terminé ne correspond à la demande.');

            return self::FAILURE;
        }

        $stats = $this->statsByMatch($matches->pluck('id')->all());

        $rows = $matches->map(function (FootballMatch $match) use ($stats): array {
            $home = $stats->get($match->id.':'.$match->home_team_id);
            $away = $stats->get($match->id.':'.$match->away_team_id);

            return [
                'date' => Carbon::parse($match->match_date_utc)->toDateString(),
                'home_team' => $match->homeTeam?->name,
                'away_team' => $match->awayTeam?->name,
                'home_goals' => (int) $match->home_score,
                'away_goals' => (int) $match->away_score,
                'competition' => $match->league?->code ?? 'unknown',
                'home_xg' => $home?->xg === null ? null : (float) $home->xg,
                'away_xg' => $away?->xg === null ? null : (float) $away->xg,
                'home_shots' => $home?->shots,
                'away_shots' => $away?->shots,
                'home_shots_on_target' => $home?->shots_on_target,
                'away_shots_on_target' => $away?->shots_on_target,
            ];
        })->filter(fn (array $row): bool => $row['home_team'] !== null && $row['away_team'] !== null)->values();

        $path = (string) $this->option('output');
        $absolute = str_starts_with($path, DIRECTORY_SEPARATOR) || preg_match('/^[A-Za-z]:/', $path)
            ? $path
            : base_path($path);
        $directory = dirname($absolute);
        if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
            $this->error("Impossible de créer le dossier {$directory}.");

            return self::FAILURE;
        }

        $payload = [
            'generated_at' => now()->utc()->toIso8601String(),
            'source' => 'prono-ia export',
            'matches' => $rows->all(),
        ];

        $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if ($encoded === false || file_put_contents($absolute, $encoded) === false) {
            $this->error("Écriture impossible dans {$absolute}.");

            return self::FAILURE;
        }

        $withXg = $rows->filter(fn (array $row): bool => $row['home_xg'] !== null && $row['away_xg'] !== null)->count();

        $this->table(
            ['Matchs exportés', 'Avec xG', 'Compétitions', 'Fichier'],
            [[
                $rows->count(),
                $withXg,
                $rows->pluck('competition')->unique()->implode(', '),
                $absolute,
            ]]
        );

        if ($withXg === 0) {
            $this->warn('Aucun xG disponible : le backtest comparera uniquement Dixon-Coles et la calibration.');
        }

        return self::SUCCESS;
    }

    /** @param array<int, int> $matchIds */
    private function statsByMatch(array $matchIds): Collection
    {
        return TeamMatchStat::query()
            ->whereIn('match_id', $matchIds)
            ->get()
            ->keyBy(fn (TeamMatchStat $stat): string => $stat->match_id.':'.$stat->team_id);
    }
}
