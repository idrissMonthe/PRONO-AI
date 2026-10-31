<?php

namespace App\Console\Commands;

use App\Services\Parlay\ParlayService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class GenerateParlaysCommand extends Command
{
    /**
     * Commande : oracle:generate-parlays
     * Génère les combinés du jour à partir des vraies données de matchs et prédictions.
     */
    protected $signature = 'oracle:generate-parlays
                            {--date= : Date cible au format Y-m-d (Africa/Douala). Défaut : aujourd\'hui.}
                            {--force : Force la régénération même si des combinés existent déjà pour cette date.}';

    protected $description = 'Génère les combinés probabilistes du jour. Utilise uniquement des matchs réels (football-data.org).';

    public function __construct(
        private readonly ParlayService $parlayService
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->info('🎯 Oracle Foot — Génération des combinés du jour');
        $this->newLine();

        $tz      = 'Africa/Douala';
        $now     = Carbon::now($tz);
        $dateArg = $this->option('date');

        if ($dateArg) {
            try {
                $targetDate = Carbon::createFromFormat('Y-m-d', $dateArg, $tz)->startOfDay();
            } catch (Throwable $e) {
                $this->error("Format de date invalide : {$dateArg}. Attendu : Y-m-d");
                return self::FAILURE;
            }
        } else {
            $targetDate = $now->copy()->startOfDay();
        }

        $this->info("Date cible : {$targetDate->toDateString()} ({$tz})");
        $this->newLine();

        try {
            if ($this->option('force')) {
                $this->warn('Option --force : régénération forcée.');
                $parlays = $this->parlayService->generateDailyParlays($targetDate);
            } else {
                $parlays = $this->parlayService->getOrGenerateDailyParlays($targetDate);
            }

            if ($parlays->isEmpty()) {
                $this->warn('⚠️  Aucun combiné généré — données insuffisantes ou pas assez de matchs éligibles.');
                $this->warn('   Conditions requises : minimum 2 matchs avec prédiction officielle et cotes réelles.');
                Log::warning('oracle:generate-parlays — no parlays generated', [
                    'date' => $targetDate->toDateString(),
                ]);
                return self::SUCCESS;
            }

            $this->info("✅ {$parlays->count()} combiné(s) généré(s) :");
            $this->newLine();

            foreach ($parlays as $parlay) {
                $this->table(
                    ['Champ', 'Valeur'],
                    [
                        ['Titre',            $parlay->title],
                        ['Niveau de risque', $parlay->risk_level],
                        ['Score de risque',  $parlay->risk_score . '/10'],
                        ['Cote combinée',    'x' . $parlay->combined_odds],
                        ['Probabilité',      round($parlay->combined_prob * 100, 1) . '%'],
                        ['Valeur attendue',  round($parlay->expected_value * 100, 2) . '%'],
                        ['Statut',           $parlay->status],
                    ]
                );
            }

            Log::info('oracle:generate-parlays completed', [
                'date'  => $targetDate->toDateString(),
                'count' => $parlays->count(),
            ]);

            return self::SUCCESS;

        } catch (Throwable $e) {
            $this->error('❌ Erreur lors de la génération : ' . $e->getMessage());
            Log::error('oracle:generate-parlays failed', [
                'exception' => $e::class,
                'message'   => $e->getMessage(),
                'date'      => $targetDate->toDateString(),
            ]);
            return self::FAILURE;
        }
    }
}
