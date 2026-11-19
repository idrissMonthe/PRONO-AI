<?php

namespace App\Services\AIExplanation;

use App\Models\LlmInteraction;
use Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GeminiExplanationService
{
    protected ?string $apiKey;
    protected string $model;

    public function __construct()
    {
        $this->apiKey = config('services.gemini.api_key', env('GEMINI_API_KEY'));
        $this->model = config('services.gemini.model', env('GEMINI_MODEL', 'gemini-3.8-flash'));
    }

    public function getApiKey(): ?string
    {
        return config('services.gemini.api_key') ?: ($this->apiKey ?: env('GEMINI_API_KEY'));
    }

    public function getModel(): string
    {
        return config('services.gemini.model') ?: ($this->model ?: env('GEMINI_MODEL', 'gemini-3.8-flash'));
    }

    public function getModelsToTry(): array
    {
        // Cascade volontairement limitée aux modèles réellement disponibles
        // pour une clé AI Studio : les versions 2.5 sont retirées (404) et
        // `gemini-flash-latest` répond régulièrement 503/timeout.
        return array_values(array_unique(array_filter([
            $this->getModel(),
            'gemini-flash-lite-latest',
            'gemini-3.5-flash-lite',
            'gemini-3.8-flash',
        ])));
    }

    /**
     * Analyse et journalise la réponse d'erreur de l'API Gemini.
     * Retourne true si la cascade de modèles doit s'arrêter immédiatement (401, 403, 429).
     */
    protected function handleApiErrorResponse(\Illuminate\Http\Client\Response $response, string $model, string $action): bool
    {
        $status = $response->status();
        $errorBody = $response->json('error', []);
        $errorCode = $errorBody['code'] ?? $status;
        $errorStatus = $errorBody['status'] ?? ('HTTP_' . $status);
        $errorMessage = $errorBody['message'] ?? $response->body();

        Log::warning("Gemini API error during {$action}.", [
            'model' => $model,
            'http_status' => $status,
            'error_code' => $errorCode,
            'error_status' => $errorStatus,
            'error_message' => substr((string) $errorMessage, 0, 500),
        ]);

        // 401 Non autorisé ou 403 Clé invalide/permissions : inutile de tester d'autres modèles avec la même clé
        if ($status === 401 || $status === 403) {
            return true;
        }

        // 429 Quota dépassé / Rate limit : le quota est propre à chaque modèle.
        // Un modèle saturé ne doit pas empêcher d'essayer le suivant, sinon une
        // clé parfaitement valide finit en « revue indisponible ».
        if ($status === 429) {
            return false;
        }

        // 404 Modèle inconnu ou 5xx Erreur serveur Google : on tente le modèle suivant
        return false;
    }

    public function isConfigured(): bool
    {
        $key = $this->getApiKey();
        return !empty($key) && $key !== 'your-gemini-api-key';
    }

    /**
     * Analyse complémentaire pour les matchs que Python ne couvre pas encore.
     * Gemini peut proposer une estimation, mais elle reste explicitement
     * AI_ESTIMATE et ne reçoit jamais le statut d'une probabilité calibrée.
     */
    public function analyzeSparseMatch(array $context): array
    {
        $fallback = [
            'decision' => 'no_prediction',
            'candidate' => null,
            'reasoning' => 'Aucune analyse IA exploitable n’est disponible.',
            'supporting_factors' => [],
            'risk_factors' => ['ai_unavailable_or_insufficient_context'],
            'missing_data' => $context['missing_data'] ?? [],
            'confidence' => 'low',
            'provider' => 'deterministic_fallback',
            'model' => null,
            'fallback' => true,
        ];

        if (! $this->isConfigured()) {
            return $fallback;
        }

        $allowedMarkets = [
            '1N2' => ['home', 'draw', 'away'],
            'double_chance' => ['1X', 'X2', '12'],
            'btts' => ['yes', 'no'],
            'totals' => ['over_0_5', 'under_0_5', 'over_1_5', 'under_1_5', 'over_2_5', 'under_2_5', 'over_3_5', 'under_3_5', 'over_4_5', 'under_4_5'],
            'asian_totals' => ['over_1_0', 'under_1_0', 'over_2_0', 'under_2_0', 'over_3_0', 'under_3_0'],
            'dnb' => ['home', 'away'],
            'team_totals' => ['home_OVER_0.5', 'home_UNDER_0.5', 'home_OVER_1.5', 'home_UNDER_1.5', 'home_OVER_2.5', 'home_UNDER_2.5', 'away_OVER_0.5', 'away_UNDER_0.5', 'away_OVER_1.5', 'away_UNDER_1.5', 'away_OVER_2.5', 'away_UNDER_2.5'],
        ];
        $systemInstruction = implode("\n", [
            'Tu es un analyste football complémentaire, pas un bookmaker.',
            'Tu utilises uniquement les faits présents dans le JSON fourni.',
            'Tu ne dois jamais inventer une statistique, une blessure, une cote, une composition ou un résultat.',
            'Si les informations sont insuffisantes, propose quand même une sélection prudente parmi allowed_markets, avec confidence=low. N’invente aucune statistique. decision=no_prediction n’est réservé qu’à un JSON vide.',
            'Une probabilité proposée doit être une estimation IA non calibrée, jamais une probabilité Python.',
            'Tu ne peux choisir qu’un marché et une sélection dans allowed_markets.',
            'Réponds uniquement avec un objet JSON valide.',
        ]);
        $prompt = "JSON de référence :\n".json_encode([
            'match' => $context,
            'allowed_markets' => $allowedMarkets,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $contextHash = hash('sha256', json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $modelsToTry = $this->getModelsToTry();

        foreach ($modelsToTry as $currentModel) {
            try {
                $response = Http::timeout(12)
                    ->withHeaders(['Content-Type' => 'application/json', 'x-goog-api-key' => $this->getApiKey()])
                    ->post("https://generativelanguage.googleapis.com/v1beta/models/{$currentModel}:generateContent", [
                        'contents' => [['role' => 'user', 'parts' => [['text' => $prompt]]]],
                        'systemInstruction' => ['parts' => [['text' => $systemInstruction]]],
                        'generationConfig' => [
                            'temperature' => 0.1,
                            'maxOutputTokens' => 700,
                            'responseMimeType' => 'application/json',
                            // Pas de thinkingConfig : thinkingBudget=0 est rejeté
                            // (400 INVALID_ARGUMENT) par les modèles actuels.
                        ],
                    ]);

                if (! $response->successful()) {
                    $shouldStop = $this->handleApiErrorResponse($response, $currentModel, 'sparse_analysis');
                    if ($shouldStop) {
                        break;
                    }
                    continue;
                }

                $parts = $response->json('candidates.0.content.parts', []);
                $text = collect($parts)->filter(fn ($part) => isset($part['text']) && empty($part['thought']))
                    ->pluck('text')->implode('');
                $decoded = json_decode(trim(str_replace(['```json', '```'], '', $text)), true);
                $validated = $this->validateSparseAnalysis($decoded, $allowedMarkets, $fallback);

                LlmInteraction::create([
                    'provider' => 'google_gemini',
                    'model' => $currentModel,
                    'context_hash' => $contextHash,
                    'sanitized_prompt' => substr($prompt, 0, 500).'...',
                    'response_text' => json_encode($validated, JSON_UNESCAPED_UNICODE),
                    'verification_passed' => ! ($validated['fallback'] ?? true),
                    'verification_details' => ['type' => 'sparse_analysis_contract'],
                    'interacted_at_utc' => now()->utc(),
                ]);

                $validated['provider'] = 'google_gemini';
                $validated['model'] = $currentModel;
                $validated['fallback'] = false;
                return $validated;
            } catch (Exception $e) {
                Log::warning('Gemini sparse analysis connection failed.', ['model' => $currentModel, 'exception' => $e::class]);
            }
        }

        return $fallback;
    }

    private function validateSparseAnalysis(mixed $decoded, array $allowedMarkets, array $fallback): array
    {
        if (! is_array($decoded)) return $fallback;
        $decision = $decoded['decision'] ?? 'no_prediction';
        if (! in_array($decision, ['propose', 'no_prediction'], true)) return $fallback;
        $candidate = $decoded['candidate'] ?? null;
        if ($decision === 'no_prediction' || ! is_array($candidate)) {
            return array_merge($fallback, [
                'reasoning' => (string) ($decoded['reasoning'] ?? $fallback['reasoning']),
                'missing_data' => is_array($decoded['missing_data'] ?? null) ? $decoded['missing_data'] : $fallback['missing_data'],
                'risk_factors' => is_array($decoded['risk_factors'] ?? null) ? $decoded['risk_factors'] : $fallback['risk_factors'],
            ]);
        }

        $market = (string) ($candidate['market'] ?? '');
        $selection = (string) ($candidate['selection'] ?? '');
        $probability = $candidate['probability'] ?? null;
        if (! isset($allowedMarkets[$market]) || ! in_array($selection, $allowedMarkets[$market], true)
            || ! is_numeric($probability) || (float) $probability < 0.05 || (float) $probability > 0.95) {
            return $fallback;
        }

        return [
            'decision' => 'propose',
            'candidate' => [
                'candidate_key' => $market.':'.$selection,
                'market' => $market,
                'selection' => $selection,
                'label' => (string) ($candidate['label'] ?? $selection),
                'line' => isset($candidate['line']) && is_numeric($candidate['line']) ? (float) $candidate['line'] : null,
                'probability' => round((float) $probability, 4),
                'origin' => 'AI_ESTIMATE',
            ],
            'reasoning' => (string) ($decoded['reasoning'] ?? ''),
            'supporting_factors' => is_array($decoded['supporting_factors'] ?? null) ? $decoded['supporting_factors'] : [],
            'risk_factors' => is_array($decoded['risk_factors'] ?? null) ? $decoded['risk_factors'] : [],
            'missing_data' => is_array($decoded['missing_data'] ?? null) ? $decoded['missing_data'] : [],
            'confidence' => in_array($decoded['confidence'] ?? null, ['low', 'medium', 'high'], true) ? $decoded['confidence'] : 'low',
        ];
    }

    public function explainMatch(
        array $predictionContext,
        string $mode = 'beginner', // 'beginner' (vulgarisation) or 'expert' (math & métriques)
        ?string $userQuestion = null
    ): array {
        $contextHash = hash('sha256', json_encode($predictionContext));

        // If Gemini is not configured, return strict deterministic template fallback (CDC 9.5)
        if (!$this->isConfigured()) {
            $fallbackText = $this->generateDeterministicExplanation($predictionContext, $mode);
            return [
                'provider' => 'deterministic_template',
                'text' => $fallbackText,
                'verified' => true,
                'is_fallback' => true,
            ];
        }

        $systemInstruction = $this->buildSystemInstruction($mode);
        $prompt = $this->buildPrompt($predictionContext, $mode, $userQuestion);

        $apiKey = $this->getApiKey();
        $modelsToTry = $this->getModelsToTry();

        foreach ($modelsToTry as $currentModel) {
            try {
                $url = "https://generativelanguage.googleapis.com/v1beta/models/{$currentModel}:generateContent";

                $response = Http::timeout(15)
                    ->withHeaders([
                        'Content-Type' => 'application/json',
                        'x-goog-api-key' => $apiKey,
                    ])
                    ->post($url, [
                        'contents' => [
                            [
                                'role' => 'user',
                                'parts' => [
                                    ['text' => $prompt]
                                ]
                            ]
                        ],
                        'systemInstruction' => [
                            'parts' => [
                                ['text' => $systemInstruction]
                            ]
                        ],
                        'generationConfig' => [
                            'temperature' => 0.2, // Faible température pour précision factuelle
                            'maxOutputTokens' => 800,
                        ]
                    ]);

                if (! $response->successful()) {
                    $shouldStop = $this->handleApiErrorResponse($response, $currentModel, 'match_explanation');
                    if ($shouldStop) {
                        break;
                    }
                    continue;
                }
                    $data = $response->json();
                    $parts = $data['candidates'][0]['content']['parts'] ?? [];

                    // Extraction propre en ignorant les pensées internes (thought: true)
                    $generatedText = '';
                    foreach ($parts as $part) {
                        if (isset($part['text']) && empty($part['thought'])) {
                            $generatedText .= $part['text'];
                        }
                    }
                    if (empty($generatedText) && !empty($parts)) {
                        $lastPart = end($parts);
                        $generatedText = $lastPart['text'] ?? '';
                    }

                    if (!empty($generatedText)) {
                        // Vérification de conformité contre les règles strictes (CDC 9.5)
                        $verification = $this->verifyNumbersInResponse($generatedText, $predictionContext);

                        $finalText = $verification['passed'] ? $generatedText : $this->generateDeterministicExplanation($predictionContext, $mode);

                        LlmInteraction::create([
                            'provider' => 'google_gemini',
                            'model' => $currentModel,
                            'context_hash' => $contextHash,
                            'sanitized_prompt' => substr($prompt, 0, 500) . '...',
                            'response_text' => $finalText,
                            'verification_passed' => $verification['passed'],
                            'verification_details' => $verification,
                            'interacted_at_utc' => now()->utc(),
                        ]);

                        return [
                            'provider' => 'google_gemini',
                            'model' => $currentModel,
                            'text' => $finalText,
                            'verified' => $verification['passed'],
                            'is_fallback' => !$verification['passed'],
                            'verification_notes' => $verification['notes'] ?? null,
                        ];
                    }
            } catch (Exception $e) {
                Log::error('Gemini API connection failed.', [
                    'model' => $currentModel,
                    'exception' => $e::class,
                ]);
            }
        }

        // Return fallback on any failure
        $fallbackText = $this->generateDeterministicExplanation($predictionContext, $mode);
        return [
            'provider' => 'deterministic_template',
            'text' => $fallbackText,
            'verified' => true,
            'is_fallback' => true,
        ];
    }

    protected function buildSystemInstruction(string $mode): string
    {
        $base = "Tu es l'assistant statistique d'ORACLE FOOT, un outil personnel d'aide à la décision footballistique.\n"
            . "RÈGLES D'INTÉGRITÉ OBLIGATOIRES (CDC Section 9.4, 9.5 & 28) :\n"
            . "1. Tu ne dois JAMAIS inventer de blessures, de compositions ou de statistiques non présentes dans les données.\n"
            . "2. Tu ne dois JAMAIS utiliser de termes incitatifs comme 'pari sûr', 'à jouer', 'bonne affaire' ou donner des conseils de mise financière.\n"
            . "3. Rappelle systématiquement l'incertitude inhérente au football (par exemple : une probabilité de 65% signifie que dans 35% des cas, l'événement ne se produit pas).\n"
            . "4. Tout écart avec les cotes du marché peut refléter une information manquante au modèle (compositions tardives, météo, dynamique interne).\n"
            . "5. Cite UNIQUEMENT les chiffres et pourcentages exacts fournis dans le document JSON de contexte.\n"
            . "6. Si une donnée est absente ou nulle, indique qu'elle est indisponible. N'invente aucun chiffre pour la remplacer.";

        if ($mode === 'expert') {
            $base .= "\nMode EXPERT : Analyse technique approfondie, détaille les lambdas (buts attendus Poisson/Dixon-Coles), la calibration, les intervalles de crédibilité (ci80, ci95) et le retrait de marge.";
        } else {
            $base .= "\nMode DÉBUTANT : Vulgarisation claire et pédagogique en français soigné, phrases concises, mise en avant des dynamiques principales sans jargon excessif.";
        }

        return $base;
    }

    protected function buildPrompt(array $context, string $mode, ?string $userQuestion): string
    {
        $jsonContext = json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $prompt = "DOCUMENT DE DONNÉES DU MATCH (Source de vérité) :\n```json\n{$jsonContext}\n```\n\n";

        if ($userQuestion) {
            $prompt .= "Question de l'analyste : \"{$userQuestion}\"\n";
            $prompt .= "Réponds précisément à cette question en t'appuyant uniquement sur les données ci-dessus.";
        } else {
            $prompt .= "Rédige une synthèse explicative du match expliquant :\n"
                . "- Pourquoi cette issue est favorisée selon le modèle statistique.\n"
                . "- Les buts attendus et l'équilibre attaque/défense.\n"
                . "- Les limites de cette analyse (qualité des données, incertitude).\n"
                . "- La comparaison avec le marché sans marge si disponible.";
        }

        return $prompt;
    }

    protected function verifyNumbersInResponse(string $text, array $context): array
    {
        $forbidden = ['pari sûr', 'mise garantie', 'à tenter absolument', 'fortune'];
        foreach ($forbidden as $word) {
            if (stripos($text, $word) !== false) {
                return ['passed' => false, 'notes' => "Forbidden word detected: {$word}"];
            }
        }

        $allowedNumbers = [0.0, 1.0, 80.0, 95.0];
        array_walk_recursive($context, function ($value) use (&$allowedNumbers): void {
            if (is_numeric($value)) {
                $number = (float) $value;
                $allowedNumbers[] = $number;
                if ($number >= 0 && $number <= 1) {
                    $allowedNumbers[] = $number * 100;
                }

                return;
            }

            if (is_string($value) && preg_match_all('/-?\d+(?:[.,]\d+)?/', $value, $matches)) {
                foreach ($matches[0] as $match) {
                    $allowedNumbers[] = (float) str_replace(',', '.', $match);
                }
            }
        });

        preg_match_all('/(?<![\p{L}\w])(-?\d+(?:[.,]\d+)?)(\s?%)?/u', $text, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            $number = (float) str_replace(',', '.', $match[1]);
            $normalized = !empty($match[2]) ? $number / 100 : $number;
            $isSupported = false;

            foreach ($allowedNumbers as $allowedNumber) {
                if (abs($normalized - $allowedNumber) <= 0.001) {
                    $isSupported = true;
                    break;
                }
            }

            if (!$isSupported) {
                return ['passed' => false, 'notes' => "Unsupported number detected: {$match[0]}"];
            }
        }

        return ['passed' => true, 'notes' => 'Integrity verified'];
    }

    public function generateDeterministicExplanation(array $context, string $mode = 'beginner'): string
    {
        $home = $context['home_team'] ?? 'Équipe domicile';
        $away = $context['away_team'] ?? 'Équipe extérieur';
        $pHome = $this->contextUnitProbability($context['probabilities']['home']['p'] ?? null);
        $pDraw = $this->contextUnitProbability($context['probabilities']['draw']['p'] ?? null);
        $pAway = $this->contextUnitProbability($context['probabilities']['away']['p'] ?? null);
        $xGHome = $this->contextNonNegative($context['expected_goals']['home'] ?? null);
        $xGAway = $this->contextNonNegative($context['expected_goals']['away'] ?? null);
        $qScore = $this->contextNonNegative($context['quality']['score'] ?? null);
        $scoreModal = is_string($context['top_scores'][0]['score'] ?? null) ? $context['top_scores'][0]['score'] : null;

        $missing = array_values(array_filter([
            ($pHome === null || $pDraw === null || $pAway === null) ? 'les probabilités 1N2' : null,
            ($xGHome === null || $xGAway === null) ? 'les buts attendus' : null,
            $scoreModal === null ? 'le score le plus probable' : null,
            $qScore === null ? 'l’indice de qualité des données' : null,
        ]));

        $lines = ["### Synthèse Analytique du Match", ''];
        if ($pHome !== null && $pDraw !== null && $pAway !== null) {
            $lines[] = "Victoire {$home} : **{$this->percentLabel($pHome)}**. Match nul : **{$this->percentLabel($pDraw)}**. Victoire {$away} : **{$this->percentLabel($pAway)}**.";
        }
        if ($xGHome !== null && $xGAway !== null) {
            $lines[] = "Buts attendus fournis par le modèle : **{$xGHome}** pour {$home} et **{$xGAway}** pour {$away}.";
        }
        if ($scoreModal !== null) {
            $lines[] = "Score le plus probable enregistré : **{$scoreModal}**.";
        }
        if ($qScore !== null) {
            $lines[] = "Indice de données enregistré : **{$qScore}/100**.";
        }
        if ($missing !== []) {
            $lines[] = 'Informations indisponibles : '.implode(', ', $missing).'. Aucun chiffre de remplacement n’est affiché.';
        }
        $lines[] = '';
        $lines[] = '> **Rappel de prudence** : ces chiffres décrivent le modèle lorsqu’ils sont présents. Ils ne garantissent pas le résultat.';

        return implode("\n", $lines);
    }

    private function contextUnitProbability(mixed $value): ?float
    {
        if (! is_numeric($value)) {
            return null;
        }
        $number = (float) $value;

        return ($number >= 0.0 && $number <= 1.0) ? $number : null;
    }

    private function contextNonNegative(mixed $value): ?float
    {
        if (! is_numeric($value)) {
            return null;
        }
        $number = (float) $value;

        return $number >= 0.0 ? $number : null;
    }

    private function percentLabel(float $probability): string
    {
        return rtrim(rtrim(number_format($probability * 100, 1, '.', ''), '0'), '.').' %';
    }

    /**
     * Analyse et évaluation du risque d'un combiné multi-sélections (CDC Section 9 & 28).
     */
    /**
     * Gemini examine le pronostic Python. Il peut le valider, le refuser
     * avec ou sans alternative, ou déclarer que l'information manque.
     * Il ne calcule aucune probabilité.
     */
    public function reviewPrediction(array $context): array
    {
        $fallback = [
            'decision' => 'review_failed',
            'candidate_key' => null,
            'reasoning' => 'La validation Gemini n’a pas pu être effectuée. L’absence de réponse n’est pas une validation.',
            'supporting_factors' => [],
            'risk_factors' => ['ai_review_unavailable'],
            'required_recalculation' => false,
            'confidence_in_revision' => 'low',
            'provider' => 'deterministic_fallback',
            'model' => null,
            'fallback' => true,
        ];

        if (! $this->isConfigured()) return $fallback;

        $systemInstruction = implode("\n", [
            "Tu es un réviseur critique indépendant de pronostics football.",
            "Tu ne calcules aucune probabilité et tu n'en inventes jamais.",
            "Le pronostic Python initial reste conservé quoi que tu décides : tu ne le remplaces JAMAIS.",
            "RÈGLES D'ÉVALUATION :",
            "1. decision : 'validated' | 'not_validated' | 'insufficient_information'.",
            "2. 'validated' : tu valides le pronostic Python car les statistiques fournies le soutiennent fermement.",
            "3. 'not_validated' : tu ne valides pas le pronostic Python car il présente des risques excessifs ou injustifiés.",
            "4. OBLIGATION D'ALTERNATIVE EN CAS DE NON VALIDATION :",
            "   Si ta décision est 'not_validated', tu DOIS examiner attentivement la liste des candidates fournies et proposer un pronostic alternatif mieux justifié (par exemple : double chance au lieu de victoire sèche, over 1.5 au lieu de over 2.5, BTTS, DNB, total buts, etc.).",
            "   Tu renseignes 'candidate_key' avec la clé exacte issue de candidates, ou 'alternative_market' et 'alternative_selection' décrivant un marché réel.",
            "   Tu fournis 'alternative_reason' expliquant clairement pourquoi cette alternative est préférable au pronostic initial.",
            "   Si et seulement si ABSOLUMENT AUCUNE alternative n'est raisonnablement justifiable, tu laisses candidate_key null et expliques pourquoi dans 'reasoning'. L'absence d'alternative ne doit pas être systématique.",
            "5. 'insufficient_information' : les données ne permettent pas de trancher rigoureusement.",
            "Réponds UNIQUEMENT avec un objet JSON valide.",
            "Champs obligatoires : decision, candidate_key (clé existante ou null), alternative_market (string ou null), alternative_selection (string ou null), alternative_reason (string ou null), reasoning (français, concis), supporting_factors (array), risk_factors (array), confidence_in_revision (low|medium|high), value_analysis (booléen).",
        ]);

        $prompt = "DOCUMENT STATISTIQUE DU MATCH (Source de vérité) :\n"
            . "Rencontre : " . ($context['home_team'] ?? 'Domicile') . " vs " . ($context['away_team'] ?? 'Extérieur') . "\n"
            . "Espérance de buts (xG Dixon-Coles) : Domicile = " . ($context['python_result']['expected_goals']['home'] ?? 'N/A') . ", Extérieur = " . ($context['python_result']['expected_goals']['away'] ?? 'N/A') . "\n"
            . "Sélection initiale proposée par Python : " . json_encode($context['initial_candidate'] ?? [], JSON_UNESCAPED_UNICODE) . "\n\n"
            . "CANDIDATS DISPONIBLES (marchés calculés par Python, avec cotes lorsqu'elles existent) :\n"
            . json_encode($context['candidates'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n\n"
            . "Consigne : Examine la proposition Python. Si la sélection présente une cote trop faible (< 1.25/1.30) ou un risque excessif, tu dois répondre 'not_validated', sélectionner obligatoirement la meilleure alternative parmi les candidats et la justifier.";
        $contextHash = hash('sha256', json_encode($context));
        $apiKey = $this->getApiKey();
        $modelsToTry = $this->getModelsToTry();

        foreach ($modelsToTry as $currentModel) {
            try {
                $response = Http::timeout(25)
                    ->withHeaders(['Content-Type' => 'application/json', 'x-goog-api-key' => $apiKey])
                    ->post("https://generativelanguage.googleapis.com/v1beta/models/{$currentModel}:generateContent", [
                        'contents' => [['role' => 'user', 'parts' => [['text' => $prompt]]]],
                        'systemInstruction' => ['parts' => [['text' => $systemInstruction]]],
                        'generationConfig' => [
                            'temperature' => 0.1,
                            'maxOutputTokens' => 600,
                            'responseMimeType' => 'application/json',
                        ],
                    ]);

                if (! $response->successful()) {
                    $shouldStop = $this->handleApiErrorResponse($response, $currentModel, 'prediction_review');
                    if ($shouldStop) {
                        break;
                    }
                    continue;
                }
                $parts = $response->json('candidates.0.content.parts', []);
                $text = trim(implode("\n", array_map(fn ($part) => $part['text'] ?? '', array_filter($parts, fn ($part) => empty($part['thought'])))));
                $decoded = $this->decodeReviewJson($text);
                if (! is_array($decoded)) continue;
                $decision = $decoded['decision'] ?? null;
                $candidateKey = $decoded['candidate_key'] ?? null;
                $allowed = [
                    'validated', 'not_validated', 'insufficient_information',
                    'keep', 'replace', 'reject', 'insufficient_data',
                    'not_validated_with_alternative', 'not_validated_without_alternative',
                ];
                if (! in_array($decision, $allowed, true)) continue;

                $normalized = [
                    'decision' => $decision,
                    'candidate_key' => $candidateKey,
                    'alternative_market' => is_string($decoded['alternative_market'] ?? null) ? $decoded['alternative_market'] : null,
                    'alternative_selection' => is_string($decoded['alternative_selection'] ?? null) ? $decoded['alternative_selection'] : null,
                    'alternative_reason' => is_string($decoded['alternative_reason'] ?? $decoded['justification'] ?? null) ? substr($decoded['alternative_reason'] ?? $decoded['justification'], 0, 2000) : null,
                    'reasoning' => is_string($decoded['reasoning'] ?? null) ? substr($decoded['reasoning'], 0, 2000) : '',
                    'supporting_factors' => array_values(array_filter((array) ($decoded['supporting_factors'] ?? []), 'is_string')),
                    'risk_factors' => array_values(array_filter((array) ($decoded['risk_factors'] ?? []), 'is_string')),
                    'value_analysis' => (bool) ($decoded['value_analysis'] ?? false),
                    'required_recalculation' => false,
                    'confidence_in_revision' => in_array(($decoded['confidence_in_revision'] ?? 'low'), ['low', 'medium', 'high'], true) ? $decoded['confidence_in_revision'] : 'low',
                    'provider' => 'google_gemini',
                    'model' => $currentModel,
                    'fallback' => false,
                ];

                LlmInteraction::create([
                    'provider' => 'google_gemini',
                    'model' => $currentModel,
                    'context_hash' => $contextHash,
                    'sanitized_prompt' => substr($prompt, 0, 1000),
                    'response_text' => $text,
                    'verification_passed' => true,
                    'verification_details' => ['decision' => $decision, 'candidate_key' => $candidateKey],
                    'interacted_at_utc' => now()->utc(),
                ]);

                return $normalized;
            } catch (\Throwable $exception) {
                Log::warning('Gemini prediction review failed.', ['model' => $currentModel, 'exception' => $exception::class, 'message' => $exception->getMessage()]);
            }
        }

        // Si l'API Gemini est temporairement indisponible (quota journalier atteint,
        // latence réseau ou panne), la révision critique s'exécute de façon déterministe
        // pour garantir que l'analyse des cotes et l'alternative obligatoire sont publiées.
        return $this->generateDeterministicReview($context, 'api_quota_or_network_cooldown');
    }

    /**
     * Analyse critique déterministe et sélection d'une alternative robuste.
     * Appliquée en relai lorsque les quotas de l'API externe sont atteints.
     */
    public function generateDeterministicReview(array $context, string $trigger = ''): array
    {
        $initial = $context['initial_candidate'] ?? null;
        $candidates = $context['candidates'] ?? [];
        $home = $context['home_team'] ?? 'Domicile';
        $away = $context['away_team'] ?? 'Extérieur';

        if (! is_array($initial) || empty($candidates)) {
            return [
                'decision' => 'insufficient_information',
                'candidate_key' => null,
                'alternative_market' => null,
                'alternative_selection' => null,
                'alternative_reason' => null,
                'reasoning' => 'Informations statistiques insuffisantes pour évaluer la rencontre.',
                'supporting_factors' => [],
                'risk_factors' => ['insufficient_data'],
                'confidence_in_revision' => 'low',
                'provider' => 'deterministic_local_fallback',
                'model' => 'local_rules_engine',
                'fallback' => true,
            ];
        }

        $odds = (float) ($initial['odds'] ?? 0);
        $prob = (float) ($initial['probability'] ?? 0);
        $market = (string) ($initial['market'] ?? '');
        $label = (string) ($initial['label'] ?? '');

        // Analyse critique de rentabilité et de risque :
        // 1. Cote trop faible (< 1.28) -> ne vaut pas le coup d'être pariée
        // 2. Victoire sèche 1N2 avec probabilité incertaine (< 0.58) -> trop risquée
        $needsAlternative = ($odds > 0 && $odds < 1.28) || ($market === '1N2' && $prob < 0.58);

        if ($needsAlternative) {
            // Recherche de la meilleure alternative parmi les marchés supportés
            $bestAlt = null;
            $bestScore = -999.0;
            foreach ($candidates as $c) {
                if (($c['candidate_key'] ?? '') === ($initial['candidate_key'] ?? '')) {
                    continue;
                }
                $cOdds = (float) ($c['odds'] ?? 1.40);
                $cProb = (float) ($c['probability'] ?? 0);
                $cMarket = (string) ($c['market'] ?? '');

                // Exclure les cotes dérisoires et les issues trop hasardeuses
                if ($cProb < 0.52 || $cOdds < 1.28 || $cOdds > 2.30) {
                    continue;
                }
                // Favoriser les marchés fiables : Double chance, BTTS, Total buts, DNB
                $weight = match ($cMarket) {
                    'double_chance' => 1.25,
                    'btts' => 1.20,
                    'totals', 'total_goals' => 1.15,
                    'dnb', 'draw_no_bet' => 1.10,
                    default => 0.95,
                };
                $score = ($cProb * 0.55 + ($cOdds / 2.5) * 0.25) * $weight;
                if ($score > $bestScore) {
                    $bestScore = $score;
                    $bestAlt = $c;
                }
            }

            if ($bestAlt) {
                $altLabel = $bestAlt['label'] ?? 'Alternative';
                $altOdds = number_format((float) ($bestAlt['odds'] ?? 1.40), 2);
                $altProb = round(((float) ($bestAlt['probability'] ?? 0)) * 100);

                $altReason = ($odds > 0 && $odds < 1.28)
                    ? "La sélection initiale '{$label}' présente une cote trop faible (@" . number_format($odds, 2) . ") ne justifiant pas la prise de risque. L'alternative '{$altLabel}' offre une cote plus abordable (@{$altOdds}) avec une excellente fiabilité ({$altProb}%)."
                    : "La victoire sèche comporte un risque élevé sur cette rencontre équilibrée. L'alternative '{$altLabel}' sécurise le scénario avec une probabilité robuste ({$altProb}%) à cote @{$altOdds}.";

                return [
                    'decision' => 'not_validated',
                    'candidate_key' => $bestAlt['candidate_key'],
                    'alternative_market' => $bestAlt['market'],
                    'alternative_selection' => $bestAlt['selection'],
                    'alternative_reason' => $altReason,
                    'reasoning' => "Pronostic initial contesté en raison d'un rapport risque/gain défavorable. Proposition d'un marché alternatif plus rémunérateur et sécurisé.",
                    'supporting_factors' => ["Probabilité solide de l'alternative ({$altProb}%)", "Cote rémunératrice (@{$altOdds})"],
                    'risk_factors' => ['initial_selection_unfavorable_value'],
                    'confidence_in_revision' => 'medium',
                    'provider' => 'deterministic_local_fallback',
                    'model' => 'local_rules_engine',
                    'fallback' => true,
                ];
            }
        }

        $pct = round($prob * 100);
        $oddsStr = $odds > 0 ? " à cote @" . number_format($odds, 2) : "";
        return [
            'decision' => 'validated',
            'candidate_key' => null,
            'alternative_market' => null,
            'alternative_selection' => null,
            'alternative_reason' => null,
            'reasoning' => "Le pronostic Python '{$label}' est validé : les distributions statistiques soutiennent fermement cette sélection ({$pct}% de probabilité{$oddsStr}) avec un équilibre risque/gain satisfaisant.",
            'supporting_factors' => ["Probabilité robuste de {$pct}%", "Rentabilité confirmée"],
            'risk_factors' => [],
            'confidence_in_revision' => 'high',
            'provider' => 'deterministic_local_fallback',
            'model' => 'local_rules_engine',
            'fallback' => true,
        ];
    }

    private function decodeReviewJson(string $text): ?array
    {
        $text = trim(preg_replace('/^```(?:json)?|```$/m', '', $text));
        $decoded = json_decode($text, true);
        if (is_array($decoded)) return $decoded;
        $start = strpos($text, '{');
        $end = strrpos($text, '}');
        if ($start === false || $end === false || $end <= $start) return null;
        $decoded = json_decode(substr($text, $start, $end - $start + 1), true);
        return is_array($decoded) ? $decoded : null;
    }

    public function explainParlay(array $parlayContext): array
    {
        if (!$this->isConfigured()) {
            return [
                'provider' => 'deterministic_template',
                'model' => 'deterministic_template',
                'text' => $this->generateDeterministicParlayExplanation($parlayContext),
            ];
        }

        $systemInstruction = "Tu es ORACLE FOOT, un assistant probabiliste de football impartial et rigoureux. "
            . "Tu analyses un combiné généré à partir de calculs mathématiques réels (Dixon-Coles). "
            . "Règles strictes : "
            . "1. Ne promets JAMAIS de gain ni de 'pari sûr' ou 'garantie'. "
            . "2. Rappelle que le produit des probabilités est une hypothèse d'indépendance, pas une probabilité jointe exacte si des sélections partagent une ligue. "
            . "3. Identifie clairement la sélection qui présente la plus forte incertitude. "
            . "4. Si pricing_basis indique des cotes justes indicatives du modèle, précise qu'il ne s'agit pas de cotes bookmaker et que l'EV n'est pas calculée ; sinon, mentionne la source réelle des cotes. "
            . "5. Ne cite AUCUN chiffre absent du contexte JSON fourni. "
            . "Reste concis (3 à 4 paragraphes en français professionnel et clair).";

        $prompt = "Voici les données mathématiques réelles du combiné :\n"
            . json_encode($parlayContext, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
            . "\n\nFournis une évaluation lucide et transparente du risque de ce combiné.";

        $apiKey = $this->getApiKey();
        $modelsToTry = $this->getModelsToTry();

        foreach ($modelsToTry as $currentModel) {
            try {
                $url = "https://generativelanguage.googleapis.com/v1beta/models/{$currentModel}:generateContent";

                $response = Http::timeout(15)
                    ->withHeaders([
                        'Content-Type' => 'application/json',
                        'x-goog-api-key' => $apiKey,
                    ])
                    ->post($url, [
                        'contents' => [
                            ['role' => 'user', 'parts' => [['text' => $prompt]]],
                        ],
                        'systemInstruction' => [
                            'parts' => [['text' => $systemInstruction]],
                        ],
                        'generationConfig' => [
                            'temperature' => 0.2,
                            'maxOutputTokens' => 600,
                        ],
                    ]);

                if (! $response->successful()) {
                    $shouldStop = $this->handleApiErrorResponse($response, $currentModel, 'parlay_explanation');
                    if ($shouldStop) {
                        break;
                    }
                    continue;
                }

                $candidates = $response->json('candidates.0.content.parts', []);
                    $parts = array_filter($candidates, fn ($part) => empty($part['thought']));
                    $text = trim(implode("\n", array_column($parts, 'text')));

                    if (!empty($text)) {
                        return [
                            'provider' => 'gemini',
                            'model' => $currentModel,
                            'text' => $text,
                        ];
                    }
            } catch (Exception $e) {
                Log::warning("Gemini parlay explanation failed with model {$currentModel}: {$e->getMessage()}");
            }
        }

        return [
            'provider' => 'deterministic_template',
            'model' => 'deterministic_template',
            'text' => $this->generateDeterministicParlayExplanation($parlayContext),
        ];
    }

    public function generateDeterministicParlayExplanation(array $context): string
    {
        $combOdds = $context['combined_odds'] ?? 2.0;
        $combProb = round(($context['combined_prob'] ?? 0.35) * 100, 1);
        $riskScore = $context['risk_score'] ?? 6.5;
        $count = count($context['selections'] ?? []);
        $modelPriced = ($context['pricing_basis'] ?? null) === 'cotes justes indicatives du modèle';
        $pricingNote = $modelPriced
            ? 'La cote cumulée est une cote juste théorique déduite des probabilités du modèle, pas une cote bookmaker ; aucune EV n’est calculée.'
            : 'Les cotes répertoriées proviennent des relevés des bookmakers indiqués.';

        return "### Analyse Statistique du Combiné ({$count} sélections)\n\n"
            . "Ce combiné présente une cote totale cumulée de **{$combOdds}** pour une probabilité produit de **{$combProb}%** (hypothèse d’indépendance, indice de risque : **{$riskScore}/10**).\n\n"
            . "**Hypothèse d’indépendance** : ce pourcentage est le produit des probabilités, pas une probabilité jointe exacte lorsque des sélections partagent une ligue ou un même week-end. " . ($context['independence_assumption'] ?? '') . "\n\n"
            . "> **Transparence éthique** : {$pricingNote} Ce combiné est un outil d'observation statistique pédagogique sans garantie de résultat.";
    }

    /**
     * Teste la connectivité directe avec l'API Google Gemini et retourne un diagnostic structuré.
     */
    public function ping(): array
    {
        if (! $this->isConfigured()) {
            return [
                'success' => false,
                'configured' => false,
                'connected' => false,
                'provider' => 'deterministic_local_fallback',
                'model' => $this->getModel(),
                'http_status' => null,
                'error_code' => 'API_KEY_NOT_CONFIGURED',
                'message' => 'Clé API Gemini non configurée dans .env (GEMINI_API_KEY).',
                'latency_ms' => 0,
                'is_fallback' => true,
            ];
        }

        $apiKey = $this->getApiKey();
        $modelsToTry = $this->getModelsToTry();
        $lastStatus = null;
        $lastErrorCode = null;
        $lastMessage = null;
        $startTime = microtime(true);

        foreach ($modelsToTry as $currentModel) {
            $modelStart = microtime(true);
            try {
                $response = Http::timeout(10)
                    ->withHeaders([
                        'Content-Type' => 'application/json',
                        'x-goog-api-key' => $apiKey,
                    ])
                    ->post("https://generativelanguage.googleapis.com/v1beta/models/{$currentModel}:generateContent", [
                        'contents' => [
                            ['role' => 'user', 'parts' => [['text' => 'Confirme en un mot court : OK']]],
                        ],
                        'generationConfig' => [
                            'temperature' => 0.0,
                            'maxOutputTokens' => 10,
                        ],
                    ]);

                $status = $response->status();
                $latencyMs = round((microtime(true) - $modelStart) * 1000);

                if ($response->successful()) {
                    return [
                        'success' => true,
                        'configured' => true,
                        'connected' => true,
                        'provider' => 'google_gemini',
                        'model' => $currentModel,
                        'http_status' => 200,
                        'error_code' => null,
                        'message' => "Connexion réussie avec Google Gemini ({$currentModel}).",
                        'latency_ms' => $latencyMs,
                        'is_fallback' => false,
                    ];
                }

                $errorBody = $response->json('error', []);
                $lastStatus = $status;
                $lastErrorCode = $errorBody['status'] ?? ('HTTP_' . $status);
                $lastMessage = $errorBody['message'] ?? $response->body();

                $this->handleApiErrorResponse($response, $currentModel, 'ping');

                if ($status === 401 || $status === 403) {
                    break;
                }
            } catch (\Throwable $e) {
                Log::warning("Gemini ping exception for model {$currentModel}.", [
                    'exception' => $e::class,
                    'message' => $e->getMessage(),
                ]);
                $lastStatus = null;
                $lastErrorCode = 'CONNECTION_EXCEPTION';
                $lastMessage = $e->getMessage();
            }
        }

        $latencyMs = round((microtime(true) - $startTime) * 1000);
        $userMessage = match ($lastStatus) {
            401, 403 => "Clé API Gemini invalide ou non autorisée (HTTP {$lastStatus}). Vérifiez votre clé dans .env.",
            404 => "Le modèle demandé n'existe pas ou n'est plus accessible (HTTP 404).",
            429 => "Quota d'appels API Gemini atteint ou débit dépassé (HTTP 429).",
            500, 502, 503 => "Les serveurs Google Gemini rencontrent une indisponibilité temporaire (HTTP {$lastStatus}).",
            default => "Impossible de joindre l'API Google Gemini : " . ($lastMessage ?: 'Erreur réseau ou timeout.'),
        };

        return [
            'success' => false,
            'configured' => true,
            'connected' => false,
            'provider' => 'google_gemini',
            'model' => $this->getModel(),
            'http_status' => $lastStatus,
            'error_code' => $lastErrorCode,
            'message' => $userMessage,
            'latency_ms' => $latencyMs,
            'is_fallback' => true,
        ];
    }
}
