<?php

namespace App\Console\Commands;

use App\Models\Prediction;
use App\Models\PredictionHashChain;
use App\Services\Integrity\HashChainService;
use Illuminate\Console\Command;

class RebuildIntegrityChainCommand extends Command
{
    protected $signature = 'oracle:rebuild-integrity-chain
                            {--dry-run : Compte les prédictions sans réécrire la chaîne}';

    protected $description = 'Reconstruit la chaîne d’intégrité dans l’ordre chronologique des prédictions.';

    public function handle(HashChainService $hashChainService): int
    {
        $predictions = Prediction::count();
        $blocks = PredictionHashChain::count();

        if ($this->option('dry-run')) {
            $this->table(
                ['Prédictions', 'Blocs actuels'],
                [[$predictions, $blocks]]
            );
            $this->info('Mode dry-run : aucun bloc n’a été réécrit.');

            return self::SUCCESS;
        }

        $result = $hashChainService->rebuildLedger();

        $this->table(
            ['Prédictions', 'Blocs réécrits', 'Dernier hash'],
            [[$result['total'], $result['chained'], $result['last_hash']]]
        );
        $this->info("Chaîne d’intégrité reconstruite : {$result['chained']} bloc(s).");

        return self::SUCCESS;
    }
}
