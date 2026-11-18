<?php

namespace App\Services\Prediction;

use App\Models\Prediction;
use App\Services\AIExplanation\GeminiExplanationService;

/**
 * Gemini examine le pronostic Python. Il peut le valider ou proposer une
 * alternative distincte. Il ne remplace jamais le pronostic initial et ne
 * fabrique pas de probabilité Python.
 */
class PredictionRevisionService
{
    public const POLICY_VERSION = 'hybrid_revision_v4';

    public const STATUS_VALIDATED = 'VALIDATED';

    public const STATUS_WITH_ALTERNATIVE = 'NOT_VALIDATED_WITH_ALTERNATIVE';

    public const STATUS_WITHOUT_ALTERNATIVE = 'NOT_VALIDATED_WITHOUT_ALTERNATIVE';

    public const STATUS_INSUFFICIENT = 'INSUFFICIENT_INFORMATION';

    public const STATUS_FAILED = 'REVIEW_FAILED';

    /** Statuts qui ne doivent jamais être présentés comme une validation. */
    public const UNVALIDATED_STATUSES = [
        self::STATUS_WITH_ALTERNATIVE,
        self::STATUS_WITHOUT_ALTERNATIVE,
        self::STATUS_INSUFFICIENT,
        self::STATUS_FAILED,
    ];

    public function __construct(private readonly GeminiExplanationService $geminiService) {}

    public function review(array $result, array $context): array
    {
        $oddsContext = $context['odds_context'] ?? [];
        $candidates = $this->candidatesFromResult($result, $oddsContext);
        $initial = $this->initialCandidate($candidates);
        $quality = (float) ($context['quality_score'] ?? 0);
        $hybridMode = strtoupper((string) config('services.hybrid_prediction.mode', 'ASSISTED'));

        // Python s'abstient : une proposition IA reste une estimation distincte.
        // Elle ne devient pas le pronostic Python.
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
            if (is_array($aiAnalysis) && ($aiAnalysis['decision'] ?? null) === 'propose' && is_array($aiAnalysis['candidate'] ?? null)) {
                return $this->fromSparseProposal($aiAnalysis, $initial, $candidates, $quality);
            }
        }

        if (! $initial || count($candidates) === 0) {
            return $this->assemble(
                self::STATUS_INSUFFICIENT,
                $initial,
                null,
                'Aucun marché calculable n’est disponible. La validation Gemini n’a pas pu être effectuée.',
                ['no_calculable_market'],
                $candidates,
                'low',
                'local_revision_rules',
                self::POLICY_VERSION,
                null
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

        if (! is_array($aiReview) || ($aiReview['fallback'] ?? true)) {
            return $this->assemble(
                self::STATUS_FAILED,
                $initial,
                null,
                'La validation Gemini n’a pas pu être effectuée. L’absence de réponse n’est pas une validation.',
                array_values(array_unique(array_merge(
                    $aiReview['risk_factors'] ?? [],
                    ['ai_review_unavailable'],
                    $this->qualityRisks($quality)
                ))),
                $candidates,
                'low',
                $aiReview['provider'] ?? 'deterministic_fallback',
                $aiReview['model'] ?? self::POLICY_VERSION,
                is_array($aiReview) ? $aiReview : null
            );
        }

        $canonical = $this->canonicalDecision($aiReview);
        $risks = array_values(array_filter((array) ($aiReview['risk_factors'] ?? []), 'is_string'));
        $reasoning = is_string($aiReview['reasoning'] ?? null) && $aiReview['reasoning'] !== ''
            ? $aiReview['reasoning']
            : 'Gemini n’a pas fourni d’explication.';

        if ($canonical === 'validated') {
            return $this->assemble(
                self::STATUS_VALIDATED,
                $initial,
                null,
                $reasoning,
                array_values(array_unique(array_merge($risks, $this->qualityRisks($quality)))),
                $candidates,
                (string) ($aiReview['confidence_in_revision'] ?? 'low'),
                (string) ($aiReview['provider'] ?? 'google_gemini'),
                $aiReview['model'] ?? null,
                $aiReview,
                $aiReview['supporting_factors'] ?? []
            );
        }

        if ($canonical === 'insufficient') {
            return $this->assemble(
                self::STATUS_INSUFFICIENT,
                $initial,
                null,
                $reasoning.' La validation Gemini n’a pas pu être effectuée.',
                array_values(array_unique(array_merge($risks, ['insufficient_information'], $this->qualityRisks($quality)))),
                $candidates,
                (string) ($aiReview['confidence_in_revision'] ?? 'low'),
                (string) ($aiReview['provider'] ?? 'google_gemini'),
                $aiReview['model'] ?? null,
                $aiReview,
                $aiReview['supporting_factors'] ?? []
            );
        }

        if ($canonical === 'failed') {
            return $this->assemble(
                self::STATUS_FAILED,
                $initial,
                null,
                'La validation Gemini n’a pas pu être effectuée. L’absence de réponse n’est pas une validation.',
                array_values(array_unique(array_merge($risks, ['ai_review_unavailable'], $this->qualityRisks($quality)))),
                $candidates,
                'low',
                (string) ($aiReview['provider'] ?? 'deterministic_fallback'),
                $aiReview['model'] ?? self::POLICY_VERSION,
                $aiReview
            );
        }

        $alternative = null;
        if ($canonical === 'not_validated') {
            $alternative = $this->resolveAlternative($this->proposalFromReview($aiReview), $candidates, $initial);
            if ($alternative === null && $this->proposalFromReview($aiReview) !== null) {
                $risks[] = 'invalid_ai_candidate_rejected';
            }
        }

        return $this->assemble(
            $alternative ? self::STATUS_WITH_ALTERNATIVE : self::STATUS_WITHOUT_ALTERNATIVE,
            $initial,
            $alternative,
            $reasoning,
            array_values(array_unique(array_merge($risks, $this->qualityRisks($quality)))),
            $candidates,
            (string) ($aiReview['confidence_in_revision'] ?? 'low'),
            (string) ($aiReview['provider'] ?? 'google_gemini'),
            $aiReview['model'] ?? null,
            $aiReview,
            $aiReview['supporting_factors'] ?? []
        );
    }

    public static function statusLabel(?string $status): string
    {
        return match (strtoupper((string) $status)) {
            self::STATUS_VALIDATED, 'VALIDATED' => 'Validé par Gemini',
            self::STATUS_WITH_ALTERNATIVE, 'NOT_VALIDATED_WITH_ALTERNATIVE' => 'Non validé par Gemini (alternative proposée)',
            self::STATUS_WITHOUT_ALTERNATIVE, 'NOT_VALIDATED_WITHOUT_ALTERNATIVE', 'NOT_VALIDATED' => 'Non validé par Gemini',
            self::STATUS_INSUFFICIENT, 'INSUFFICIENT_INFORMATION', 'INSUFFICIENT_DATA' => 'Non évalué par Gemini (informations insuffisantes)',
            self::STATUS_FAILED, 'FAILED', 'REVIEW_FAILED' => 'Non évalué par Gemini (service indisponible)',
            default => 'Non évalué par Gemini',
        };
    }

    /**
     * Les anciens pronostics sans statut Gemini restent éligibles.
     * Un refus, un manque d'information ou un échec technique ne l'est pas.
     */
    public function pythonPickEligibleForParlay(Prediction $prediction): bool
    {
        $status = (string) ($prediction->review_decision ?? '');

        return ! in_array($status, self::UNVALIDATED_STATUSES, true);
    }

    /**
     * L'alternative n'entre dans un combiné qu'après les contrôles techniques
     * et seulement si la configuration l'autorise. Le pronostic Python validé
     * reste le seul choix lorsqu'il est éligible.
     */
    public function alternativeForParlay(Prediction $prediction): ?array
    {
        if ($prediction->review_decision !== self::STATUS_WITH_ALTERNATIVE) {
            return null;
        }
        if ($this->pythonPickEligibleForParlay($prediction)) {
            return null;
        }

        $alternative = data_get($prediction->market_metrics_json, 'gemini_review.alternative');
        if (! is_array($alternative)) {
            return null;
        }
        if (! (bool) config('services.hybrid_prediction.allow_gemini_alternative_in_parlays', false)) {
            return null;
        }

        $origin = (string) ($alternative['origin'] ?? '');
        if ($origin === 'PYTHON' && ($alternative['technically_valid'] ?? false) && is_numeric($alternative['probability'] ?? null)) {
            return $alternative;
        }

        $allowAi = (bool) config('services.hybrid_prediction.allow_ai_in_parlays', true);
        if ($origin === 'AI_ESTIMATE' && $allowAi && is_numeric($alternative['ai_probability'] ?? null) && ($alternative['market_recognized'] ?? false)) {
            return $alternative;
        }

        return null;
    }

    public static function presentsAsValidated(?string $reviewDecision): bool
    {
        return ! in_array((string) $reviewDecision, self::UNVALIDATED_STATUSES, true);
    }

    public function storedReview(array $review): array
    {
        return [
            'status' => $review['gemini_status'] ?? self::STATUS_FAILED,
            'status_label' => self::statusLabel($review['gemini_status'] ?? null),
            'explanation' => (string) ($review['reasoning'] ?? ''),
            'python' => $this->displayPython($review['python_candidate'] ?? null),
            'alternative' => $this->displayAlternative($review['alternative'] ?? null),
            'published_selection' => $this->displayPython($review['python_candidate'] ?? null),
        ];
    }

    public static function presentationFromPrediction(?Prediction $prediction): ?array
    {
        if (! $prediction) {
            return null;
        }

        $stored = data_get($prediction->market_metrics_json, 'gemini_review');
        if (! is_array($stored) || ! isset($stored['status'])) {
            return null;
        }

        // Le libellé est toujours dérivé du statut, jamais lu tel quel : les
        // revues historiques conservent leur statut mais adoptent le
        // vocabulaire courant (validé / non validé / non évalué).
        $stored['status_label'] = self::statusLabel($stored['status']);

        return $stored;
    }

    public function candidatesFromResult(array $result, array $oddsContext = []): array
    {
        $markets = $result['markets'] ?? [];
        $seenKeys = [];
        $candidates = [];
        $add = function (
            string $market,
            string $selection,
            string $label,
            $probability,
            ?float $line = null,
            ?float $odds = null,
            ?string $family = null,
            ?float $reliability = null,
            ?string $coverage = 'full',
            ?string $marketStatus = 'supported'
        ) use (&$candidates, &$seenKeys): void {
            if (! is_numeric($probability)) return;
            $probability = (float) $probability;
            if ($probability <= 0 || $probability >= 1) return;

            // Exclusion stricte des marchés avec couverture absente ou statut insuffisant/non supporté
            if ($coverage === 'none' || in_array($marketStatus, ['insufficient', 'unsupported'], true)) {
                return;
            }

            $key = $market.':'.$selection.($line !== null ? ':'.$line : '');
            if (isset($seenKeys[$key])) {
                return;
            }
            $seenKeys[$key] = true;

            // Cote réelle si fournie, sinon estimation explicite (marquée
            // comme telle : elle ne sera jamais affichée comme un prix bookmaker).
            $isBookmakerOdds = $odds !== null && $odds > 1.01;
            if (! $isBookmakerOdds) {
                // Marge bookmaker de ~6% : cote décimale = (1 / prob) * 0.94
                $odds = round(max(1.02, (1.0 / $probability) * 0.94), 2);
            } else {
                $odds = round($odds, 2);
                // Désaccord trop fort entre le modèle et le marché : candidat
                // écarté plutôt que publié comme une certitude.
                if (abs($probability - (1.0 / $odds)) > 0.22) {
                    return;
                }
            }

            $candidates[] = [
                'candidate_key' => $key,
                'market' => $market,
                'selection' => $selection,
                'label' => $label,
                'line' => $line,
                'probability' => round($probability, 4),
                'odds' => $odds,
                'odds_source' => $isBookmakerOdds ? 'bookmaker' : 'estimated',
                'expected_value' => $isBookmakerOdds ? round(($probability * $odds) - 1.0, 4) : null,
                'family' => $family ?: $this->marketFamily($market, $selection),
                'reliability' => $reliability !== null ? round((float) $reliability, 4) : 0.65,
                'coverage' => $coverage ?: 'full',
                'market_status' => $marketStatus ?: 'supported',
                'calibration_status' => 'insufficient_sample',
                'high_confidence' => false,
            ];
        };

        // 0. Marchés étendus issus du moteur Python (MarketEngine : 86 marchés)
        if (! empty($result['market_predictions']) && is_array($result['market_predictions'])) {
            foreach ($result['market_predictions'] as $pred) {
                if (! is_array($pred) || empty($pred['market_id']) || empty($pred['selection'])) {
                    continue;
                }
                $mId = (string) $pred['market_id'];
                $sel = (string) $pred['selection'];
                $prob = $pred['probability'] ?? null;
                $line = isset($pred['line']) && is_numeric($pred['line']) ? (float) $pred['line'] : null;
                $pLabel = (string) ($pred['selection_label'] ?? $pred['market_name'] ?? $sel);
                $rel = isset($pred['reliability']) ? (float) $pred['reliability'] : null;
                $cov = isset($pred['coverage']) ? (string) $pred['coverage'] : 'partial';
                $fam = isset($pred['family']) ? (string) $pred['family'] : null;
                $mStatus = isset($pred['market_status']) ? (string) $pred['market_status'] : 'supported';

                $add($mId, $sel, $pLabel, $prob, $line, null, $fam, $rel, $cov, $mStatus);
            }
        }

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

        // Totaux de buts. Les lignes 0,5 à 4,5 viennent de la même matrice.
        // Une ligne n'est publiée que si sa probabilité existe : rien n'est inventé.
        foreach (($markets['totals'] ?? []) as $key => $probability) {
            if (preg_match('/^(over|under)_(\d+)_(\d+)$/', (string) $key, $parts)) {
                $type = $parts[1];
                $line = (float) ($parts[2].'.'.$parts[3]);
                if (! in_array($line, [0.5, 1.5, 2.5, 3.5, 4.5], true)) {
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

        foreach (($markets['asian_totals'] ?? []) as $lineKey => $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $line = (float) ($entry['line'] ?? $lineKey);
            foreach (['over' => 'Plus de ', 'under' => 'Moins de '] as $direction => $prefix) {
                $win = $entry[$direction]['win'] ?? null;
                $push = $entry[$direction]['push'] ?? null;
                if (! is_numeric($win) || ! is_numeric($push)) {
                    continue;
                }
                $selection = $direction.'_'.str_replace('.', '_', number_format($line, 1, '.', ''));
                $label = $prefix.str_replace('.', ',', number_format($line, 1, '.', '')).' buts (remboursement si total exact)';
                $odd = $oddsContext['asian_totals'][(string) $line][$direction] ?? null;
                $before = count($candidates);
                $add('asian_totals', $selection, $label, $win, $line, $odd !== null ? (float) $odd : null);
                if (count($candidates) > $before) {
                    $candidates[array_key_last($candidates)]['push_probability'] = round((float) $push, 4);
                }
            }
        }

        foreach (['home' => $homeName, 'away' => $awayName] as $side => $team) {
            foreach (($markets['asian_team_totals'][$side] ?? []) as $lineKey => $entry) {
                if (! is_array($entry)) {
                    continue;
                }
                $line = (float) ($entry['line'] ?? $lineKey);
                foreach (['over' => 'Plus de ', 'under' => 'Moins de '] as $direction => $prefix) {
                    $win = $entry[$direction]['win'] ?? null;
                    $push = $entry[$direction]['push'] ?? null;
                    if (! is_numeric($win) || ! is_numeric($push)) {
                        continue;
                    }
                    $selection = $side.'_'.strtoupper($direction).'_'.number_format($line, 1, '.', '');
                    $label = $team.' : '.$prefix.str_replace('.', ',', number_format($line, 1, '.', '')).' but (remboursement si total exact)';
                    $before = count($candidates);
                    $add('asian_team_totals', $selection, $label, $win, $line, null);
                    if (count($candidates) > $before) {
                        $candidates[array_key_last($candidates)]['push_probability'] = round((float) $push, 4);
                    }
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
     * Conserve le barème de diversité des marchés. Il ne remplace plus le
     * pronostic Python : une alternative vient uniquement de Gemini, puis
     * passe par les contrôles de marché.
     */
    private function marketDiversityBonus(string $market): float
    {
        return match ($market) {
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

    /**
     * Le pronostic Python est le meilleur candidat calculé, pas forcément le 1N2.
     * Une ligne presque certaine (au-dessus du plafond) ne masque pas un autre
     * marché déjà situé dans la bande de publication. S'il n'y a aucun candidat
     * dans cette bande, la ligne la plus probable reste disponible.
     * Une valeur positive n'est utilisée que lorsqu'une cote bookmaker réelle existe.
     */
    private function initialCandidate(array $candidates): ?array
    {
        if ($candidates === []) {
            return null;
        }

        // Exclusion stricte des marchés sans données, insuffisants ou à fiabilité sous 0.35
        $validCandidates = array_values(array_filter(
            $candidates,
            fn (array $c): bool => ($c['coverage'] ?? 'full') !== 'none'
                && ! in_array($c['market_status'] ?? 'supported', ['insufficient', 'unsupported'], true)
                && (float) ($c['reliability'] ?? 0.50) >= 0.35
        ));

        if ($validCandidates === []) {
            return null;
        }

        // Dépriorisation stricte des cartons/discipline : ne jamais retenir un carton comme
        // pronostic officiel principal lorsqu'un marché de football classique existe.
        $nonCards = array_values(array_filter(
            $validCandidates,
            fn (array $c): bool => ! MarketNormalizationService::isCardsMarket($c['market'] ?? '', $c['family'] ?? null)
        ));
        $eligibleBase = $nonCards !== [] ? $nonCards : $validCandidates;

        $floor = (float) config('services.hybrid_prediction.publish_probability_floor', 0.50);
        $ceiling = (float) config('services.hybrid_prediction.publish_probability_ceiling', 0.88);
        $inBand = array_values(array_filter(
            $eligibleBase,
            fn (array $c): bool => (float) $c['probability'] >= $floor
                && (float) $c['probability'] <= $ceiling
        ));
        $aboveFloor = array_values(array_filter(
            $eligibleBase,
            fn (array $c): bool => (float) $c['probability'] >= $floor
        ));
        $pool = $inBand !== [] ? $inBand : ($aboveFloor !== [] ? $aboveFloor : $eligibleBase);

        // Score multi-critères statistique équitable :
        // 1. Probabilité calibrée (45%)
        // 2. Fiabilité de la famille (35%)
        // 3. Couverture des données (15%)
        // 4. Bonus d'espérance mathématique si cote bookmaker réelle disponible (+EV)
        $scoreFn = function (array $c): float {
            $prob = (float) $c['probability'];
            $rel = (float) ($c['reliability'] ?? 0.65);
            $cov = match ($c['coverage'] ?? 'full') {
                'full' => 1.0,
                'partial' => 0.75,
                'thin' => 0.40,
                default => 0.70,
            };

            $evBonus = 0.0;
            if (($c['odds_source'] ?? '') === 'bookmaker' && is_numeric($c['expected_value'] ?? null)) {
                $ev = (float) $c['expected_value'];
                if ($ev > 0) {
                    $evBonus = min(0.10, $ev * 0.35);
                }
            }

            // Pénalisation des marchés de cartons au cas où aucun autre marché n'est disponible
            $cardPenalty = MarketNormalizationService::isCardsMarket($c['market'] ?? '', $c['family'] ?? null) ? -0.30 : 0.0;

            return (0.45 * $prob) + (0.35 * $rel) + (0.15 * $cov) + $evBonus + $cardPenalty;
        };

        usort($pool, fn (array $a, array $b): int => $scoreFn($b) <=> $scoreFn($a));

        return $this->withReliability($pool[0]);
    }

    /**
     * Aucune famille, y compris le BTTS Non, n'est fiable par son seul libellé.
     * Sans échantillon historique suffisant, la confiance élevée reste refusée.
     */
    private function withReliability(array $candidate): array
    {
        $family = $this->marketFamily((string) $candidate['market'], (string) $candidate['selection']);
        $calibrated = $this->familyCalibrated($family);
        $candidate['market_family'] = $family;
        $candidate['calibration_status'] = $calibrated ? 'calibrated' : 'insufficient_sample';
        $candidate['high_confidence'] = $calibrated && (float) $candidate['probability'] >= 0.60;
        $candidate['calibration_label'] = $calibrated
            ? 'Calibration historique disponible'
            : 'Non suffisamment calibré';

        return $candidate;
    }

    public function marketFamily(string $market, string $selection): string
    {
        return match ($market) {
            '1N2', 'dnb' => '1X2',
            'double_chance' => $selection === '12' ? '12' : '1X2',
            'btts' => $selection === 'no' ? 'BTTS Non' : 'BTTS Oui',
            'totals' => 'Over/Under',
            'team_totals', 'asian_team_totals' => 'Buts équipe',
            'asian_totals', 'asian_handicap' => 'Ligne asiatique',
            default => $market,
        };
    }

    public function familyCalibrated(string $family): bool
    {
        if ($family === '' || ! \Illuminate\Support\Facades\Schema::hasTable('prediction_evaluations')) {
            return false;
        }

        $minimum = max(1, (int) config('services.hybrid_prediction.min_calibration_sample', 30));

        return \App\Models\PredictionEvaluation::query()
            ->where('market_group', $family)
            ->whereIn('status', ['WON', 'LOST'])
            ->count() >= $minimum;
    }

    private function fromSparseProposal(array $analysis, ?array $initial, array $candidates, float $quality): array
    {
        $proposal = is_array($analysis['candidate'] ?? null) ? $analysis['candidate'] : null;
        if (is_array($proposal)) {
            $proposal['justification'] = $analysis['reasoning'] ?? '';
            $proposal['risk_factors'] = $analysis['risk_factors'] ?? [];
            if (! isset($proposal['probability']) && isset($analysis['candidate']['probability'])) {
                $proposal['probability'] = $analysis['candidate']['probability'];
            }
        }
        $alternative = $this->resolveAlternative($proposal, $candidates, $initial);
        $risks = array_values(array_unique(array_merge(
            array_filter((array) ($analysis['risk_factors'] ?? []), 'is_string'),
            $alternative ? [] : ['invalid_ai_candidate_rejected'],
            $this->qualityRisks($quality)
        )));

        return $this->assemble(
            $alternative ? self::STATUS_WITH_ALTERNATIVE : self::STATUS_WITHOUT_ALTERNATIVE,
            $initial,
            $alternative,
            (string) ($analysis['reasoning'] ?? ''),
            $risks,
            $candidates,
            (string) ($analysis['confidence'] ?? 'low'),
            (string) ($analysis['provider'] ?? 'google_gemini'),
            $analysis['model'] ?? null,
            $analysis,
            $analysis['supporting_factors'] ?? [],
            $analysis['missing_data'] ?? []
        );
    }

    private function assemble(
        string $status,
        ?array $python,
        ?array $alternative,
        string $reasoning,
        array $risks,
        array $candidates,
        string $confidence,
        string $provider,
        ?string $model,
        ?array $aiResponse,
        array $supporting = [],
        array $missing = []
    ): array {
        $python = $python ? $this->withPythonOrigin($python) : null;

        return [
            'decision' => $status,
            'gemini_status' => $status,
            'initial_candidate' => $python,
            'python_candidate' => $python,
            'alternative' => $alternative,
            'final_candidate' => $python,
            'published_selection' => $python,
            'prediction_origin' => 'PYTHON_ESTIMATE',
            'source_model' => $model,
            'ai_estimate' => ($alternative['origin'] ?? null) === 'AI_ESTIMATE' ? $alternative : null,
            'reasoning' => $reasoning,
            'supporting_factors' => array_values(array_filter($supporting, 'is_string')),
            'risk_factors' => array_values(array_unique($risks)),
            'missing_data' => array_values(array_filter($missing, 'is_string')),
            'confidence_in_revision' => in_array($confidence, ['low', 'medium', 'high'], true) ? $confidence : 'low',
            'required_recalculation' => ($alternative['origin'] ?? null) === 'PYTHON',
            'parlay_eligible' => $status === self::STATUS_VALIDATED && $python !== null,
            'candidates' => $candidates,
            'provider' => $provider,
            'model' => $model,
            'ai_response' => $aiResponse,
        ];
    }

    private function canonicalDecision(array $review): string
    {
        return match (strtolower((string) ($review['decision'] ?? ''))) {
            'validated', 'keep' => 'validated',
            'not_validated', 'not_validated_with_alternative', 'replace' => 'not_validated',
            'not_validated_without_alternative', 'reject' => 'not_validated_without',
            'insufficient_information', 'insufficient_data' => 'insufficient',
            default => 'failed',
        };
    }

    private function proposalFromReview(array $review): ?array
    {
        if (is_array($review['alternative'] ?? null)) {
            return $review['alternative'];
        }

        $key = $review['candidate_key'] ?? null;
        $market = $review['alternative_market'] ?? $review['market'] ?? null;
        $selection = $review['alternative_selection'] ?? $review['selection'] ?? null;
        if (! $key && ! $market && ! $selection) {
            return null;
        }

        $justification = (string) ($review['alternative_reason']
            ?? $review['justification']
            ?? $review['reasoning']
            ?? '');

        return [
            'candidate_key' => $key,
            'market' => $market,
            'selection' => $selection,
            'line' => $review['line'] ?? null,
            'label' => $review['label'] ?? null,
            'probability' => $review['alternative_probability'] ?? null,
            'value_analysis' => (bool) ($review['value_analysis'] ?? false),
            'justification' => $justification,
            'risk_factors' => $review['risk_factors'] ?? [],
        ];
    }

    /**
     * Une alternative n'est retenue que si le marché et la sélection existent.
     * La probabilité Python est relue sur le marché déjà calculé. Sinon
     * l'estimation reste identifiée comme telle, sans probabilité Python.
     */
    private function resolveAlternative(?array $proposal, array $candidates, ?array $python): ?array
    {
        if (! is_array($proposal)) {
            return null;
        }

        $matched = null;
        $key = $proposal['candidate_key'] ?? null;
        if (is_string($key) && $key !== '') {
            $matched = collect($candidates)->firstWhere('candidate_key', $key);
        }
        if (! $matched && is_string($proposal['market'] ?? null) && is_string($proposal['selection'] ?? null)) {
            $matched = collect($candidates)->first(fn (array $candidate): bool => $candidate['market'] === $proposal['market']
                && $candidate['selection'] === $proposal['selection']);
        }

        if ($matched && $python && ($matched['candidate_key'] ?? null) === ($python['candidate_key'] ?? null)) {
            return null;
        }

        $justification = (string) ($proposal['justification'] ?? $proposal['alternative_reason'] ?? $proposal['reasoning'] ?? '');
        $risks = array_values(array_filter((array) ($proposal['risk_factors'] ?? $proposal['risks'] ?? []), 'is_string'));
        $valueRequested = (bool) ($proposal['value_analysis'] ?? false);

        if ($matched) {
            // Rejet strict si le marché sous-jacent a une couverture nulle, un statut insuffisant ou une probabilité triviale
            if (($matched['coverage'] ?? '') === 'none'
                || in_array($matched['market_status'] ?? '', ['insufficient', 'unsupported'], true)
                || (float) ($matched['probability'] ?? 0) > 0.82
            ) {
                return null;
            }

            $bookmaker = ($matched['odds_source'] ?? null) === 'bookmaker';
            if ($valueRequested && ! $bookmaker) {
                $risks[] = 'value_analysis_without_bookmaker_odds';
            }

            return [
                'candidate_key' => $matched['candidate_key'],
                'market' => $matched['market'],
                'selection' => $matched['selection'],
                'label' => $matched['label'],
                'line' => $matched['line'],
                'probability' => $matched['probability'],
                'origin' => 'PYTHON',
                'ai_probability' => null,
                'odds' => $matched['odds'] ?? null,
                'odds_source' => $matched['odds_source'] ?? null,
                'justification' => $justification,
                'risks' => array_values(array_unique($risks)),
                'technically_valid' => ! ($valueRequested && ! $bookmaker),
                'market_recognized' => true,
            ];
        }

        $market = is_string($proposal['market'] ?? null) ? $proposal['market'] : null;
        $selection = is_string($proposal['selection'] ?? null) ? $proposal['selection'] : null;
        if (! $this->selectionIsRecognized($market, $selection)) {
            return null;
        }

        $aiProbability = is_numeric($proposal['probability'] ?? null) ? (float) $proposal['probability'] : null;
        if ($aiProbability !== null && ($aiProbability <= 0 || $aiProbability >= 1)) {
            $aiProbability = null;
        }
        $risks[] = 'python_probability_unavailable';

        return [
            'candidate_key' => $market.':'.$selection,
            'market' => $market,
            'selection' => $selection,
            'label' => is_string($proposal['label'] ?? null) ? $proposal['label'] : $selection,
            'line' => is_numeric($proposal['line'] ?? null) ? (float) $proposal['line'] : null,
            'probability' => null,
            'origin' => 'AI_ESTIMATE',
            'ai_probability' => $aiProbability,
            'odds' => null,
            'odds_source' => null,
            'justification' => $justification,
            'risks' => array_values(array_unique($risks)),
            'technically_valid' => false,
            'market_recognized' => true,
        ];
    }

    private function selectionIsRecognized(?string $market, ?string $selection): bool
    {
        if ($market === null || $selection === null || $selection === '') {
            return false;
        }

        return match ($market) {
            '1N2', '1x2', 'half_time_result', 'ht_1x2' => in_array($selection, ['home', 'draw', 'away'], true),
            'double_chance' => in_array($selection, ['1X', 'X2', '12'], true),
            'btts', 'both_teams_to_score', 'half_time_btts' => in_array($selection, ['yes', 'no'], true),
            'dnb', 'draw_no_bet' => in_array($selection, ['home', 'away'], true),
            'clean_sheet', 'win_to_nil' => in_array($selection, ['home', 'away'], true),
            'totals', 'over_under', 'asian_totals', 'half_time_totals' => (bool) preg_match('/^(over|under)(?:_\d+_\d+|:\d+(?:\.\d+)?)$/i', $selection),
            'team_totals', 'team_total_goals', 'asian_team_totals' => (bool) preg_match('/^(home|away)_(OVER|UNDER|over|under)(?:_\d+(?:\.\d+)?|:\d+(?:\.\d+)?)$/i', $selection),
            'asian_handicap', 'european_handicap' => true,
            'result_total_combo', 'result_btts_combo', 'result_goals_combo', 'htft_combo', 'combos' => true,
            'corners', 'total_corners', 'cards', 'total_cards', 'shots', 'total_shots', 'fouls', 'offsides' => true,
            'players', 'anytime_goalscorer', 'player_carded' => true,
            'exact_score' => (bool) preg_match('/^\d+-\d+$/', $selection),
            default => false,
        };
    }

    private function withPythonOrigin(array $candidate): array
    {
        $candidate['origin'] = 'PYTHON';
        $candidate['origin_label'] = 'Python';

        return $candidate;
    }

    private function qualityRisks(float $quality): array
    {
        if ($quality >= 58) {
            return [];
        }

        return ['data_quality_below_validation_threshold', 'limited_data_mode'];
    }

    private function displayPython(?array $candidate): ?array
    {
        if (! is_array($candidate) || empty($candidate['market']) || empty($candidate['selection'])) {
            return null;
        }

        $probability = is_numeric($candidate['probability'] ?? null) ? (float) $candidate['probability'] : null;

        return [
            'market' => $candidate['market'],
            'selection' => $candidate['selection'],
            'label' => $candidate['label'] ?? $candidate['selection'],
            'probability' => $probability,
            'probability_label' => $probability === null ? 'Non calculée' : $this->percentLabel($probability),
            'origin' => 'PYTHON',
            'origin_label' => 'Python',
            'high_confidence' => (bool) ($candidate['high_confidence'] ?? false),
            'calibration_status' => $candidate['calibration_status'] ?? 'insufficient_sample',
            'calibration_label' => $candidate['calibration_label'] ?? 'Non suffisamment calibré',
        ];
    }

    private function displayAlternative(?array $alternative): ?array
    {
        if (! is_array($alternative)) {
            return null;
        }

        $origin = ($alternative['origin'] ?? '') === 'PYTHON' ? 'PYTHON' : 'AI_ESTIMATE';
        $probability = $origin === 'PYTHON' && is_numeric($alternative['probability'] ?? null)
            ? (float) $alternative['probability']
            : null;
        $aiProbability = is_numeric($alternative['ai_probability'] ?? null) ? (float) $alternative['ai_probability'] : null;

        return [
            'market' => $alternative['market'] ?? null,
            'selection' => $alternative['selection'] ?? null,
            'label' => $alternative['label'] ?? ($alternative['selection'] ?? ''),
            'probability' => $probability,
            'probability_label' => $probability === null ? 'Non calculée par Python' : $this->percentLabel($probability),
            'ai_probability' => $aiProbability,
            'ai_probability_label' => $aiProbability === null ? null : $this->percentLabel($aiProbability),
            'origin' => $origin,
            'origin_label' => $origin === 'PYTHON' ? 'Python' : 'Estimation IA',
            'justification' => (string) ($alternative['justification'] ?? ''),
            'risks' => array_values(array_filter((array) ($alternative['risks'] ?? []), 'is_string')),
            'technically_valid' => (bool) ($alternative['technically_valid'] ?? false),
            'market_recognized' => (bool) ($alternative['market_recognized'] ?? false),
            'odds' => ($alternative['odds_source'] ?? null) === 'bookmaker' ? ($alternative['odds'] ?? null) : null,
            'odds_source' => ($alternative['odds_source'] ?? null) === 'bookmaker' ? 'bookmaker' : null,
        ];
    }

    private function percentLabel(float $probability): string
    {
        return number_format($probability * 100, 1, ',', ' ').' %';
    }
}
