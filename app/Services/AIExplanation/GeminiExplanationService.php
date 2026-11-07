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
        $this->model = config('services.gemini.model', env('GEMINI_MODEL', 'gemini-2.5-flash'));
    }

    public function getApiKey(): ?string
    {
        return config('services.gemini.api_key') ?: ($this->apiKey ?: env('GEMINI_API_KEY'));
    }

    public function getModel(): string
    {
        return config('services.gemini.model') ?: ($this->model ?: env('GEMINI_MODEL', 'gemini-2.5-flash'));
    }

    public function isConfigured(): bool
    {
        $key = $this->getApiKey();
        return !empty($key) && $key !== 'your-gemini-api-key';
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
        $model = $this->getModel();

        // Modèles candidats en cascade en cas de saturation temporaire (503)
        $modelsToTry = array_unique([$model, 'gemini-2.5-flash', 'gemini-flash-latest', 'gemini-2.5-flash-lite']);

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
                            'thinkingConfig' => [
                                'thinkingBudget' => 0, // Réponse directe et instantanée
                            ],
                        ]
                    ]);

                if ($response->successful()) {
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
                } else {
                    Log::warning('Gemini API request failed.', [
                        'model' => $currentModel,
                        'status' => $response->status(),
                    ]);
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
            . "5. Cite UNIQUEMENT les chiffres et pourcentages exacts fournis dans le document JSON de contexte.";

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
        $pHome = round(($context['probabilities']['home']['p'] ?? 0.45) * 100, 1);
        $pDraw = round(($context['probabilities']['draw']['p'] ?? 0.27) * 100, 1);
        $pAway = round(($context['probabilities']['away']['p'] ?? 0.28) * 100, 1);
        $xGHome = $context['expected_goals']['home'] ?? 1.45;
        $xGAway = $context['expected_goals']['away'] ?? 1.10;
        $qScore = $context['quality']['score'] ?? 85;
        $scoreModal = $context['top_scores'][0]['score'] ?? '1-1';

        if ($mode === 'expert') {
            return "### Analyse Statistique Approfondie (Mode Expert)\n\n"
                . "- **Buts attendus (Dixon-Coles)** : $\\lambda_{\\text{dom}} = {$xGHome}$ contre $\\lambda_{\\text{ext}} = {$xGAway}$. Le volume offensif modélisé accorde l'ascendant à {$home}.\n"
                . "- **Distribution 1N2** : Victoire {$home} : **{$pHome}%** | Nul : **{$pDraw}%** | Victoire {$away} : **{$pAway}%**.\n"
                . "- **Score modal** : `{$scoreModal}` avec une probabilité résiduelle de queue inférieure à $10^{-6}$.\n"
                . "- **Indice de données** : Complétude estimée à **{$qScore}/100**. Les intervalles de crédibilité à 95% encadrent l'incertitude épistémique.\n"
                . "- **Avertissement méthodologique** : Tout décalage avec la cote de clôture du bookmaker peut résulter de paramètres non quantifiés (compositions de dernière minute, fatigue cumulée).";
        }

        $fav = $pHome >= $pAway ? $home : $away;
        $favProb = max($pHome, $pAway);

        return "### Synthèse Analytique du Match\n\n"
            . "Le modèle statistique attribue un avantage à **{$fav}** avec une probabilité estimée à **{$favProb}%** "
            . "(contre **{$pDraw}%** pour le match nul et **" . min($pHome, $pAway) . "%** pour l'adversaire).\n\n"
            . "En termes d'espérance de buts, la modélisation Poisson prévoit environ **{$xGHome}** but(s) pour {$home} et **{$xGAway}** pour {$away}. Le score le plus probable selon la matrice est **{$scoreModal}**.\n\n"
            . "> **Rappel de prudence** : Une probabilité de {$favProb}% implique que le résultat inverse ou nul survient dans plus d'un tiers des situations similaires. Les statistiques reflètent une tendance probabiliste, non une certitude sportive.";
    }

    /**
     * Analyse et évaluation du risque d'un combiné multi-sélections (CDC Section 9 & 28).
     */
    /**
     * Révision décisionnelle. Gemini choisit uniquement une candidate
     * calculée par Python et ne fournit jamais de probabilité.
     */
    public function reviewPrediction(array $context): array
    {
        $fallback = [
            'decision' => 'keep',
            'candidate_key' => $context['initial_candidate']['candidate_key'] ?? null,
            'reasoning' => 'Révision externe indisponible : la sélection statistique initiale est conservée.',
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
            "Tu es un réviseur critique de pronostics football.",
            "Tu ne calcules aucune probabilité et tu n'en inventes jamais.",
            "Tu peux uniquement choisir une candidate_key présente dans candidates.",
            "Réponds uniquement avec un objet JSON valide.",
            "Champs : decision (keep|replace|reject|insufficient_data), candidate_key (clé existante ou null), reasoning, supporting_factors (tableau), risk_factors (tableau), required_recalculation (booléen), confidence_in_revision (low|medium|high).",
            "Si aucune candidate ne convient, utilise reject ou insufficient_data.",
        ]);
        $prompt = "Données réelles du moteur Python :\n".json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)."\n\nExamine la sélection initiale de façon critique et ne choisis qu'une candidate existante.";
        $contextHash = hash('sha256', json_encode($context));
        $apiKey = $this->getApiKey();
        $modelsToTry = array_unique([$this->getModel(), 'gemini-2.5-flash', 'gemini-flash-latest', 'gemini-2.5-flash-lite']);

        foreach ($modelsToTry as $currentModel) {
            try {
                $response = Http::timeout(12)
                    ->withHeaders(['Content-Type' => 'application/json', 'x-goog-api-key' => $apiKey])
                    ->post("https://generativelanguage.googleapis.com/v1beta/models/{$currentModel}:generateContent", [
                        'contents' => [['role' => 'user', 'parts' => [['text' => $prompt]]]],
                        'systemInstruction' => ['parts' => [['text' => $systemInstruction]]],
                        'generationConfig' => [
                            'temperature' => 0.1,
                            'maxOutputTokens' => 600,
                            'responseMimeType' => 'application/json',
                            'thinkingConfig' => ['thinkingBudget' => 0],
                        ],
                    ]);

                if (! $response->successful()) continue;
                $parts = $response->json('candidates.0.content.parts', []);
                $text = trim(implode("\n", array_map(fn ($part) => $part['text'] ?? '', array_filter($parts, fn ($part) => empty($part['thought'])))));
                $decoded = $this->decodeReviewJson($text);
                if (! is_array($decoded)) continue;
                $decision = $decoded['decision'] ?? null;
                $candidateKey = $decoded['candidate_key'] ?? null;
                $candidateKeys = collect($context['candidates'] ?? [])->pluck('candidate_key')->filter()->all();
                if (! in_array($decision, ['keep', 'replace', 'reject', 'insufficient_data'], true)) continue;
                if ($decision === 'replace' && ! in_array($candidateKey, $candidateKeys, true)) continue;

                $normalized = [
                    'decision' => $decision,
                    'candidate_key' => $decision === 'keep' ? ($context['initial_candidate']['candidate_key'] ?? $candidateKey) : $candidateKey,
                    'reasoning' => is_string($decoded['reasoning'] ?? null) ? substr($decoded['reasoning'], 0, 2000) : '',
                    'supporting_factors' => array_values(array_filter((array) ($decoded['supporting_factors'] ?? []), 'is_string')),
                    'risk_factors' => array_values(array_filter((array) ($decoded['risk_factors'] ?? []), 'is_string')),
                    'required_recalculation' => (bool) ($decoded['required_recalculation'] ?? $decision === 'replace'),
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
            } catch (Exception $exception) {
                Log::warning('Gemini prediction review failed.', ['model' => $currentModel, 'exception' => $exception::class]);
            }
        }

        return $fallback;
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
            . "2. Rappelle que le produit des probabilités diminue rapidement la probabilité conjointe. "
            . "3. Identifie clairement la sélection qui présente la plus forte incertitude. "
            . "4. Si pricing_basis indique des cotes justes indicatives du modèle, précise qu'il ne s'agit pas de cotes bookmaker et que l'EV n'est pas calculée ; sinon, mentionne la source réelle des cotes. "
            . "5. Ne cite AUCUN chiffre absent du contexte JSON fourni. "
            . "Reste concis (3 à 4 paragraphes en français professionnel et clair).";

        $prompt = "Voici les données mathématiques réelles du combiné :\n"
            . json_encode($parlayContext, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
            . "\n\nFournis une évaluation lucide et transparente du risque de ce combiné.";

        $apiKey = $this->getApiKey();
        $model = $this->getModel();
        $modelsToTry = array_unique([$model, 'gemini-2.5-flash', 'gemini-flash-latest', 'gemini-2.5-flash-lite']);

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
                            'thinkingConfig' => ['thinkingBudget' => 0],
                        ],
                    ]);

                if ($response->successful()) {
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
            . "Ce combiné présente une cote totale cumulée de **{$combOdds}** pour une probabilité conjointe estimée à **{$combProb}%** par le modèle Dixon-Coles (indice de risque : **{$riskScore}/10**).\n\n"
            . "**Règle de multiplication des probabilités** : Même si chaque rencontre individuelle possède une probabilité dominante, l'accumulation de plusieurs événements indépendants divise la chance globale de réussite. À titre indicatif, une probabilité conjointe de {$combProb}% signifie que la combinaison échoue dans plus de " . round(100 - $combProb) . "% des cas théoriques.\n\n"
            . "> **Transparence éthique** : {$pricingNote} Ce combiné est un outil d'observation statistique pédagogique sans garantie de résultat.";
    }
}
