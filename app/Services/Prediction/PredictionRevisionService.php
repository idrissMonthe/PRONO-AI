<?php

namespace App\Services\Prediction;

use App\Services\AIExplanation\GeminiExplanationService;

/**
 * Réviseur local sans appel externe. Il ne fabrique jamais de probabilité :
 * toutes les candidates viennent des marchés calculés par Python.
 */
class PredictionRevisionService
{
    public const POLICY_VERSION = 'hybrid_revision_v2';

    public function __construct(private readonly GeminiExplanationService $geminiService) {}

    public function review(array $result, array $context): array
    {
        $candidates = $this->candidatesFromResult($result);
        $initial = $this->initialCandidate($candidates);
        $quality = (float) ($context['quality_score'] ?? 0);
        $hybridMode = strtoupper((string) config('services.hybrid_prediction.mode', 'ASSISTED'));
        // En phase de test, une donnée limitée ne doit plus supprimer la
        // sélection : elle est conservée et explicitement signalée.
        $allowLimitedData = (bool) config('services.prediction.allow_limited_data', true);

        // En mode ASSISTED/ACTIVE, l’IA peut prendre le relais quand Python
        // s’abstient. La proposition reste marquée AI_ESTIMATE et n’est pas
        // transformée en probabilité statistique Python.
        if (($result['abstain'] ?? true) && in_array($hybridMode, ['ASSISTED', 'ACTIVE'], true)) {
            $aiAnalysis = $this->geminiService->analyzeSparseMatch(array_merge($context, [
                'python_status' => 'insufficient_data',
                'python_abstained' => true,
                'python_result' => [
                    'quality' => $result['quality'] ?? [],
                    'warnings' => $result['warnings'] ?? [],
                    'available_markets' => $candidates,
                ],
                'missing_data' => array_values(array_unique(array_merge(
                    $context['missing_data'] ?? [],
                    $result['warnings'] ?? []
                ))),
            ]));
            if (($aiAnalysis['decision'] ?? null) === 'propose' && is_array($aiAnalysis['candidate'] ?? null)) {
                return $this->aiEstimateDecision($aiAnalysis, $initial, $candidates);
            }
        }

        if (! $initial || count($candidates) === 0) {
            return $this->decision('insufficient_data', $initial, null, 'Aucun marché calculable n’est disponible.', ['no_calculable_market'], $candidates);
        }
        if ($quality < 58) {
            if (! $allowLimitedData) {
                return $this->decision('reject', $initial, null, 'La qualité des données est insuffisante pour publier une sélection.', ['data_quality_below_validation_threshold'], $candidates);
            }

            return $this->decision(
                'keep',
                $initial,
                $initial,
                'Qualité de données limitée : la meilleure sélection calculée est conservée et signalée comme telle.',
                ['data_quality_below_validation_threshold', 'limited_data_mode'],
                $candidates,
                'low'
            );
        }

        $aiReview = $this->geminiService->reviewPrediction(array_merge($context, [
            'initial_candidate' => $initial,
            'candidates' => $candidates,
            'python_result' => [
                'expected_goals' => $result['expected_goals'] ?? [],
                'probabilities' => $result['probabilities'] ?? [],
                'warnings' => $result['warnings'] ?? [],
                'quality' => $result['quality'] ?? [],
            ],
        ]));
        if (! ($aiReview['fallback'] ?? true)) {
            $candidate = collect($candidates)->firstWhere('candidate_key', $aiReview['candidate_key'] ?? null);
            if (($aiReview['decision'] ?? null) === 'replace' && ! $candidate) {
                $aiReview['decision'] = 'insufficient_data';
                $aiReview['risk_factors'][] = 'invalid_ai_candidate_rejected';
            }
            if (($aiReview['decision'] ?? null) === 'replace' && $candidate && $candidate['candidate_key'] === $initial['candidate_key']) {
                $aiReview['decision'] = 'keep';
                $candidate = $initial;
            }
            if (in_array($aiReview['decision'] ?? null, ['keep', 'replace'], true) && $candidate) {
                return $this->aiDecision($aiReview, $initial, $candidate, $candidates);
            }
            if (in_array($aiReview['decision'] ?? null, ['reject', 'insufficient_data'], true)) {
                if ($allowLimitedData) {
                    return $this->decision(
                        'keep',
                        $initial,
                        $initial,
                        'La révision externe proposait de rejeter la sélection ; elle est conservée en mode données limitées.',
                        array_values(array_unique(array_merge(
                            $aiReview['risk_factors'] ?? [],
                            ['external_review_rejected_candidate', 'limited_data_mode']
                        ))),
                        $candidates,
                        'low'
                    );
                }

                return $this->aiDecision($aiReview, $initial, null, $candidates);
            }
        }

        $alternatives = collect($candidates)
            ->reject(fn (array $candidate): bool => $candidate['candidate_key'] === $initial['candidate_key'])
            ->filter(fn (array $candidate): bool => $candidate['probability'] >= 0.55)
            ->map(function (array $candidate) use ($context): array {
                $marketBonus = in_array($candidate['market'], ['double_chance', 'totals', 'btts'], true) ? 0.02 : 0.0;
                $valueBonus = 0.0;
                if (isset($context['market_probabilities'][$candidate['selection']]) && is_numeric($context['market_probabilities'][$candidate['selection']])) {
                    $valueBonus = max(0.0, $candidate['probability'] - (float) $context['market_probabilities'][$candidate['selection']]) * 0.15;
                }
                $candidate['review_score'] = $candidate['probability'] * 0.82 + $marketBonus + $valueBonus;
                return $candidate;
            })
            ->sortByDesc('review_score')
            ->values();
        $bestAlternative = $alternatives->first();

        if ($bestAlternative && $bestAlternative['review_score'] >= ($initial['probability'] * 0.82 + 0.055)) {
            return $this->decision(
                'replace',
                $initial,
                $bestAlternative,
                'Le marché alternatif calculé présente un meilleur compromis probabiliste selon les données disponibles.',
                ['higher_validated_market_score'],
                $candidates,
                'medium'
            );
        }

        if ($initial['probability'] < 0.40) {
            if ($allowLimitedData) {
                return $this->decision(
                    'keep',
                    $initial,
                    $initial,
                    'La sélection initiale a une probabilité faible ; elle est conservée et signalée en mode données limitées.',
                    ['low_initial_probability', 'limited_data_mode'],
                    $candidates,
                    'low'
                );
            }

            return $this->decision('reject', $initial, null, 'La sélection initiale ne franchit pas le seuil de solidité et aucune alternative ne la dépasse.', ['low_initial_probability'], $candidates);
        }

        return $this->decision('keep', $initial, $initial, 'La sélection initiale reste la meilleure candidate validée.', ['initial_candidate_remains_best'], $candidates);
    }

    public function candidatesFromResult(array $result): array
    {
        $markets = $result['markets'] ?? [];
        $candidates = [];
        $add = function (string $market, string $selection, string $label, $probability, ?float $line = null) use (&$candidates): void {
            if (! is_numeric($probability)) return;
            $probability = (float) $probability;
            if ($probability <= 0 || $probability >= 1) return;
            $candidates[] = [
                'candidate_key' => $market.':'.$selection.($line !== null ? ':'.$line : ''),
                'market' => $market,
                'selection' => $selection,
                'label' => $label,
                'line' => $line,
                'probability' => round($probability, 4),
            ];
        };

        foreach (['home' => 'Victoire domicile', 'draw' => 'Match nul', 'away' => 'Victoire extérieur'] as $key => $label) {
            $add('1N2', $key, $label, $markets['1N2'][$key] ?? null);
        }
        foreach (($markets['double_chance'] ?? []) as $key => $probability) $add('double_chance', (string) $key, (string) $key, $probability);
        foreach (($markets['totals'] ?? []) as $key => $probability) {
            if (preg_match('/^(over|under)_(\d+)_(\d+)$/', (string) $key, $parts)) $add('totals', (string) $key, $parts[1].' '.$parts[2].','.$parts[3].' buts', $probability, (float) ($parts[2].'.'.$parts[3]));
        }
        foreach (['yes' => 'Les deux équipes marquent', 'no' => 'Au moins une équipe ne marque pas'] as $key => $label) $add('btts', $key, $label, $markets['btts'][$key] ?? null);
        foreach (($markets['top_scores'] ?? $result['top_scores'] ?? []) as $score) $add('exact_score', (string) ($score['score'] ?? ''), 'Score exact '.($score['score'] ?? ''), $score['p'] ?? null);
        return $candidates;
    }

    private function initialCandidate(array $candidates): ?array
    {
        return collect($candidates)->where('market', '1N2')->sortByDesc('probability')->first()
            ?? collect($candidates)->sortByDesc('probability')->first();
    }

    private function decision(string $decision, ?array $initial, ?array $final, string $reasoning, array $risks, array $candidates, string $confidence = 'low'): array
    {
        return [
            'decision' => $decision,
            'initial_candidate' => $initial,
            'final_candidate' => $final,
            'reasoning' => $reasoning,
            'supporting_factors' => $final ? ['candidate_calculated_by_python'] : [],
            'risk_factors' => $risks,
            'confidence_in_revision' => $confidence,
            'required_recalculation' => $decision === 'replace',
            'candidates' => $candidates,
            'provider' => 'local_revision_rules',
            'model' => self::POLICY_VERSION,
        ];
    }

    private function aiDecision(array $review, array $initial, ?array $final, array $candidates): array
    {
        $provider = $review['provider'] ?? 'google_gemini';
        return [
            'decision' => $review['decision'],
            'initial_candidate' => $initial,
            'final_candidate' => $final,
            'prediction_origin' => $provider === 'google_gemini' ? 'AI_ASSISTED_PYTHON' : 'PYTHON_ESTIMATE',
            'source_model' => $review['model'] ?? 'python',
            'reasoning' => $review['reasoning'] ?? '',
            'supporting_factors' => $review['supporting_factors'] ?? [],
            'risk_factors' => $review['risk_factors'] ?? [],
            'confidence_in_revision' => $review['confidence_in_revision'] ?? 'low',
            'required_recalculation' => (bool) ($review['required_recalculation'] ?? false),
            'candidates' => $candidates,
            'provider' => $provider,
            'model' => $review['model'] ?? null,
            'ai_response' => $review,
        ];
    }

    private function aiEstimateDecision(array $analysis, ?array $initial, array $candidates): array
    {
        return [
            'decision' => 'keep',
            'initial_candidate' => $initial,
            'final_candidate' => $analysis['candidate'],
            'prediction_origin' => 'AI_ESTIMATE',
            'source_model' => $analysis['model'] ?? 'gemini',
            'ai_estimate' => $analysis,
            'reasoning' => $analysis['reasoning'] ?? '',
            'supporting_factors' => $analysis['supporting_factors'] ?? [],
            'risk_factors' => $analysis['risk_factors'] ?? [],
            'missing_data' => $analysis['missing_data'] ?? [],
            'confidence_in_revision' => $analysis['confidence'] ?? 'low',
            'required_recalculation' => false,
            'candidates' => $candidates,
            'provider' => $analysis['provider'] ?? 'google_gemini',
            'model' => $analysis['model'] ?? null,
            'ai_response' => $analysis,
        ];
    }
}
