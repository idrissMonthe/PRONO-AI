<?php

namespace App\Http\Controllers;

use App\Services\Odds\OddsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OddsApiController extends Controller
{
    public function devig(Request $request, OddsService $service): JsonResponse
    {
        $data = $request->validate([
            'odds' => 'required|array|min:2|max:20',
            'odds.*' => 'required|numeric|gt:1',
            'method' => 'sometimes|in:shin,power,proportional',
        ]);

        return response()->json($service->removeMargin($data['odds'], $data['method'] ?? 'shin'));
    }

    public function evaluateValue(Request $request, OddsService $service): JsonResponse
    {
        $data = $request->validate([
            'model_prob' => 'required|numeric|between:0,1',
            'ci80_low' => 'required|numeric|between:0,1',
            'market_odds' => 'required|numeric|gt:1',
        ]);

        return response()->json($service->evaluateValue(
            (float) $data['model_prob'],
            (float) $data['ci80_low'],
            (float) $data['market_odds']
        ));
    }

    public function calculateClv(Request $request, OddsService $service): JsonResponse
    {
        $data = $request->validate([
            'placed_odds' => 'required|numeric|gt:1',
            'closing_fair_odds' => 'required|numeric|gt:1',
        ]);

        return response()->json([
            'clv' => $service->calculateClv((float) $data['placed_odds'], (float) $data['closing_fair_odds']),
        ]);
    }

    public function calculateKellyStake(Request $request, OddsService $service): JsonResponse
    {
        $data = $request->validate([
            'prob' => 'required|numeric|between:0,1',
            'decimal_odds' => 'required|numeric|gt:1',
            'bankroll' => 'sometimes|numeric|gt:0',
            'fraction' => 'sometimes|numeric|between:0,0.25',
            'max_cap_pct' => 'sometimes|numeric|between:0,0.02',
        ]);

        return response()->json($service->calculateKellyStake(
            (float) $data['prob'],
            (float) $data['decimal_odds'],
            (float) ($data['bankroll'] ?? 1000),
            (float) ($data['fraction'] ?? 0.25),
            (float) ($data['max_cap_pct'] ?? 0.02)
        ));
    }
}
