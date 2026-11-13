<?php

namespace App\Console\Commands;

use App\Models\SystemAlert;
use App\Services\FootballData\ApiFootballService;
use App\Services\Prediction\PredictionSettlementService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Throwable;

class SyncApiFootballCommand extends Command
{
    protected $signature = 'oracle:sync-api-football
                            {--live : Synchronise uniquement les matchs en cours avec une requête live globale}
                            {--date= : Date cible au format Y-m-d}
                            {--days-ahead=14 : Nombre de jours à venir pour la synchronisation des fixtures}
                            {--refresh-catalogue : Force la redécouverte du catalogue de compétitions}';

    protected $description = 'Complète la couverture de football-data.org (CAN, compétitions internationales, championnats non couverts) via API-Football.';

    public function handle(ApiFootballService $service): int
    {
        try {
            if ($this->option('refresh-catalogue')) {
                $service->catalog(true);
            }

            if ($this->option('live')) {
                $result = $service->syncLive();
            } else {
                $date = $this->option('date');
                $from = $date ?: Carbon::now('Africa/Douala')->subDays(3)->toDateString();
                $to = $date
                    ?: Carbon::now('Africa/Douala')->addDays((int) $this->option('days-ahead'))->toDateString();
                $result = $service->syncFixtures($from, $to);
            }

            $result['evaluations'] = app(PredictionSettlementService::class)->settleFinishedPredictions();
            SystemAlert::resolve('provider.api-football.unavailable');
            $this->info(sprintf(
                'API-Football (%s) : %d compétition(s), %d reçu(s), %d importé(s), %d rejeté(s).',
                $result['mode'] ?? 'fixtures',
                count($result['competitions'] ?? []),
                $result['received'],
                $result['imported'],
                $result['rejected']
            ));

            if (($result['failed'] ?? 0) > 0) {
                $this->warn(sprintf(
                    '%d compétition(s) en erreur ; les autres résultats ont été conservés.',
                    $result['failed']
                ));

                foreach (($result['errors'] ?? []) as $competition => $error) {
                    $this->line("- {$competition} : {$error}");
                }
            }

            if (($result['quota_exhausted'] ?? false) === true) {
                $this->warn('Quota quotidien API-Football atteint : les compétitions restantes seront reprises à la prochaine synchronisation.');
            }

            if (($result['received'] ?? 0) === 0 && ($result['failed'] ?? 0) === 0) {
                $this->warn('Aucune rencontre reçue sur la fenêtre demandée (trêve, hors saison ou compétitions sans match).');
            }

            // Une synchronisation dont toutes les compétitions échouent ne doit
            // jamais être rapportée comme un succès.
            if (($result['succeeded'] ?? 0) === 0 && ($result['failed'] ?? 0) > 0) {
                SystemAlert::raise(
                    'provider.api-football.unavailable',
                    'API-Football n’a fourni aucune compétition exploitable : '.implode(' | ', array_slice((array) ($result['errors'] ?? []), 0, 3)),
                    'critical',
                    'api-football'
                );
                $this->error('Aucune compétition synchronisée : voir les erreurs ci-dessus.');

                return self::FAILURE;
            }

            return self::SUCCESS;
        } catch (Throwable $exception) {
            SystemAlert::raise(
                'provider.api-football.unavailable',
                'API-Football est indisponible : '.$exception->getMessage(),
                'critical',
                'api-football'
            );
            $this->error($exception->getMessage());
            return self::FAILURE;
        }
    }
}
