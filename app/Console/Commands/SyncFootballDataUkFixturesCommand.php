<?php

namespace App\Console\Commands;

use App\Models\ImportRun;
use App\Services\Import\FootballDataUkConnector;
use App\Services\Import\MultiSourceImporter;
use Illuminate\Console\Command;
use Throwable;

/**
 * Relève les cotes 1N2 (et Plus/Moins 2,5) des rencontres à venir depuis
 * `fixtures.csv` de Football-Data.co.uk.
 *
 * Ces prix sont écrits en non-closing : c'est ce que `OddsService` retient pour
 * envoyer `market_probabilities` au moteur Python, donc le blend marché
 * fonctionne à nouveau sans consommer un seul crédit The Odds API.
 */
class SyncFootballDataUkFixturesCommand extends Command
{
    protected $signature = 'oracle:sync-football-data-uk-fixtures
                            {--division=* : Division à relever E0, SP1, I1… (défaut : configuration)}
                            {--dry-run : Simule sans écrire en base}';

    protected $description = 'Relève les cotes des matchs à venir depuis Football-Data.co.uk (sans quota API)';

    public function handle(MultiSourceImporter $importer): int
    {
        $config = (array) config('imports.football_data_uk', []);

        if (! ($config['enabled'] ?? false)) {
            $this->warn('Source Football-Data.co.uk désactivée (FOOTBALL_DATA_UK_ENABLED=false).');

            return self::SUCCESS;
        }

        $connector = FootballDataUkConnector::fromConfig();

        try {
            $batch = $connector->fetchUpcomingFixtures();
        } catch (Throwable $exception) {
            ImportRun::begin('football-data-uk', 'fixtures')->finish('failed', ['received' => 0], $exception->getMessage());

            $this->error('fixtures.csv indisponible : '.$exception->getMessage());

            return self::FAILURE;
        }

        $divisions = array_values(array_filter((array) $this->option('division')));
        if ($divisions !== []) {
            $batch = array_values(array_filter(
                $batch,
                fn ($match): bool => in_array((string) ($match->metadata['division'] ?? ''), $divisions, true)
            ));
        }

        $dryRun = (bool) $this->option('dry-run');
        $run = ImportRun::begin('football-data-uk', 'fixtures', [
            'divisions' => $divisions,
            'dry_run' => $dryRun,
        ]);

        if ($batch === []) {
            $run->finish('success', ['received' => 0]);

            // Fichier en retard ou hors période : rien à écrire, mais ce n'est
            // pas une erreur d'exécution.
            $this->warn('Aucun match à venir exploitable dans fixtures.csv (fichier en retard ou hors période).');

            return self::SUCCESS;
        }

        $counters = $importer->import($run, $batch, $dryRun);
        $run->finish('success', $counters);

        $this->table(
            ['Reçus', 'Importés', 'Mis à jour', 'Rejetés', 'Cotes'],
            [[
                $counters['received'],
                $counters['imported'],
                $counters['updated'],
                $counters['rejected'],
                $counters['odds_rows'],
            ]]
        );

        $this->line(sprintf(
            '%s : %d rencontre(s) à venir, %d snapshot(s) de cotes non-closing.',
            $dryRun ? 'Simulation' : 'Import',
            $counters['received'],
            $counters['odds_rows']
        ));

        return self::SUCCESS;
    }
}
