<?php

namespace App\Console\Commands;

use App\Models\ImportRun;
use App\Services\Import\FootballDataUkConnector;
use App\Services\Import\MultiSourceImporter;
use Illuminate\Console\Command;
use Throwable;

class ImportFootballDataUkCommand extends Command
{
    protected $signature = 'oracle:import-football-data-uk
                            {--season=* : Saison au format 2627 (défaut : configuration)}
                            {--division=* : Code de division E0, SP1, I1… (défaut : configuration)}
                            {--dry-run : Simule sans écrire en base}';

    protected $description = 'Importe résultats, statistiques et cotes de clôture depuis Football-Data.co.uk';

    public function handle(MultiSourceImporter $importer): int
    {
        $config = (array) config('imports.football_data_uk', []);

        if (! ($config['enabled'] ?? false)) {
            $this->warn('Source Football-Data.co.uk désactivée (FOOTBALL_DATA_UK_ENABLED=false).');

            return self::SUCCESS;
        }

        $connector = FootballDataUkConnector::fromConfig();
        $seasons = array_values(array_filter((array) $this->option('season'))) ?: $connector->seasons();
        $divisions = array_values(array_filter((array) $this->option('division'))) ?: array_keys($connector->competitions());

        if ($seasons === [] || $divisions === []) {
            $this->error('Aucune saison ou division à importer.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $run = ImportRun::begin('football-data-uk', 'historical', [
            'seasons' => $seasons,
            'divisions' => $divisions,
            'dry_run' => $dryRun,
        ]);

        $failures = [];
        $totals = ['received' => 0, 'imported' => 0, 'updated' => 0, 'rejected' => 0, 'divergent' => 0, 'stats_rows' => 0, 'odds_rows' => 0];

        foreach ($seasons as $season) {
            foreach ($divisions as $division) {
                try {
                    $batch = $connector->fetchAndNormalize((string) $season, (string) $division);
                } catch (Throwable $exception) {
                    $failures[] = "{$season}/{$division} : ".$exception->getMessage();
                    $this->warn("⚠️  {$season}/{$division} : ".$exception->getMessage());

                    continue;
                }

                if ($batch === []) {
                    $this->line("· {$season}/{$division} : aucun match");

                    continue;
                }

                $this->line(sprintf('· %s/%s : %d match(s)', $season, $division, count($batch)));

                // Import fichier par fichier : un lot de plusieurs centaines de
                // matchs rendait les erreurs de déduplication difficiles à isoler.
                $counters = $importer->import($run, $batch, $dryRun);
                foreach ($totals as $key => $value) {
                    $totals[$key] = $value + (int) ($counters[$key] ?? 0);
                }
            }
        }

        if ($totals['received'] === 0) {
            $run->finish('failed', $totals, implode(' | ', $failures) ?: 'Aucun match récupéré.');

            return self::FAILURE;
        }

        $status = $failures === [] ? 'success' : 'partial';
        $run->finish($status, $totals, $failures === [] ? null : implode(' | ', $failures));

        $this->table(
            ['Reçus', 'Importés', 'Mis à jour', 'Rejetés', 'Divergents', 'Lignes stats', 'Cotes'],
            [[
                $totals['received'],
                $totals['imported'],
                $totals['updated'],
                $totals['rejected'],
                $totals['divergent'],
                $totals['stats_rows'],
                $totals['odds_rows'],
            ]]
        );

        return self::SUCCESS;
    }
}
