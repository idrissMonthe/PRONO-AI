<?php

namespace App\Http\Controllers;

use App\Services\FootballData\FootballDataService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FootballDataApiController extends Controller
{
    public function quality(Request $request, FootballDataService $service): JsonResponse
    {
        $data = $request->validate([
            'home_team' => 'nullable|string|max:100',
            'away_team' => 'nullable|string|max:100',
            'match_date_utc' => 'nullable|date',
            'odds' => 'sometimes|array|max:20',
            'odds.*' => 'required|numeric|gt:1',
        ]);

        $odds = $data['odds'] ?? [];
        unset($data['odds']);

        return response()->json($service->calculateQualityScore($data, $odds));
    }

    public function ingest(Request $request, FootballDataService $service): JsonResponse
    {
        $data = $request->validate([
            'home_team' => 'required|string|max:100',
            'away_team' => 'required|string|max:100',
            'date' => 'required|date',
            'league_code' => 'nullable|string|max:20',
            'league_name' => 'nullable|string|max:100',
            'country' => 'nullable|string|max:100',
            'season' => 'nullable|string|max:20',
            'status' => 'nullable|string|max:30',
            'home_score' => 'nullable|integer|min:0',
            'away_score' => 'nullable|integer|min:0',
            'ht_home_score' => 'nullable|integer|min:0',
            'ht_away_score' => 'nullable|integer|min:0',
            'is_neutral_ground' => 'sometimes|boolean',
            'round' => 'nullable|string|max:50',
            'odds' => 'sometimes|array|size:3',
            'odds.*' => 'required|numeric|gt:1',
            'source' => 'sometimes|string|max:100',
        ]);

        $source = $data['source'] ?? 'api_import';
        unset($data['source']);

        $match = $service->ingestMatchRecord($data, $source);
        if (! $match) {
            return response()->json([
                'status' => 'rejected',
                'message' => 'L’enregistrement n’a pas pu être ingéré et peut avoir été placé en quarantaine.',
            ], 422);
        }

        return response()->json([
            'status' => 'ingested',
            'match' => $match->load(['homeTeam', 'awayTeam', 'league']),
        ], 201);
    }
}
