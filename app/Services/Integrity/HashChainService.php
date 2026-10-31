<?php

namespace App\Services\Integrity;

use App\Models\Prediction;
use App\Models\PredictionHashChain;

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
     * Verifies the entire cryptographic hash chain integrity for the Diagnostic Center.
     */
    public function verifyLedgerIntegrity(): array
    {
        $chains = PredictionHashChain::with('prediction')->orderBy('id', 'asc')->get();
        if ($chains->isEmpty()) {
            return [
                'status' => 'valid',
                'total_blocks' => 0,
                'message' => 'Aucune prédiction enregistrée dans la chaîne.',
                'broken_at_id' => null,
            ];
        }

        $expectedPrev = str_repeat('0', 64);
        foreach ($chains as $chain) {
            if ($chain->prev_hash !== $expectedPrev) {
                return [
                    'status' => 'compromised',
                    'total_blocks' => $chains->count(),
                    'message' => "Rupture de chaîne détectée au bloc #{$chain->id}.",
                    'broken_at_id' => $chain->id,
                ];
            }

            $prediction = $chain->prediction;
            if (!$prediction) {
                return [
                    'status' => 'compromised',
                    'total_blocks' => $chains->count(),
                    'message' => "Prédiction manquante pour le bloc #{$chain->id}.",
                    'broken_at_id' => $chain->id,
                ];
            }

            if ($prediction->content_hash !== $chain->current_hash || $prediction->prev_hash !== $chain->prev_hash) {
                return [
                    'status' => 'compromised',
                    'total_blocks' => $chains->count(),
                    'message' => "Empreinte incohérente entre la prédiction et le bloc #{$chain->id}.",
                    'broken_at_id' => $chain->id,
                ];
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
                return [
                    'status' => 'compromised',
                    'total_blocks' => $chains->count(),
                    'message' => "Altération rétroactive détectée sur le bloc #{$chain->id}.",
                    'broken_at_id' => $chain->id,
                ];
            }

            $expectedPrev = $chain->current_hash;
        }

        return [
            'status' => 'valid',
            'total_blocks' => $chains->count(),
            'message' => "Chaîne cryptographique vérifiée avec succès. {$chains->count()} prédiction(s) intègre(s).",
            'broken_at_id' => null,
        ];
    }
}

