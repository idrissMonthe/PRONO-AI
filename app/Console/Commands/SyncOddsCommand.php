<?php

namespace App\Console\Commands;

use App\Models\SystemAlert;
use App\Services\Odds\TheOddsApiService;
use Illuminate\Console\Command;
use Throwable;

class SyncOddsCommand extends Command
{
    protected $signature = 'oracle:sync-odds {--date-from= : Date de début Y-m-d} {--date-to= : Date de fin Y-m-d}';
    protected $description = 'Synchronise les cotes 1N2 réelles depuis The Odds API.';

    public function handle(TheOddsApiService $service): int
    {
        try {
            $result = $service->sync($this->option('date-from'), $this->option('date-to'));
            if (($result['failed'] ?? 0) === 0) {
                SystemAlert::resolve('provider.the-odds-api.unavailable');
            } else {
                SystemAlert::raise(
                    'provider.the-odds-api.unavailable',
                    'The Odds API ne répond pas pour au moins une compétition.',
                    ($result['failed'] ?? 0) >= count((array) config('services.the_odds_api.sports', [])) ? 'critical' : 'warning',
                    'the-odds-api',
                    ['failed' => $result['failed'], 'errors' => $result['errors'] ?? []]
                );
            }
            $this->table(['Indicateur', 'Valeur'], [
                ['Événements reçus', $result['received']],
                ['Matchs rapprochés', $result['matched']],
                ['Snapshots enregistrés', $result['snapshots']],
                ['Matchs non rapprochés', $result['unmatched']],
                ['Compétitions en erreur', $result['failed'] ?? 0],
            ]);
            if (($result['failed'] ?? 0) > 0) {
                $this->warn('Certaines compétitions n’ont pas répondu ; les autres résultats ont été conservés.');
                foreach (($result['errors'] ?? []) as $sport => $error) {
                    $this->line("- {$sport} : {$error}");
                }
            }
            return ($result['failed'] ?? 0) < count((array) config('services.the_odds_api.sports', []))
                ? self::SUCCESS
                : self::FAILURE;
        } catch (Throwable $exception) {
            SystemAlert::raise('provider.the-odds-api.unavailable', 'The Odds API est indisponible : '.$exception->getMessage(), 'critical', 'the-odds-api');
            $this->error($exception->getMessage());
            return self::FAILURE;
        }
    }
}
