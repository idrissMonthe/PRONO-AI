<?php

namespace App\Http\Controllers;

use App\Models\FootballMatch;
use App\Models\OddsSnapshot;
use App\Models\PaperBet;
use App\Models\Prediction;
use App\Services\Integrity\HashChainService;
use App\Services\Odds\OddsService;
use App\Services\Prediction\PredictionClient;
use Carbon\Carbon;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function index(
        OddsService $oddsService,
        HashChainService $hashChainService,
        PredictionClient $predictionClient
    )
    {
        $today = Carbon::today();

        // 1. Core KPIs
        $totalMatches = FootballMatch::count();
        $predictedMatches = Prediction::where('is_official', true)->count();
        $readiness = $predictionClient->modelReadiness();
        $championModel = !empty($readiness['model_loaded'])
            ? (object) ['version_name' => $readiness['model_version'] ?? 'Version inconnue']
            : null;
        $upcomingCount = FootballMatch::whereIn('status', ['SCHEDULED', 'TIMED'])
            ->where('match_date_utc', '>=', now())
            ->count();

        // 2. Upcoming matches to monitor ("À surveiller" - CDC 4.2)
        $upcomingMatches = FootballMatch::with(['homeTeam', 'awayTeam', 'league', 'officialPrediction', 'oddsSnapshots'])
            ->whereIn('status', ['SCHEDULED', 'TIMED'])
            ->orderBy('match_date_utc', 'asc')
            ->take(6)
            ->get();

        // 3. Model vs Market Edges ("Écarts modèle/marché" - CDC 4.7 & 25.4)
        $edges = [];
        foreach ($upcomingMatches as $match) {
            $pred = $match->officialPrediction;
            $oddsList = $match->oddsSnapshots->where('is_closing', false);
            $homeOdds = $oddsList->where('selection', 'HOME')->first();

            if ($pred && $homeOdds && $homeOdds->decimal_odds > 1.0) {
                $ci80 = $pred->intervals_json['ci80']['home'][0] ?? ($pred->prob_home - 0.04);
                $eval = $oddsService->evaluateValue((float)$pred->prob_home, (float)$ci80, (float)$homeOdds->decimal_odds);

                if ($eval['has_value']) {
                    $edges[] = [
                        'match' => $match,
                        'selection' => 'Victoire ' . $match->homeTeam->name,
                        'model_prob' => $eval['model_prob'],
                        'market_odds' => $eval['market_odds'],
                        'fair_odds' => $eval['fair_odds'],
                        'ev_pct' => $eval['ev_pct'],
                        'classification' => $eval['classification'],
                    ];
                }
            }
        }

        // 4. Team Form trends ("Forme des équipes" - CDC 4.4)
        $recentFinished = FootballMatch::with(['homeTeam', 'awayTeam', 'league', 'officialPrediction'])
            ->where('status', 'FINISHED')
            ->orderBy('match_date_utc', 'desc')
            ->take(5)
            ->get();

        // 5. Model Health ("Santé du modèle" - CDC 4.7)
        $modelHealth = [
            'champion' => $championModel?->version_name ?? 'Aucun modèle entraîné',
            'algorithm' => !empty($readiness['model_loaded']) ? 'Dixon-Coles' : 'Fallback Poisson non entraîné',
            'rolling_rps' => null,
            'reference_baseline_rps' => null,
            'skill_score' => null,
            'calibration_ece' => null,
            'status' => 'Non évalué',
        ];

        // 6. Paper Trading Summary (CDC 25.7)
        $paperBets = PaperBet::all();
        $clvValues = $paperBets->pluck('clv')->filter(fn ($value) => $value !== null);
        $paperSummary = [
            'total_bets' => $paperBets->count(),
            'pending' => $paperBets->where('status', 'PENDING')->count(),
            'won' => $paperBets->where('status', 'WON')->count(),
            'lost' => $paperBets->where('status', 'LOST')->count(),
            'avg_odds' => $paperBets->isEmpty() ? null : round($paperBets->avg('placed_odds'), 2),
            'avg_clv' => $clvValues->isEmpty() ? null : round($clvValues->avg(), 4),
        ];

        $integrity = $hashChainService->verifyLedgerIntegrity();

        return view('dashboard', compact(
            'totalMatches',
            'predictedMatches',
            'championModel',
            'upcomingCount',
            'upcomingMatches',
            'edges',
            'recentFinished',
            'modelHealth',
            'paperSummary',
            'integrity'
        ));
    }
}

