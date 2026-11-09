<?php

namespace App\Http\Controllers;

use App\Models\PredictionEvaluation;
use App\Models\ModelVersion;
use App\Models\FootballMatch;
use App\Models\Prediction;
use Illuminate\Http\Request;

class PredictionPerformanceController extends Controller
{
    public function index(Request $request)
    {
        $data = $request->validate([
            'status' => ['nullable', 'in:WON,LOST'],
            'market' => ['nullable', 'string', 'max:30'],
        ]);

        $query = PredictionEvaluation::with(['match.homeTeam', 'match.awayTeam', 'match.league', 'prediction.modelVersion'])
            ->orderByDesc('evaluated_at_utc');

        if (! empty($data['status'])) {
            $query->where('status', $data['status']);
        }
        if (! empty($data['market'])) {
            $query->where('market_group', $data['market']);
        }

        $evaluations = $query->paginate(30)->withQueryString();
        $all = PredictionEvaluation::query();
        $total = (clone $all)->count();
        $won = (clone $all)->where('status', 'WON')->count();
        $lost = (clone $all)->where('status', 'LOST')->count();
        $rate = $total > 0 ? round(($won / $total) * 100, 1) : null;

        $marketStats = PredictionEvaluation::query()
            ->selectRaw("market_group, COUNT(*) as total, SUM(CASE WHEN status = 'WON' THEN 1 ELSE 0 END) as won")
            ->groupBy('market_group')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($row) => [
                'market' => $row->market_group,
                'total' => (int) $row->total,
                'won' => (int) $row->won,
                'rate' => $row->total > 0 ? round(($row->won / $row->total) * 100, 1) : 0,
            ]);

        $calibrationStats = ModelVersion::query()
            ->whereNotNull('validation_metrics')
            ->orderByDesc('updated_at')
            ->get()
            ->flatMap(function (ModelVersion $model): array {
                return collect($model->validation_metrics['markets'] ?? [])->map(fn (array $metrics, string $market) => [
                    'model' => $model->version_name,
                    'market' => $market,
                    'sample_size' => $metrics['sample_size'] ?? 0,
                    'brier' => $metrics['brier'] ?? null,
                    'log_loss' => $metrics['log_loss'] ?? null,
                    'ece' => $metrics['ece'] ?? null,
                ])->all();
            })->values();

        $coverageStats = FootballMatch::query()
            ->selectRaw('analysis_status, COUNT(*) as total')
            ->whereNotNull('analysis_status')
            ->groupBy('analysis_status')
            ->pluck('total', 'analysis_status');

        $originStats = Prediction::query()
            ->selectRaw('prediction_origin, COUNT(*) as total')
            ->where('is_official', true)
            ->groupBy('prediction_origin')
            ->pluck('total', 'prediction_origin');

        $hybridCoverage = [
            'matches' => FootballMatch::count(),
            'python_ready' => (int) ($coverageStats['PYTHON_READY'] ?? 0),
            'ai_assisted' => (int) ($coverageStats['AI_ASSISTED'] ?? 0),
            'hybrid_ready' => (int) ($coverageStats['HYBRID_READY'] ?? 0),
            'limited_data' => (int) ($coverageStats['LIMITED_DATA'] ?? 0),
            'no_analysis' => (int) ($coverageStats['NO_ANALYSIS'] ?? 0),
            'python_predictions' => (int) ($originStats['PYTHON_ESTIMATE'] ?? 0),
            'ai_predictions' => (int) ($originStats['AI_ESTIMATE'] ?? 0),
        ];

        return view('performance.index', compact('evaluations', 'total', 'won', 'lost', 'rate', 'marketStats', 'calibrationStats', 'hybridCoverage'));
    }
}
