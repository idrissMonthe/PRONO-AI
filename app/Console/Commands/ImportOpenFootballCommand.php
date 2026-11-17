<?php

namespace App\Console\Commands;

use App\Models\ImportRun;
use App\Services\Import\MultiSourceImporter;
use App\Services\Import\OpenFootballConnector;
use Illuminate\Console\Command;
use Throwable;

class ImportOpenFootballCommand extends Command
{
    protected $signature = 'oracle:import-openfootball
                            {--season= : Saison au format 2025-26 (défaut : configuration)}
                            {--competition=* : Fichiers du dépôt, ex. en.1.json (défaut : tous)}
                            {--dry-run : Simule sans écrire en base}';

    protected $description = 'Importe calendriers et résultats historiques depuis openfootball/football.json';

    public function handle(MultiSourceImporter $importer): int
    {
        if (! config('imports.openfootball.enabled', false)) {
            $this->warn('Source openfootball désactivée (OPENFOOTBALL_ENABLED=false).');

            return self::SUCCESS;
        }

        $connector = OpenFootballConnector::fromConfig();
        $files = array_filter((array) $this->option('competition'));
        $plan = $connector->plan($this->option('season') ?: null, $files ?: null);

        if ($plan === []) {
            $this->error('Aucune compétition à importer : vérifiez la saison et les fichiers demandés.');

            return self::FAILURE;
        }

        $run = ImportRun::begin('openfootball', $this->option('season') ?: 'config', [
            'files' => array_column($plan, 'file'),
            'dry_run' => (bool) $this->option('dry-run'),
        ]);

        $records = [];
        $failures = [];

        foreach ($plan as $entry) {
            try {
                $batch = $connector->fetchAndNormalize(
                    $entry['season'],
                    $entry['file'],
                    $entry['code'],
                    $entry['name'],
                    $entry['country']
                );
                $records = array_merge($records, $batch);
                $this->line("· {$entry['season']}/{$entry['file']} : ".count($batch).' match(s)');
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
            ['Reçus', 'Importés', 'Mis à jour', 'Rejetés', 'Divergents', 'Simulation'],
            [[
                $counters['received'],
                $counters['imported'],
                $counters['updated'],
                $counters['rejected'],
                $counters['divergent'],
                $this->option('dry-run') ? 'oui' : 'non',
            ]]
        );

        foreach ($counters['metadata']['divergences'] as $divergence) {
            $this->warn(sprintf(
                'Divergence match #%d : score conservé %s-%s (%s), score entrant %s-%s (%s).',
                $divergence['match_id'],
                $divergence['kept_score'][0],
                $divergence['kept_score'][1],
                $divergence['kept_source'],
                $divergence['incoming_score'][0],
                $divergence['incoming_score'][1],
                $divergence['incoming_source']
            ));
        }

        return $status === 'success' ? self::SUCCESS : self::FAILURE;
    }
}
