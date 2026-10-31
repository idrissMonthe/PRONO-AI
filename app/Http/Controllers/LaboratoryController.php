<?php

namespace App\Http\Controllers;

use App\Models\BacktestRun;
use App\Models\ModelPromotion;
use App\Models\ModelVersion;
use Illuminate\Http\Request;

class LaboratoryController extends Controller
{
    public function index()
    {
        $models = ModelVersion::orderBy('id', 'asc')->get();
        $champion = ModelVersion::where('status', 'champion')->first() ?? $models->first();

        // Keep model definitions visible, but do not claim metrics until measured.
        $benchmarks = [
            [
                'ref' => 'M0',
                'name' => 'Fréquences Empiriques Ligue',
                'family' => 'pure',
                'rps' => null,
                'log_loss' => null,
                'brier' => null,
                'ece' => null,
                'skill_vs_m0' => null,
                'dm_pvalue' => null,
                'status' => 'Non évalué',
            ],
            [
                'ref' => 'M1',
                'name' => 'Élo Dynamique + Avantage Terrain',
                'family' => 'pure',
                'rps' => null,
                'log_loss' => null,
                'brier' => null,
                'ece' => null,
                'skill_vs_m0' => null,
                'dm_pvalue' => null,
                'status' => 'Non évalué',
            ],
            [
                'ref' => 'M2',
                'name' => 'Poisson Indépendant Standard',
                'family' => 'pure',
                'rps' => null,
                'log_loss' => null,
                'brier' => null,
                'ece' => null,
                'skill_vs_m0' => null,
                'dm_pvalue' => null,
                'status' => 'Non évalué',
            ],
            [
                'ref' => 'M3',
                'name' => 'Dixon-Coles + Correction Tau + Décroissance Temporelle',
                'family' => 'pure',
                'rps' => null,
                'log_loss' => null,
                'brier' => null,
                'ece' => null,
                'skill_vs_m0' => null,
                'dm_pvalue' => null,
                'status' => 'Non évalué',
            ],
            [
                'ref' => 'M7',
                'name' => 'Probabilités de Marché Sans Marge (Shin Closing)',
                'family' => 'hybrid',
                'rps' => null,
                'log_loss' => null,
                'brier' => null,
                'ece' => null,
                'skill_vs_m0' => null,
                'dm_pvalue' => null,
                'status' => 'Non évalué',
            ],
        ];

        // Past Backtest Runs
        $backtests = BacktestRun::with('modelVersion')->orderBy('created_at', 'desc')->take(5)->get();

        // Promotion History
        $promotions = ModelPromotion::with(['sourceVersion', 'targetVersion'])->orderBy('decided_at', 'desc')->take(5)->get();

        return view('laboratory.index', compact(
            'models',
            'champion',
            'benchmarks',
            'backtests',
            'promotions'
        ));
    }

    public function runBacktest(Request $request)
    {
        return redirect()->route('laboratory.index')->with(
            'error',
            'Backtest indisponible : aucun pipeline walk-forward réel n’est encore configuré. Aucun résultat n’a été enregistré.'
        );
    }

    public function promoteModel(Request $request)
    {
        return redirect()->route('laboratory.index')->with(
            'error',
            'Promotion refusée : les critères de backtest, de significativité et de calibration ne sont pas mesurés.'
        );
    }
}

