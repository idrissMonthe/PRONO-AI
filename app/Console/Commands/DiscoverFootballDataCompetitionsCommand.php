<?php

namespace App\Console\Commands;

use App\Services\FootballData\FootballDataService;
use Illuminate\Console\Command;
use RuntimeException;

class DiscoverFootballDataCompetitionsCommand extends Command
{
    protected $signature = 'oracle:football-data-competitions
                            {--refresh : Ignore le cache du catalogue fournisseur}';

    protected $description = 'Liste les compétitions football-data.org accessibles et les compétitions internationales détectées.';

    public function handle(FootballDataService $service): int
    {
        try {
            $catalog = $service->competitionCatalog((bool) $this->option('refresh'));
            $international = $service->discoverInternationalCompetitions(false);
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());
            return self::FAILURE;
        }

        $this->info(sprintf('%d compétition(s) exposée(s) par football-data.org.', count($catalog)));
        $this->table(
            ['Code', 'Nom', 'Type', 'Zone', 'Plan'],
            array_map(
                static fn (array $competition): array => [
                    $competition['code'],
                    $competition['name'],
                    $competition['type'],
                    $competition['area'],
                    $competition['plan'],
                ],
                $catalog
            )
        );

        $this->newLine();
        $this->info(sprintf('%d compétition(s) internationale(s) détectée(s).', count($international)));
        $state = config('services.football_data.discover_international', false) ? 'activée' : 'désactivée';
        $this->line('La découverte internationale est '.$state.' via FOOTBALL_DATA_DISCOVER_INTERNATIONAL.');

        return self::SUCCESS;
    }
}
