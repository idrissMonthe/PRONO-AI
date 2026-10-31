<?php

namespace App\Http\Controllers;

use App\Models\FootballMatch;
use App\Models\LlmInteraction;
use App\Services\AIExplanation\GeminiExplanationService;
use App\Services\Prediction\PredictionClient;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GeminiController extends Controller
{
    protected GeminiExplanationService $geminiService;
    protected PredictionClient $predictionClient;

    public function __construct(
        GeminiExplanationService $geminiService,
        PredictionClient $predictionClient
    ) {
        $this->geminiService = $geminiService;
        $this->predictionClient = $predictionClient;
    }

    /**
     * Obtenir l'état de la configuration et de la connectivité Gemini.
     */
    public function status(): JsonResponse
    {
        $isConfigured = $this->geminiService->isConfigured();
        $model = config('services.gemini.model', env('GEMINI_MODEL', 'gemini-2.5-flash'));

        return response()->json([
            'status' => 'ok',
            'configured' => $isConfigured,
            'provider' => 'Google Gemini',
            'model' => $model,
            'mode_default' => 'beginner',
            'anti_hallucination_active' => true,
        ]);
    }

    /**
     * Tester en direct la clé API Gemini avec un appel léger.
     */
    public function testConnection(): JsonResponse
    {
        if (!$this->geminiService->isConfigured()) {
            return response()->json([
                'success' => false,
                'configured' => false,
                'message' => 'Clé API Gemini non configurée dans .env (GEMINI_API_KEY).',
                'provider' => 'deterministic_template',
            ], 422);
        }

        $startTime = microtime(true);

        $testContext = [
            'home_team' => 'Test FC',
            'away_team' => 'Demo United',
            'expected_goals' => ['home' => 1.5, 'away' => 1.0],
            'probabilities' => [
                'home' => ['p' => 0.50],
                'draw' => ['p' => 0.28],
                'away' => ['p' => 0.22],
            ],
            'top_scores' => [['score' => '1-0', 'p' => 0.15]],
            'quality' => ['score' => 100],
        ];

        $result = $this->geminiService->explainMatch(
            $testContext,
            'beginner',
            'Peux-tu confirmer en une phrase courte que la connexion à l\'API fonctionne bien ?'
        );

        $latencyMs = round((microtime(true) - $startTime) * 1000);

        return response()->json([
            'success' => $result['provider'] === 'google_gemini',
            'configured' => true,
            'latency_ms' => $latencyMs,
            'provider' => $result['provider'],
            'model' => $result['model'] ?? config('services.gemini.model'),
            'verified' => $result['verified'] ?? true,
            'is_fallback' => $result['is_fallback'] ?? false,
            'sample_response' => $result['text'] ?? '',
        ]);
    }

    /**
     * Générer une explication complète pour un match ou un contexte statistique.
     */
    public function explain(Request $request): JsonResponse
    {
        $data = $request->validate([
            'match_id' => ['nullable', 'integer', 'min:1'],
            'mode' => ['nullable', 'in:beginner,expert'],
            'question' => ['nullable', 'string', 'max:1000'],
            'context' => ['nullable', 'array'],
            'home_team' => ['nullable', 'string', 'max:100'],
            'away_team' => ['nullable', 'string', 'max:100'],
            'xg_home' => ['nullable', 'numeric', 'between:0,8'],
            'xg_away' => ['nullable', 'numeric', 'between:0,8'],
            'prob_home' => ['nullable', 'numeric', 'between:0,1'],
            'prob_draw' => ['nullable', 'numeric', 'between:0,1'],
            'prob_away' => ['nullable', 'numeric', 'between:0,1'],
            'quality_score' => ['nullable', 'numeric', 'between:0,100'],
        ]);

        if ($request->has('context') && strlen((string) json_encode($data['context'])) > 20000) {
            return response()->json(['success' => false, 'error' => 'Le contexte d’analyse est trop volumineux.'], 422);
        }

        $mode = $data['mode'] ?? 'beginner';
        $question = $data['question'] ?? null;

        // Cas 1 : match_id fourni
        if (!empty($data['match_id'])) {
            $match = FootballMatch::with(['homeTeam', 'awayTeam', 'officialPrediction'])->find($data['match_id']);

            if (!$match) {
                return response()->json([
                    'success' => false,
                    'error' => 'Match introuvable avec l\'identifiant #' . $request->match_id,
                ], 404);
            }

            $context = $this->buildContextFromMatch($match);
        }
        // Cas 2 : contexte complet passé en JSON
        elseif (isset($data['context']) && is_array($data['context'])) {
            $context = $data['context'];
        }
        // Cas 3 : paramètres individuels
        else {
            $context = [
                'home_team' => $data['home_team'] ?? 'Équipe A',
                'away_team' => $data['away_team'] ?? 'Équipe B',
                'expected_goals' => [
                    'home' => (float)($data['xg_home'] ?? 1.45),
                    'away' => (float)($data['xg_away'] ?? 1.10),
                ],
                'probabilities' => [
                    'home' => ['p' => (float)($data['prob_home'] ?? 0.46)],
                    'draw' => ['p' => (float)($data['prob_draw'] ?? 0.27)],
                    'away' => ['p' => (float)($data['prob_away'] ?? 0.27)],
                ],
                'top_scores' => $request->input('top_scores', [['score' => '1-1', 'p' => 0.12]]),
                'quality' => ['score' => (float)($data['quality_score'] ?? 90.0)],
            ];
        }

        $explanation = $this->geminiService->explainMatch($context, $mode, $question);

        return response()->json([
            'success' => true,
            'mode' => $mode,
            'provider' => $explanation['provider'],
            'model' => $explanation['model'] ?? config('services.gemini.model'),
            'text' => $explanation['text'],
            'verified' => $explanation['verified'],
            'is_fallback' => $explanation['is_fallback'],
            'context' => $context,
        ]);
    }

    /**
     * Poser une question interactive ciblée à l'assistant statistique.
     */
    public function ask(Request $request): JsonResponse
    {
        $request->validate([
            'question' => 'required|string|max:1000',
            'match_id' => 'nullable|integer|min:1',
            'mode' => 'nullable|in:beginner,expert',
            'context' => 'nullable|array',
        ]);

        if ($request->has('context') && strlen((string) json_encode($request->input('context'))) > 20000) {
            return response()->json(['success' => false, 'error' => 'Le contexte d’analyse est trop volumineux.'], 422);
        }

        $question = $request->input('question');
        $mode = $request->input('mode', 'beginner');
        if (!in_array($mode, ['beginner', 'expert'])) {
            $mode = 'beginner';
        }

        if ($request->filled('match_id')) {
            $match = FootballMatch::with(['homeTeam', 'awayTeam', 'officialPrediction'])->find($request->match_id);
            if (!$match) {
                return response()->json([
                    'success' => false,
                    'error' => 'Match introuvable avec l\'identifiant #' . $request->match_id,
                ], 404);
            }

            $context = $this->buildContextFromMatch($match);
        } elseif ($request->has('context') && is_array($request->input('context'))) {
            $context = $request->input('context');
        } else {
            $context = $this->fallbackContext();
        }

        $res = $this->geminiService->explainMatch($context, $mode, $question);

        return response()->json([
            'success' => true,
            'question' => $question,
            'mode' => $mode,
            'provider' => $res['provider'],
            'model' => $res['model'] ?? config('services.gemini.model'),
            'text' => $res['text'],
            'verified' => $res['verified'],
            'is_fallback' => $res['is_fallback'],
        ]);
    }

    /**
     * Historique des interactions et audits anti-hallucination.
     */
    public function history(): JsonResponse
    {
        $logs = LlmInteraction::orderBy('interacted_at_utc', 'desc')
            ->take(20)
            ->get();

        return response()->json([
            'count' => $logs->count(),
            'interactions' => $logs,
        ]);
    }

    /**
     * Construire le contexte statistique sans jamais crasher sur un objet null.
     */
    protected function buildContextFromMatch(FootballMatch $match): array
    {
        $pred = $match->officialPrediction;

        if ($pred) {
            return [
                'match_id' => (string)$match->id,
                'home_team' => $match->homeTeam->name,
                'away_team' => $match->awayTeam->name,
                'expected_goals' => [
                    'home' => (float)$pred->home_lambda,
                    'away' => (float)$pred->away_lambda,
                    'total' => round((float)$pred->home_lambda + (float)$pred->away_lambda, 3),
                ],
                'probabilities' => [
                    'home' => ['p' => (float)$pred->prob_home, 'ci80' => $pred->intervals_json['ci80']['home'] ?? []],
                    'draw' => ['p' => (float)$pred->prob_draw, 'ci80' => $pred->intervals_json['ci80']['draw'] ?? []],
                    'away' => ['p' => (float)$pred->prob_away, 'ci80' => $pred->intervals_json['ci80']['away'] ?? []],
                ],
                'top_scores' => $pred->top_scores_json ?? [],
                'quality' => [
                    'score' => (float)($pred->quality_score ?? $match->quality_score ?? 85.0),
                    'tier' => $pred->quality_tier ?? $match->quality_tier ?? 'usable',
                ],
            ];
        }

        // Si aucune prédiction officielle pré-calculée, appel au client prédiction
        try {
            $sim = $this->predictionClient->predict([
                'match_id' => (string)$match->id,
                'home_team' => $match->homeTeam->name,
                'away_team' => $match->awayTeam->name,
                'is_neutral_ground' => (bool)$match->is_neutral_ground,
                'quality_score' => (float)($match->quality_score ?? 85.0),
            ]);

            return [
                'match_id' => (string)$match->id,
                'home_team' => $match->homeTeam->name,
                'away_team' => $match->awayTeam->name,
                'expected_goals' => $sim['expected_goals'],
                'probabilities' => $sim['probabilities'],
                'top_scores' => $sim['top_scores'],
                'quality' => $sim['quality'],
            ];
        } catch (Exception $e) {
            return [
                'match_id' => (string)$match->id,
                'home_team' => $match->homeTeam->name,
                'away_team' => $match->awayTeam->name,
                'expected_goals' => ['home' => 1.45, 'away' => 1.10, 'total' => 2.55],
                'probabilities' => [
                    'home' => ['p' => 0.46, 'ci80' => [0.41, 0.51]],
                    'draw' => ['p' => 0.27, 'ci80' => [0.23, 0.31]],
                    'away' => ['p' => 0.27, 'ci80' => [0.22, 0.32]],
                ],
                'top_scores' => [['score' => '1-1', 'p' => 0.12], ['score' => '1-0', 'p' => 0.11]],
                'quality' => ['score' => 85.0, 'tier' => 'usable'],
            ];
        }
    }

    protected function fallbackContext(): array
    {
        return [
            'home_team' => 'Équipe Domicile',
            'away_team' => 'Équipe Extérieur',
            'expected_goals' => ['home' => 1.5, 'away' => 1.1, 'total' => 2.6],
            'probabilities' => [
                'home' => ['p' => 0.47, 'ci80' => [0.42, 0.52]],
                'draw' => ['p' => 0.27, 'ci80' => [0.23, 0.31]],
                'away' => ['p' => 0.26, 'ci80' => [0.21, 0.31]],
            ],
            'top_scores' => [['score' => '1-1', 'p' => 0.12], ['score' => '2-1', 'p' => 0.10]],
            'quality' => ['score' => 90.0, 'tier' => 'usable'],
        ];
    }
}
