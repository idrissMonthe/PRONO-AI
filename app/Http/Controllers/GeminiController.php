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
        $ping = $this->geminiService->ping();

        $httpCode = 200;
        if (! $ping['success']) {
            if (! $ping['configured']) {
                $httpCode = 422;
            } elseif (in_array($ping['http_status'], [401, 403], true)) {
                $httpCode = 401;
            } elseif ($ping['http_status'] === 429) {
                $httpCode = 429;
            } else {
                $httpCode = 502;
            }
        }

        return response()->json([
            'success'     => (bool) $ping['success'],
            'configured'  => (bool) $ping['configured'],
            'connected'   => (bool) ($ping['connected'] ?? false),
            'provider'    => (string) ($ping['provider'] ?? 'google_gemini'),
            'model'       => (string) ($ping['model'] ?? config('services.gemini.model', 'gemini-3.8-flash')),
            'http_status' => $ping['http_status'],
            'error_code'  => $ping['error_code'],
            'message'     => (string) $ping['message'],
            'latency_ms'  => $ping['latency_ms'] ?? null,
            'is_fallback' => (bool) ($ping['is_fallback'] ?? false),
        ], $httpCode);
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
            'top_scores' => ['nullable', 'array', 'max:50'],
            'top_scores.*.score' => ['required_with:top_scores', 'string', 'max:10'],
            'top_scores.*.p' => ['required_with:top_scores', 'numeric', 'between:0,1'],
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
                'home_team' => $data['home_team'] ?? null,
                'away_team' => $data['away_team'] ?? null,
                'expected_goals' => [
                    'home' => is_numeric($data['xg_home'] ?? null) ? (float) $data['xg_home'] : null,
                    'away' => is_numeric($data['xg_away'] ?? null) ? (float) $data['xg_away'] : null,
                ],
                'probabilities' => [
                    'home' => ['p' => is_numeric($data['prob_home'] ?? null) ? (float) $data['prob_home'] : null],
                    'draw' => ['p' => is_numeric($data['prob_draw'] ?? null) ? (float) $data['prob_draw'] : null],
                    'away' => ['p' => is_numeric($data['prob_away'] ?? null) ? (float) $data['prob_away'] : null],
                ],
                'top_scores' => is_array($data['top_scores'] ?? null) ? $data['top_scores'] : [],
                'quality' => ['score' => is_numeric($data['quality_score'] ?? null) ? (float) $data['quality_score'] : null],
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
                    'score' => is_numeric($pred->quality_score) ? (float) $pred->quality_score : (is_numeric($match->quality_score) ? (float) $match->quality_score : null),
                    'tier' => $pred->quality_tier ?? $match->quality_tier,
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
                'quality_score' => is_numeric($match->quality_score) ? (float) $match->quality_score : null,
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
                'expected_goals' => ['home' => null, 'away' => null, 'total' => null],
                'probabilities' => [
                    'home' => ['p' => null],
                    'draw' => ['p' => null],
                    'away' => ['p' => null],
                ],
                'top_scores' => [],
                'quality' => ['score' => is_numeric($match->quality_score) ? (float) $match->quality_score : null, 'tier' => $match->quality_tier],
                'missing_data' => ['prediction'],
            ];
        }
    }

    protected function fallbackContext(): array
    {
        return [
            'home_team' => null,
            'away_team' => null,
            'expected_goals' => ['home' => null, 'away' => null, 'total' => null],
            'probabilities' => [
                'home' => ['p' => null],
                'draw' => ['p' => null],
                'away' => ['p' => null],
            ],
            'top_scores' => [],
            'quality' => ['score' => null, 'tier' => null],
            'missing_data' => ['match', 'prediction'],
        ];
    }
}
