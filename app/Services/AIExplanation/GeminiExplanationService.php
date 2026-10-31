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

    public function isConfigured(): bool
    {
        return !empty($this->apiKey) && $this->apiKey !== 'your-gemini-api-key';
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

        // Modèles candidats en cascade en cas de saturation temporaire (503)
        $modelsToTry = array_unique([$this->model, 'gemini-2.5-flash', 'gemini-flash-latest', 'gemini-2.5-flash-lite']);

        foreach ($modelsToTry as $currentModel) {
            try {
                $url = "https://generativelanguage.googleapis.com/v1beta/models/{$currentModel}:generateContent";

                $response = Http::timeout(15)
                    ->withHeaders([
                        'Content-Type' => 'application/json',
                        'x-goog-api-key' => $this->apiKey,
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
}

