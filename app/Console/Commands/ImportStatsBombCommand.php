<?php

namespace App\Console\Commands;

use App\Models\ImportRun;
use App\Services\Import\MultiSourceImporter;
use App\Services\Import\StatsBombConnector;
use Illuminate\Console\Command;
use Throwable;

class ImportStatsBombCommand extends Command
{
    protected $signature = 'oracle:import-statsbomb
                            {--competition= : Identifiant StatsBomb (défaut : configuration)}
                            {--season= : Identifiant de saison StatsBomb (défaut : configuration)}
                            {--stats : Importe aussi tirs, tirs cadrés et xG des matchs liés}
                            {--limit= : Nombre maximal de matchs dont les événements sont lus}
                            {--dry-run : Simule sans écrire en base}';

    protected $description = 'Importe les matchs historiques StatsBomb Open Data et, sur demande, les statistiques de tirs/xG';

    public function handle(MultiSourceImporter $importer): int
    {
        if (! config('imports.statsbomb.enabled', false)) {
            $this->warn('Source StatsBomb désactivée (STATSBOMB_ENABLED=false).');

            return self::SUCCESS;
        }

        $connector = StatsBombConnector::fromConfig();
        $seasons = $this->resolveSeasons($connector);

        if ($seasons === []) {
            $this->error('Aucune saison StatsBomb ne correspond aux options demandées.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $run = ImportRun::begin('statsbomb', 'historical', [
            'seasons' => $seasons,
            'dry_run' => $dryRun,
        ]);

        $records = [];
        $failures = [];

        foreach ($seasons as $season) {
            try {
                $batch = $connector->fetchAndNormalize($season);
                $records = array_merge($records, $batch);
                $this->line(sprintf(
                    '· %s %s : %d match(s)',
                    $season['name'] ?? 'Compétition',
                    $season['season'] ?? '',
                    count($batch)
                ));
            } catch (Throwable $exception) {
                $failures[] = $exception->getMessage();
                $this->warn('⚠️  '.$exception->getMessage());
            }
        }

        if ($records === []) {
            $run->finish('failed', ['received' => 0], implode(' | ', $failures) ?: 'Aucun match récupéré.');

            return self::FAILURE;
        }

        try {
            $counters = $importer->import($run, $records, $dryRun);
        } catch (Throwable $exception) {
            $run->finish('failed', ['received' => count($records)], $exception->getMessage());
            $this->error('Import interrompu : '.$exception->getMessage());

            return self::FAILURE;
        }

        $statsImported = 0;
        if ($this->option('stats')) {
            $limit = (int) ($this->option('limit') ?: config('imports.statsbomb.stats_limit', 25));
            foreach (array_slice($records, 0, max(1, $limit)) as $record) {
                try {
                    if ($importer->importStats($record, $connector, $dryRun)) {
                        $statsImported++;
                    }
                } catch (Throwable $exception) {
                    $failures[] = 'stats '.$record->externalId.' : '.$exception->getMessage();
                    $this->warn('⚠️  Statistiques indisponibles pour '.$record->externalId);
                }
            }
        }

        $status = $failures === [] ? 'success' : 'partial';
        $run->finish($status, $counters + ['metadata' => ['stats_imported' => $statsImported]], $failures === [] ? null : implode(' | ', $failures));

        $this->table(
            ['Reçus', 'Importés', 'Mis à jour', 'Rejetés', 'Divergents', 'Stats xG'],
            [[$counters['received'], $counters['imported'], $counters['updated'], $counters['rejected'], $counters['divergent'], $statsImported]]
        );

        return $status === 'success' ? self::SUCCESS : self::FAILURE;
    }

    /** @return array<int, array<string, mixed>> */
    private function resolveSeasons(StatsBombConnector $connector): array
    {
        $competition = $this->option('competition');
        $season = $this->option('season');

        if ($competition === null && $season === null) {
            return $connector->seasons();
        }

        if ($competition === null || $season === null) {
            $this->error('--competition et --season doivent être fournis ensemble.');

            return [];
        }

        $configured = collect($connector->seasons())->first(
            fn (array $entry): bool => (int) ($entry['competition_id'] ?? 0) === (int) $competition
                && (int) ($entry['season_id'] ?? 0) === (int) $season
        );

        if ($configured === null) {
            $this->error(sprintf(
                'Le couple compétition %s / saison %s n\'est pas configuré : ajoutez-le dans config/imports.php après vérification dans competitions.json.',
                $competition,
                $season
            ));

            return [];
        }

        return [$configured];
    }
}
