<?php

namespace App\Http\Controllers;

use App\Services\Prediction\PredictionClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class PredictionApiController extends Controller
{
    public function health(PredictionClient $client): JsonResponse
    {
        $online = $client->checkHealth();

        return response()->json([
            'status' => $online ? 'ok' : 'unavailable',
            'service' => 'prediction',
        ], $online ? 200 : 503);
    }

    public function predict(Request $request, PredictionClient $client): JsonResponse
    {
        $payload = $request->validate([
            'match_id' => 'nullable|string|max:100',
            'home_team' => 'required|string|max:100',
            'away_team' => 'required|string|max:100',
            'is_neutral_ground' => 'sometimes|boolean',
            'quality_score' => 'sometimes|numeric|min:0|max:100',
            'lambda_home_override' => 'nullable|numeric|between:0.1,8',
            'lambda_away_override' => 'nullable|numeric|between:0.1,8',
        ]);

        $hasHomeOverride = isset($payload['lambda_home_override']);
        $hasAwayOverride = isset($payload['lambda_away_override']);
        if ($hasHomeOverride !== $hasAwayOverride) {
            return response()->json([
                'message' => 'Les deux lambdas doivent être fournies ensemble.',
                'errors' => [
                    'lambda_home_override' => ['Les deux lambdas doivent être fournies ensemble.'],
                    'lambda_away_override' => ['Les deux lambdas doivent être fournies ensemble.'],
                ],
            ], 422);
        }

        return response()->json($client->predict($payload));
    }

    public function evaluate(Request $request, PredictionClient $client): JsonResponse
    {
        $data = $request->validate([
            'items' => 'required|array|min:1|max:10000',
            'items.*.p_home' => 'required|numeric|between:0,1',
            'items.*.p_draw' => 'required|numeric|between:0,1',
            'items.*.p_away' => 'required|numeric|between:0,1',
            'items.*.outcome' => 'required|in:HOME,DRAW,AWAY,1,N,2',
        ]);

        foreach ($data['items'] as $index => $item) {
            if (abs($item['p_home'] + $item['p_draw'] + $item['p_away'] - 1.0) > 0.001) {
                return response()->json([
                    'message' => 'Les probabilités 1N2 doivent sommer à 1.',
                    'errors' => ["items.{$index}" => ['Les probabilités 1N2 doivent sommer à 1.']],
                ], 422);
            }
        }

        try {
            return response()->json($client->evaluate($data['items']));
        } catch (RuntimeException $exception) {
            return response()->json(['error' => $exception->getMessage()], 503);
        }
    }
}
