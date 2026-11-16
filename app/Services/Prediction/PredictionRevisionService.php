<?php

namespace App\Services\Prediction;

use App\Services\AIExplanation\GeminiExplanationService;

/**
 * Réviseur local sans appel externe. Il ne fabrique jamais de probabilité :
 * toutes les candidates viennent des marchés calculés par Python.
 */
class PredictionRevisionService
{
    public const POLICY_VERSION = 'hybrid_revision_v3';

    public function __construct(private readonly GeminiExplanationService $geminiService) {}

    public function review(array $result, array $context): array
    {
        $oddsContext = $context['odds_context'] ?? [];
        $candidates = $this->candidatesFromResult($result, $oddsContext);
        $initial = $this->initialCandidate($candidates);
        $quality = (float) ($context['quality_score'] ?? 0);
        $hybridMode = strtoupper((string) config('services.hybrid_prediction.mode', 'ASSISTED'));

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
            return $this->decision(
                'keep',
                $initial,
                $initial,
                'Qualité de données limitée : la meilleure sélection calculée est publiée avec une confiance basse.',
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
                $retry = $this->geminiService->reviewPrediction(array_merge($context, [
                    'initial_candidate' => $initial,
                    'candidates' => $candidates,
                    'python_result' => [
                        'expected_goals' => $result['expected_goals'] ?? [],
                        'probabilities' => $result['probabilities'] ?? [],
                        'warnings' => $result['warnings'] ?? [],
                        'quality' => $result['quality'] ?? [],
                    ],
                    'correction_request' => 'Choisis obligatoirement decision=keep ou decision=replace avec une candidate_key existante. Ne réponds pas reject ni insufficient_data : un match traité doit recevoir un pronostic.',
                ]));
                if (! ($retry['fallback'] ?? true)) {
                    $retried = collect($candidates)->firstWhere('candidate_key', $retry['candidate_key'] ?? null);
                    if (($retry['decision'] ?? null) === 'keep') {
                        $retried = $initial;
                    }
                    if (in_array($retry['decision'] ?? null, ['keep', 'replace'], true) && $retried) {
                        return $this->aiDecision($retry, $initial, $retried, $candidates);
                    }
                }

                return $this->decision(
                    'keep',
                    $initial,
                    $initial,
                    'Gemini n’a pas retenu de sélection exploitable. Mode dégradé : la sélection statistique initiale est publiée et explicitement signalée.',
                    array_values(array_unique(array_merge($aiReview['risk_factors'] ?? [], ['degraded_mode', 'gemini_declined_selection']))),
                    $candidates,
                    'low'
                );
            }
        }

        $initialOdds = (float) ($initial['odds'] ?? 1.30);
        $initialProb = (float) ($initial['probability'] ?? 0.50);
        $initialTooLow = $initialOdds < 1.25;

        $alternatives = collect($candidates)
            ->reject(fn (array $candidate): bool => $candidate['candidate_key'] === $initial['candidate_key'])
            ->filter(fn (array $candidate): bool => (float) ($candidate['probability'] ?? 0) >= 0.52)
            ->map(function (array $candidate) use ($context): array {
                $prob = (float) $candidate['probability'];
                $odds = (float) ($candidate['odds'] ?? 1.40);

                // Diversification : on ne privilégie plus la double chance,
                // qui écrasait tous les autres marchés à fiabilité comparable.
                $marketBonus = $this->marketDiversityBonus((string) $candidate['market']);

                $valueBonus = 0.0;
                if (isset($context['market_probabilities'][$candidate['selection']]) && is_numeric($context['market_probabilities'][$candidate['selection']])) {
                    $valueBonus = max(0.0, $prob - (float) $context['market_probabilities'][$candidate['selection']]) * 0.15;
                }

                $candidate['review_score'] = $prob * 0.75 + $marketBonus + $valueBonus;
                return $candidate;
            })
            ->sortByDesc('review_score')
            ->values();
        $bestAlternative = $alternatives->first();

        $initialScore = ($initialProb * 0.75) + $this->marketDiversityBonus((string) ($initial['market'] ?? ''));
        $threshold = $initialTooLow ? 0.0 : 0.075;

        // La double chance reste un repli, pas une sélection par défaut :
        // dès qu'un autre marché offre une fiabilité proche, on le préfère.
        if (($initial['market'] ?? null) === 'double_chance') {
            $diverse = $alternatives->first(fn (array $candidate): bool => ($candidate['market'] ?? '') !== 'double_chance'
                && (float) $candidate['probability'] >= max(0.52, $initialProb - 0.12));
            if ($diverse) {
                return $this->decision(
                    'replace',
                    $initial,
                    $diverse,
                    "La double chance était la sélection initiale ; le marché {$diverse['label']} offre une fiabilité équivalente avec une couverture de marchés plus variée.",
                    ['market_diversification', 'comparable_reliability'],
                    $candidates,
                    'medium'
                );
            }
        }

        // Rotation de marché : à fiabilité proche, on préfère le marché tournant
        // dès lors qu'il dispose d'une cote bookmaker réelle.
        $rotated = $this->rotationOverride($initial, $candidates, $context);
        if ($rotated) {
            return $this->decision(
                'replace',
                $initial,
                $rotated,
                "Rotation des marchés : {$rotated['label']} offre une fiabilité proche de la sélection initiale avec une cote bookmaker réelle.",
                ['market_rotation', 'comparable_reliability'],
                $candidates,
                'medium'
            );
        }

        if ($bestAlternative && ($initialTooLow || $bestAlternative['review_score'] >= ($initialScore + $threshold))) {
            $reason = $initialTooLow
                ? "La sélection initiale présentait une cote bookmaker trop faible ({$initialOdds}) sans intérêt de coupon. L’alternative {$bestAlternative['label']} offre une cote abordable ({$bestAlternative['odds']}) avec une solidité statistique validée."
                : 'Le marché alternatif calculé présente un meilleur compromis sécurité / cote selon les données disponibles.';

            return $this->decision(
                'replace',
                $initial,
                $bestAlternative,
                $reason,
                ['higher_validated_market_score', $initialTooLow ? 'initial_odds_unbettable' : 'better_value_alternative'],
                $candidates,
                'medium'
            );
        }

        if ($initial['probability'] < 0.40) {
            return $this->decision(
                'keep',
                $initial,
                $initial,
                'La sélection initiale a une probabilité faible ; elle est publiée avec une confiance basse.',
                ['low_initial_probability', 'limited_data_mode'],
                $candidates,
                'low'
            );
        }

        return $this->decision('keep', $initial, $initial, 'La sélection initiale reste la meilleure candidate validée.', ['initial_candidate_remains_best'], $candidates);
    }

    public function candidatesFromResult(array $result, array $oddsContext = []): array
    {
        $markets = $result['markets'] ?? [];
        $candidates = [];
        $add = function (string $market, string $selection, string $label, $probability, ?float $line = null, ?float $odds = null) use (&$candidates): void {
            if (! is_numeric($probability)) return;
            $probability = (float) $probability;
            if ($probability <= 0 || $probability >= 1) return;

            // Exclusion stricte des lignes absurdes / sans valeur (ex: -5.5 buts à 95%, +0.5 buts à 94%)
            if ($market === 'totals') {
                if (in_array($selection, ['over_0_5', 'under_0_5', 'over_5_5', 'under_5_5', 'under_4_5'], true)) {
                    return;
                }
            }

            // Cote réelle si fournie, sinon estimation explicite (marquée
            // comme telle : elle ne sera jamais affichée comme un prix bookmaker).
            $isBookmakerOdds = $odds !== null && $odds > 1.01;
            if (! $isBookmakerOdds) {
                // Marge bookmaker de ~6% : cote décimale = (1 / prob) * 0.94
                $odds = round(max(1.02, (1.0 / $probability) * 0.94), 2);
            } else {
                $odds = round($odds, 2);
            }

            // Pour le 1N2, on conserve l'issue pour identifier la sélection naturelle initiale.
            // Pour tous les autres marchés alternatifs, les cotes < 1.18 n'ont aucune utilité de coupon.
            if ($market !== '1N2' && $odds < 1.18) {
                return;
            }

            // Garde-fou : quand le modèle et le bookmaker divergent fortement
            // (ex. 91 % face à une cote de 2,04), la sélection est écartée
            // plutôt que publiée comme une certitude.
            if ($isBookmakerOdds && abs($probability - (1.0 / $odds)) > 0.22) {
                return;
            }

            $candidates[] = [
                'candidate_key' => $market.':'.$selection.($line !== null ? ':'.$line : ''),
                'market' => $market,
                'selection' => $selection,
                'label' => $label,
                'line' => $line,
                'probability' => round($probability, 4),
                'odds' => $odds,
                'odds_source' => $isBookmakerOdds ? 'bookmaker' : 'estimated',
                'expected_value' => round(($probability * $odds) - 1.0, 4),
            ];
        };

        // 1N2
        foreach (['home' => 'Victoire domicile', 'draw' => 'Match nul', 'away' => 'Victoire extérieur'] as $key => $label) {
            $prob = $markets['1N2'][$key] ?? null;
            $odd = $oddsContext['1N2'][$key] ?? null;
            $add('1N2', $key, $label, $prob, null, $odd);
        }

        // Double Chance
        $oneN2Odds = $oddsContext['1N2'] ?? [];
        $o1 = (float) ($oneN2Odds['home'] ?? 0);
        $oX = (float) ($oneN2Odds['draw'] ?? 0);
        $o2 = (float) ($oneN2Odds['away'] ?? 0);

        foreach (($markets['double_chance'] ?? []) as $key => $probability) {
            // Prix réel du bookmaker quand il existe, sinon prix dérivé du 1N2.
            $odd = $oddsContext['double_chance'][(string) $key] ?? null;
            if ($odd === null && $key === '1X' && $o1 > 1 && $oX > 1) {
                $odd = 1.0 / ((1.0 / $o1) + (1.0 / $oX));
            } elseif ($odd === null && $key === 'X2' && $oX > 1 && $o2 > 1) {
                $odd = 1.0 / ((1.0 / $oX) + (1.0 / $o2));
            } elseif ($odd === null && $key === '12' && $o1 > 1 && $o2 > 1) {
                $odd = 1.0 / ((1.0 / $o1) + (1.0 / $o2));
            }
            $label = match ((string) $key) {
                '1X' => 'Double chance 1X (Domicile ou Nul)',
                'X2' => 'Double chance X2 (Nul ou Extérieur)',
                '12' => 'Double chance 12 (Pas de nul)',
                default => (string) $key,
            };
            $add('double_chance', (string) $key, $label, $probability, null, $odd);
        }

        // Totaux de buts (lignes 1.5, 2.5, 3.5 réalistes)
        foreach (($markets['totals'] ?? []) as $key => $probability) {
            if (preg_match('/^(over|under)_(\d+)_(\d+)$/', (string) $key, $parts)) {
                $type = $parts[1];
                $line = (float) ($parts[2].'.'.$parts[3]);
                if (! in_array($line, [1.5, 2.5, 3.5], true)) {
                    continue;
                }
                $odd = $oddsContext['totals'][(string) $line][$type] ?? null;
                $label = ($type === 'over' ? 'Plus de ' : 'Moins de ').$parts[2].','.$parts[3].' buts';
                $add('totals', (string) $key, $label, $probability, $line, $odd);
            }
        }

        $homeName = (string) ($oddsContext['home_team'] ?? 'Domicile');
        $awayName = (string) ($oddsContext['away_team'] ?? 'Extérieur');

        foreach (['home' => $homeName, 'away' => $awayName] as $side => $team) {
            $prob = $markets['dnb'][$side] ?? null;
            $label = $side === 'home'
                ? "Draw No Bet {$team}"
                : "Draw No Bet {$team}";
            $add('dnb', $side, $label, $prob);
        }

        foreach (['home' => $homeName, 'away' => $awayName] as $side => $team) {
            foreach (['over', 'under'] as $direction) {
                foreach ([0.5, 1.5, 2.5] as $line) {
                    $teamKey = str_replace('.', '_', (string) $line);
                    $prob = $markets['team_totals'][$side][$direction.'_'.$teamKey] ?? null;
                    $oddsKey = $side.'_'.$direction.'@'.$line;
                    $legacyKey = $side.'.'.$direction.'@'.$line;
                    $odd = $oddsContext['team_totals'][$oddsKey] ?? $oddsContext['team_totals'][$legacyKey] ?? null;
                    $label = ($direction === 'over' ? 'Plus de ' : 'Moins de ').str_replace('.', ',', (string) $line).' buts — '.$team;
                    $add('team_totals', $side.'_'.strtoupper($direction).'_'.$line, $label, $prob, $line, $odd !== null ? (float) $odd : null);
                }
            }
        }

        // BTTS (Les deux équipes marquent)
        foreach (['yes' => 'Les deux équipes marquent', 'no' => 'Au moins une équipe ne marque pas'] as $key => $label) {
            $prob = $markets['btts'][$key] ?? null;
            $odd = $oddsContext['btts'][$key] ?? null;
            $add('btts', $key, $label, $prob, null, $odd);
        }

        // Handicap asiatique : uniquement les lignes pour lesquelles une cote
        // bookmaker réelle existe (aucune cote estimée sur ce marché).
        foreach (($oddsContext['handicap'] ?? []) as $handicap) {
            $side = $handicap['selection'] ?? null;
            if (! in_array($side, ['HOME', 'AWAY'], true) || ! isset($handicap['line'])) {
                continue;
            }
            $rawLine = (float) $handicap['line'];
            $homeLine = $side === 'HOME' ? $rawLine : -$rawLine;
            $entry = $markets['asian_handicap'][$this->handicapKey($homeLine)] ?? null;
            if (! $entry) {
                continue;
            }
            $sideKey = $side === 'HOME' ? 'home' : 'away';
            $prob = (float) ($entry[$sideKey]['win'] ?? 0) + 0.5 * (float) ($entry[$sideKey]['half_win'] ?? 0);
            $team = $side === 'HOME' ? $homeName : $awayName;
            $lineLabel = ($rawLine > 0 ? '+' : '') . rtrim(rtrim(number_format($rawLine, 2, ',', ''), '0'), ',');
            $add('asian_handicap', 'AH_' . $side . '_' . $rawLine, "{$team} handicap {$lineLabel}", $prob, $rawLine, (float) $handicap['odds']);
        }

        return $candidates;
    }

    /**
     * Favorise les marchés délaissés (BTTS, totaux, 1N2) sans jamais
     * favoriser la double chance, trop facile à obtenir avec une forte
     * probabilité et donc envahissante.
     */
    private function marketDiversityBonus(string $market): float
    {
        return match ($market) {
            'btts' => 0.06,
            'totals' => 0.05,
            'asian_handicap' => 0.05,
            'team_totals' => 0.05,
            '1N2' => 0.03,
            default => 0.0,
        };
    }

    /**
     * Choisit un marché tournant fiable, à cote bookmaker réelle, proche de
     * la sélection initiale. Retourne null si aucun marché ne convient.
     */
    private function rotationOverride(array $initial, array $candidates, array $context): ?array
    {
        $preferredMarket = $this->rotatedMarket((string) ($context['match_id'] ?? ''));
        if ($preferredMarket === '1N2' || $preferredMarket === ($initial['market'] ?? '')) {
            return null;
        }

        $initialProb = (float) ($initial['probability'] ?? 0.50);

        return collect($candidates)
            ->reject(fn (array $candidate): bool => ($candidate['candidate_key'] ?? '') === ($initial['candidate_key'] ?? ''))
            ->first(fn (array $candidate): bool => ($candidate['market'] ?? '') === $preferredMarket
                && ($candidate['odds_source'] ?? '') === 'bookmaker'
                && (float) ($candidate['probability'] ?? 0) >= max(0.52, $initialProb - 0.10));
    }

    /**
     * Convertit une ligne de handicap côté domicile en clé du modèle.
     */
    private function handicapKey(float $line): string
    {
        $body = str_replace('.', '_', (string) abs($line));
        if (! str_contains($body, '_')) {
            $body .= '_0';
        }

        return $line < 0 ? 'm' . $body : $body;
    }

    /**
     * Alternance déterministe des marchés pour éviter qu'un seul type
     * (1N2 ou double chance) écrase l'affichage d'un match à l'autre.
     */
    private function rotatedMarket(string $matchId): string
    {
        $markets = ['btts', 'totals', 'team_totals', 'asian_handicap', '1N2'];
        $seed = crc32($matchId);

        return $markets[$seed % count($markets)];
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
