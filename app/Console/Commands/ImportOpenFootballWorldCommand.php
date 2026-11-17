<?php

namespace App\Console\Commands;

use App\Models\ImportRun;
use App\Services\Import\MultiSourceImporter;
use App\Services\Import\OpenFootballWorldConnector;
use Illuminate\Console\Command;
use Throwable;

class ImportOpenFootballWorldCommand extends Command
{
    protected $signature = 'oracle:import-openfootball-world
                            {--league=* : Clés configurées, ex. senegal-2025-26 (défaut : toutes)}
                            {--dry-run : Simule sans écrire en base}';

    protected $description = 'Importe les championnats internationaux/africains d\'openfootball/world (Football.TXT)';

    public function handle(MultiSourceImporter $importer): int
    {
        if (! config('imports.openfootball_world.enabled', false)) {
            $this->warn('Source openfootball/world désactivée (OPENFOOTBALL_WORLD_ENABLED=false).');

            return self::SUCCESS;
        }

        $connector = OpenFootballWorldConnector::fromConfig();
        $plan = $connector->plan(array_filter((array) $this->option('league')) ?: null);

        if ($plan === []) {
            $this->error('Aucune compétition configurée pour openfootball/world.');

            return self::FAILURE;
        }

        $run = ImportRun::begin('openfootball-world', 'configured-leagues', [
            'leagues' => array_column($plan, 'key'),
            'dry_run' => (bool) $this->option('dry-run'),
        ]);

        $records = [];
        $failures = [];

        foreach ($plan as $entry) {
            try {
                $batch = $connector->fetchAndNormalize($entry);
                $records = array_merge($records, $batch);
                $this->line("· {$entry['key']} : ".count($batch).' match(s)');
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
            $counters = $importer->import($run, $records, (bool) $this->option('dry-run'));
        } catch (Throwable $exception) {
            $run->finish('failed', ['received' => count($records)], $exception->getMessage());
            $this->error('Import interrompu : '.$exception->getMessage());

            return self::FAILURE;
        }

        $status = $failures === [] ? 'success' : 'partial';
        $run->finish($status, $counters, $failures === [] ? null : implode(' | ', $failures));

        $this->table(
            ['Reçus', 'Importés', 'Mis à jour', 'Rejetés', 'Divergents'],
            [[$counters['received'], $counters['imported'], $counters['updated'], $counters['rejected'], $counters['divergent']]]
        );

        return $status === 'success' ? self::SUCCESS : self::FAILURE;
    }
}
