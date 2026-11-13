<?php

namespace App\Console\Commands;

use App\Services\FootballData\FootballDataService;
use App\Models\SystemAlert;
use App\Services\Prediction\PredictionSettlementService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class SyncMatchesCommand extends Command
{
    /**
     * Commande : oracle:sync-matches
     * Synchronise les matchs depuis football-data.org dans la base de données.
     */
    protected $signature = 'oracle:sync-matches
                            {--date= : Date cible au format Y-m-d (Africa/Douala). Si omis, utilise J-3 à J+7.}
                            {--days-back=3 : Nombre de jours en arrière à synchroniser.}
                            {--days-ahead=7 : Nombre de jours en avant à synchroniser.}';

    protected $description = 'Synchronise les matchs depuis football-data.org. Source de vérité unique pour Oracle Foot.';

    public function __construct(
        private readonly FootballDataService $footballDataService
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->info('🔄 Oracle Foot — Synchronisation des matchs depuis football-data.org');
        $this->info('Fuseau horaire : Africa/Douala (UTC+1)');
        $this->newLine();

        // Calcul de la plage de dates
        $tz       = 'Africa/Douala';
        $now      = Carbon::now($tz);
        $dateArg  = $this->option('date');

        if ($dateArg) {
            try {
                $targetDate = Carbon::createFromFormat('Y-m-d', $dateArg, $tz);
                $dateFrom   = $targetDate->copy()->toDateString();
                $dateTo     = $targetDate->copy()->toDateString();
                $this->info("Date cible : {$dateFrom}");
            } catch (Throwable $e) {
                $this->error("Format de date invalide : {$dateArg}. Attendu : Y-m-d");
                return self::FAILURE;
            }
        } else {
            $daysBack   = (int) ($this->option('days-back') ?? 3);
            $daysAhead  = (int) ($this->option('days-ahead') ?? 7);
            $dateFrom   = $now->copy()->subDays($daysBack)->toDateString();
            $dateTo     = $now->copy()->addDays($daysAhead)->toDateString();
            $this->info("Plage : {$dateFrom} → {$dateTo}");
        }

        $this->newLine();
        $startTime = microtime(true);

        try {
            $result = $this->footballDataService->syncFromFootballDataOrg($dateFrom, $dateTo);
            $settled = app(PredictionSettlementService::class)->settleFinishedPredictions();
            $result['evaluations'] = $settled;

            $elapsed = round((microtime(true) - $startTime) * 1000);

            $this->table(
                ['Indicateur', 'Valeur'],
                [
                    ['Fournisseur',       $result['provider']],
                    ['Matchs reçus',      $result['received']],
                    ['Importés/Mis à jour', $result['imported']],
                    ['Rejetés/Quarantaine', $result['rejected']],
                    ['De',                $result['date_from']],
                    ['À',                 $result['date_to']],
                    ['Durée',             "{$elapsed} ms"],
                    ['Évaluations',       $settled],
                ]
            );

            Log::info('oracle:sync-matches completed', $result);
            SystemAlert::resolve('provider.football-data.org.unavailable');

            // 0 match n'est pas une erreur technique, mais ce n'est pas une
            // réussite silencieuse : la fenêtre peut être une trêve.
            if ((int) $result['received'] === 0) {
                SystemAlert::raise(
                    'provider.football-data.org.empty',
                    "Aucun match reçu pour la fenêtre {$result['date_from']} → {$result['date_to']} (trêve, hors saison ou compétitions non couvertes).",
                    'warning',
                    'football-data.org'
                );
                $this->warn('⚠️  Aucune rencontre reçue sur cette fenêtre : les données existantes sont conservées.');
            } else {
                SystemAlert::resolve('provider.football-data.org.empty');
            }

            if ($result['rejected'] > 0) {
                $this->warn("⚠️  {$result['rejected']} match(s) rejeté(s) → voir la table data_quarantine.");
            }

            $this->newLine();
            $this->info('✅ Synchronisation terminée avec succès.');
            return self::SUCCESS;

        } catch (RuntimeException $e) {
            SystemAlert::raise('provider.football-data.org.unavailable', 'football-data.org est indisponible : '.$e->getMessage(), 'critical', 'football-data.org');
            $this->error('❌ Erreur lors de la synchronisation : ' . $e->getMessage());
            $this->warn('Les données existantes en base sont conservées telles quelles.');
            Log::error('oracle:sync-matches failed', [
                'error'     => $e->getMessage(),
                'date_from' => $dateFrom,
                'date_to'   => $dateTo,
            ]);
            return self::FAILURE;
        } catch (Throwable $e) {
            SystemAlert::raise('provider.football-data.org.unavailable', 'Erreur football-data.org : '.$e->getMessage(), 'critical', 'football-data.org');
            $this->error('❌ Erreur inattendue : ' . $e->getMessage());
            Log::error('oracle:sync-matches unexpected error', [
                'exception' => $e::class,
                'message'   => $e->getMessage(),
                'trace'     => $e->getTraceAsString(),
            ]);
            return self::FAILURE;
        }
    }
}
