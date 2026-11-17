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
                            {--offset= : Nombre de matchs à ignorer avant de lire les événements}
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
            $offset = max(0, (int) $this->option('offset'));

            // `--offset` permet de paginer : sans lui, chaque relance relisait
            // les mêmes premiers matchs et ne progressait jamais.
            foreach (array_slice($records, $offset, max(1, $limit)) as $record) {
                try {
                    if ($importer->importStats($record, $connector, $dryRun)) {
                        $statsImported++;
                    } else {
                        // Fichier d'événements indisponible ou match non lié :
                        // l'import reste valide, mais la couverture est partielle.
                        $failures[] = 'stats '.$record->externalId.' : statistiques indisponibles';
                        $this->warn('⚠️  Statistiques indisponibles pour '.$record->externalId);
                    }
                } catch (Throwable $exception) {
                    $failures[] = 'stats '.$record->externalId.' : '.$exception->getMessage();
                    $this->warn('⚠️  Statistiques indisponibles pour '.$record->externalId);
                }
            }
        }

        $status = $failures === [] ? 'success' : 'partial';
        $imported = (int) $counters['imported'] + (int) $counters['updated'];
        $error = $failures === [] ? null : implode(' | ', $failures);

        if ($imported === 0) {
            $status = 'failed';
            $error ??= 'Aucun match importé.';
        }

        // Union PHP : `$counters` contient déjà une clé `metadata`, il faut donc
        // la compléter explicitement (sinon `stats_imported` est perdu).
        $counters['metadata'] = array_merge((array) ($counters['metadata'] ?? []), ['stats_imported' => $statsImported]);
        $run->finish($status, $counters, $error);

        $this->table(
            ['Reçus', 'Importés', 'Mis à jour', 'Rejetés', 'Divergents', 'Stats xG'],
            [[$counters['received'], $counters['imported'], $counters['updated'], $counters['rejected'], $counters['divergent'], $statsImported]]
        );

        // Un fichier de statistiques indisponible ne doit pas faire échouer un
        // import qui a bien écrit des matchs : le statut `partial` consigné dans
        // import_runs porte le détail. Le code 1 reste réservé aux échecs
        // complets (aucun match importé).
        return $status === 'failed' ? self::FAILURE : self::SUCCESS;
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
