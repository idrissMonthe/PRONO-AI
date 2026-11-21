<?php

namespace App\Services\Integrity;

use App\Models\Prediction;
use App\Models\PredictionHashChain;
use Illuminate\Support\Facades\DB;

class HashChainService
{
    /**
     * Chains a prediction cryptographically to the ledger (CDC Section 10.1).
     */
    public function chainPrediction(Prediction $prediction): PredictionHashChain
    {
        $lastChain = PredictionHashChain::latest('id')->first();
        $prevHash = $lastChain ? $lastChain->current_hash : str_repeat('0', 64);

        $payloadToHash = json_encode([
            'id' => $prediction->id,
            'match_id' => $prediction->match_id,
            'model_version_id' => $prediction->model_version_id,
            'prob_home' => (string) $prediction->prob_home,
            'prob_draw' => (string) $prediction->prob_draw,
            'prob_away' => (string) $prediction->prob_away,
            'home_lambda' => (string) $prediction->home_lambda,
            'away_lambda' => (string) $prediction->away_lambda,
            'prev_hash' => $prevHash,
        ]);

        $currentHash = hash('sha256', $payloadToHash);

        $prediction->update([
            'content_hash' => $currentHash,
            'prev_hash' => $prevHash,
        ]);

        return PredictionHashChain::create([
            'prediction_id' => $prediction->id,
            'current_hash' => $currentHash,
            'prev_hash' => $prevHash,
            'chained_at_utc' => now()->utc(),
        ]);
    }

    /**
     * Reconstruit le registre dans l'ordre chronologique des prédictions.
     *
     * Les suppressions passées (cascade ou purge) ont pu retirer des blocs au
     * milieu de la chaîne : la seule réparation possible est de recalculer
     * chaque empreinte à partir de l'état actuel des prédictions, puis de
     * réécrire les blocs dans l'ordre de création. L'opération est idempotente.
     *
     * @return array{total:int, chained:int, last_hash:string}
     */
    public function rebuildLedger(): array
    {
        return DB::transaction(function (): array {
            $total = Prediction::count();
            PredictionHashChain::query()->delete();

            $prevHash = str_repeat('0', 64);
            $chained = 0;

            Prediction::query()
                ->select([
                    'id',
                    'match_id',
                    'model_version_id',
                    'prob_home',
                    'prob_draw',
                    'prob_away',
                    'home_lambda',
                    'away_lambda',
                    'created_at',
                ])
                ->orderBy('id')
                ->chunkById(500, function ($predictions) use (&$prevHash, &$chained): void {
                    foreach ($predictions as $prediction) {
                        $currentHash = hash('sha256', json_encode([
                            'id' => $prediction->id,
                            'match_id' => $prediction->match_id,
                            'model_version_id' => $prediction->model_version_id,
                            'prob_home' => (string) $prediction->prob_home,
                            'prob_draw' => (string) $prediction->prob_draw,
                            'prob_away' => (string) $prediction->prob_away,
                            'home_lambda' => (string) $prediction->home_lambda,
                            'away_lambda' => (string) $prediction->away_lambda,
                            'prev_hash' => $prevHash,
                        ]));

                        Prediction::whereKey($prediction->id)->update([
                            'content_hash' => $currentHash,
                            'prev_hash' => $prevHash,
                        ]);

                        PredictionHashChain::create([
                            'prediction_id' => $prediction->id,
                            'current_hash' => $currentHash,
                            'prev_hash' => $prevHash,
                            'chained_at_utc' => $prediction->created_at?->utc() ?? now()->utc(),
                        ]);

                        $prevHash = $currentHash;
                        $chained++;
                    }
                });

            return [
                'total' => $total,
                'chained' => $chained,
                'last_hash' => $prevHash,
            ];
        });
    }

    /**
     * Verifies the entire cryptographic hash chain integrity for the Diagnostic Center.
     */
    public function verifyLedgerIntegrity(): array
    {
        $totalBlocks = PredictionHashChain::count();
        if ($totalBlocks === 0) {
            return [
                'status' => 'valid',
                'total_blocks' => 0,
                'message' => 'Aucune prédiction enregistrée dans la chaîne.',
                'broken_at_id' => null,
            ];
        }

        // La vérification est incrémentale : charger toutes les prédictions en
        // une fois (avec leurs JSON de marchés, jusqu'à plusieurs centaines de
        // Ko chacun) épuisait la mémoire du processus web. Seules les colonnes
        // intervenant dans l'empreinte sont lues, par paquets bornés.
        $expectedPrev = str_repeat('0', 64);
        $failure = null;

        PredictionHashChain::query()
            ->select(['id', 'prediction_id', 'current_hash', 'prev_hash'])
            ->orderBy('id', 'asc')
            ->chunkById(200, function ($chains) use (&$expectedPrev, &$failure, $totalBlocks) {
                $predictions = Prediction::query()
                    ->whereIn('id', $chains->pluck('prediction_id')->all())
                    ->get([
                        'id',
                        'match_id',
                        'model_version_id',
                        'prob_home',
                        'prob_draw',
                        'prob_away',
                        'home_lambda',
                        'away_lambda',
                        'content_hash',
                        'prev_hash',
                    ])
                    ->keyBy('id');

                foreach ($chains as $chain) {
                    if ($chain->prev_hash !== $expectedPrev) {
                        $failure = [
                            'status' => 'compromised',
                            'total_blocks' => $totalBlocks,
                            'message' => "Rupture de chaîne détectée au bloc #{$chain->id}.",
                            'broken_at_id' => $chain->id,
                        ];

                        return false;
                    }

                    $prediction = $predictions->get($chain->prediction_id);
                    if (! $prediction) {
                        $failure = [
                            'status' => 'compromised',
                            'total_blocks' => $totalBlocks,
                            'message' => "Prédiction manquante pour le bloc #{$chain->id}.",
                            'broken_at_id' => $chain->id,
                        ];

                        return false;
                    }

                    if ($prediction->content_hash !== $chain->current_hash || $prediction->prev_hash !== $chain->prev_hash) {
                        $failure = [
                            'status' => 'compromised',
                            'total_blocks' => $totalBlocks,
                            'message' => "Empreinte incohérente entre la prédiction et le bloc #{$chain->id}.",
                            'broken_at_id' => $chain->id,
                        ];

                        return false;
                    }

                    $payload = json_encode([
                        'id' => $prediction->id,
                        'match_id' => $prediction->match_id,
                        'model_version_id' => $prediction->model_version_id,
                        'prob_home' => (string) $prediction->prob_home,
                        'prob_draw' => (string) $prediction->prob_draw,
                        'prob_away' => (string) $prediction->prob_away,
                        'home_lambda' => (string) $prediction->home_lambda,
                        'away_lambda' => (string) $prediction->away_lambda,
                        'prev_hash' => $expectedPrev,
                    ]);

                    $recalculated = hash('sha256', $payload);
                    if ($recalculated !== $chain->current_hash) {
                        $failure = [
                            'status' => 'compromised',
                            'total_blocks' => $totalBlocks,
                            'message' => "Altération rétroactive détectée sur le bloc #{$chain->id}.",
                            'broken_at_id' => $chain->id,
                        ];

                        return false;
                    }

                    $expectedPrev = $chain->current_hash;
                }

                return true;
            });

        if ($failure !== null) {
            return $failure;
        }

        return [
            'status' => 'valid',
            'total_blocks' => $totalBlocks,
            'message' => "Chaîne cryptographique vérifiée avec succès. {$totalBlocks} prédiction(s) intègre(s).",
            'broken_at_id' => null,
        ];
    }
}

