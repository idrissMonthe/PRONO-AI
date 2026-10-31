<?php

namespace App\Http\Controllers;

use App\Models\FootballMatch;
use App\Models\Market;
use App\Models\OddsSnapshot;
use App\Models\PaperBet;
use App\Services\Odds\OddsService;
use Illuminate\Http\Request;

class OddsMarketController extends Controller
{
    public function index(Request $request, OddsService $oddsService)
    {
        // 1. Paper bets ledger
        $paperBets = PaperBet::with('match.homeTeam', 'match.awayTeam')->orderBy('placed_at_utc', 'desc')->get();

        $totalBets = $paperBets->count();
        $settled = $paperBets->whereIn('status', ['WON', 'LOST']);
        $totalProfit = $settled->sum('virtual_profit');
        $totalStaked = $settled->sum('stake_units');
        $yield = $totalStaked > 0 ? round(($totalProfit / $totalStaked) * 100, 2) : null;
        $avgOdds = $paperBets->isEmpty() ? null : round($paperBets->avg('placed_odds'), 2);
        $clvValues = $paperBets->pluck('clv')->filter(fn ($value) => $value !== null);
        $avgClv = $clvValues->isEmpty() ? null : round($clvValues->avg(), 4);

        // 2. Value Opportunities (CDC 25.6)
        $upcoming = FootballMatch::with(['homeTeam', 'awayTeam', 'officialPrediction', 'oddsSnapshots.bookmaker'])
            ->whereIn('status', ['SCHEDULED', 'TIMED'])
            ->orderBy('match_date_utc', 'asc')
            ->get();

        $valueOpportunities = [];
        foreach ($upcoming as $match) {
            $pred = $match->officialPrediction;
            if (!$pred || $pred->is_abstained) continue;

            $odds = $match->oddsSnapshots->where('is_closing', false)
                ->sortByDesc(fn ($odd) => ($odd->source === 'the-odds-api' ? 10000000000 : 0) + ($odd->snapshot_time_utc?->timestamp ?? 0));
            $homeOdds = $odds->where('selection', 'HOME')->first();
            $drawOdds = $odds->where('selection', 'DRAW')->first();
            $awayOdds = $odds->where('selection', 'AWAY')->first();

            $checks = [
                ['sel' => 'HOME', 'label' => 'Victoire ' . $match->homeTeam->short_name, 'prob' => (float)$pred->prob_home, 'ci_low' => (float)($pred->intervals_json['ci80']['home'][0] ?? $pred->prob_home - 0.04), 'oddsObj' => $homeOdds],
                ['sel' => 'DRAW', 'label' => 'Match Nul', 'prob' => (float)$pred->prob_draw, 'ci_low' => (float)($pred->intervals_json['ci80']['draw'][0] ?? $pred->prob_draw - 0.03), 'oddsObj' => $drawOdds],
                ['sel' => 'AWAY', 'label' => 'Victoire ' . $match->awayTeam->short_name, 'prob' => (float)$pred->prob_away, 'ci_low' => (float)($pred->intervals_json['ci80']['away'][0] ?? $pred->prob_away - 0.04), 'oddsObj' => $awayOdds],
            ];

            foreach ($checks as $c) {
                if ($c['oddsObj'] && $c['oddsObj']->decimal_odds > 1.0) {
                    $eval = $oddsService->evaluateValue($c['prob'], $c['ci_low'], (float)$c['oddsObj']->decimal_odds);
                    if ($eval['has_value']) {
                        $valueOpportunities[] = [
                            'match' => $match,
                            'selection_code' => $c['sel'],
                            'selection_label' => $c['label'],
                            'bookmaker' => $c['oddsObj']->bookmaker->name ?? 'Pinnacle',
                            'market_odds' => $eval['market_odds'],
                            'fair_odds' => $eval['fair_odds'],
                            'model_prob' => $eval['model_prob'],
                            'ev_pct' => $eval['ev_pct'],
                            'classification' => $eval['classification'],
                            'odds_snapshot_id' => $c['oddsObj']->id,
                        ];
                    }
                }
            }
        }

        // 3. Devigging Calculator Initial Values
        $calculatorResult = null;
        if ($request->filled('odds_home') && $request->filled('odds_draw') && $request->filled('odds_away')) {
            $inputOdds = [(float)$request->odds_home, (float)$request->odds_draw, (float)$request->odds_away];
            $method = $request->get('devig_method', 'shin');
            $calculatorResult = $oddsService->removeMargin($inputOdds, $method);
        }

        return view('odds.index', compact(
            'paperBets',
            'totalBets',
            'yield',
            'avgOdds',
            'avgClv',
            'valueOpportunities',
            'calculatorResult'
        ));
    }

    public function recordPaperBet(Request $request, OddsService $oddsService)
    {
        $data = $request->validate([
            'match_id' => 'required|exists:matches,id',
            'market' => 'required|in:1N2',
            'selection' => 'required|in:HOME,DRAW,AWAY',
            'odds_snapshot_id' => 'required|exists:odds_snapshots,id',
        ]);

        $match = FootballMatch::with('officialPrediction')->findOrFail($data['match_id']);
        if (!in_array($match->status, ['SCHEDULED', 'TIMED'], true) || $match->match_date_utc->isPast()) {
            return redirect()->route('odds.index')->with('error', 'Un pronostic virtuel ne peut être créé que pour une rencontre à venir.');
        }

        $pred = $match->officialPrediction;
        if (!$pred || $pred->is_abstained) {
            return redirect()->route('odds.index')->with('error', 'Aucune prédiction officielle non abstentionniste n’est disponible pour cette rencontre.');
        }

        $placedSnapshot = OddsSnapshot::whereKey($data['odds_snapshot_id'])
            ->where('match_id', $match->id)
            ->where('selection', $data['selection'])
            ->where('is_closing', false)
            ->whereHas('market', fn ($query) => $query->where('code', $data['market']))
            ->first();

        if (!$placedSnapshot || $placedSnapshot->decimal_odds <= 1.0) {
            return redirect()->route('odds.index')->with('error', 'La cote sélectionnée ne correspond pas à un relevé valide de cette rencontre.');
        }

        $placedOdds = (float) $placedSnapshot->decimal_odds;
        $stakeUnits = 1.0;
        $rule = 'flat_1u';

        $closingFairOdds = null;
        $closingOdds = null;
        $marketId = Market::where('code', $data['market'])->value('id');
        $closingSnapshots = $match->oddsSnapshots()
            ->where('market_id', $marketId)
            ->where('is_closing', true)
            ->orderByDesc('snapshot_time_utc')
            ->get()
            ->groupBy('bookmaker_id');

        foreach ($closingSnapshots as $snapshotsByBookmaker) {
            $snapshotsBySelection = $snapshotsByBookmaker->keyBy('selection');
            if (!$snapshotsBySelection->has('HOME') || !$snapshotsBySelection->has('DRAW') || !$snapshotsBySelection->has('AWAY')) {
                continue;
            }

            $closingOdds = [
                'HOME' => (float) $snapshotsBySelection['HOME']->decimal_odds,
                'DRAW' => (float) $snapshotsBySelection['DRAW']->decimal_odds,
                'AWAY' => (float) $snapshotsBySelection['AWAY']->decimal_odds,
            ];
            if (min($closingOdds) <= 1.0) {
                $closingOdds = null;
                continue;
            }

            $closingProbabilities = $oddsService->removeMargin(array_values($closingOdds));
            $selectedIndex = array_search($data['selection'], ['HOME', 'DRAW', 'AWAY'], true);
            $fairProbability = $closingProbabilities['true_probabilities'][$selectedIndex] ?? 0.0;
            if ($fairProbability > 0.0) {
                $closingFairOdds = 1.0 / $fairProbability;
                break;
            }

            $closingOdds = null;
        }

        $clv = $closingFairOdds !== null
            ? $oddsService->calculateClv($placedOdds, $closingFairOdds)
            : null;

        PaperBet::create([
            'prediction_id' => $pred->id,
            'match_id' => $match->id,
            'market' => $data['market'],
            'selection' => $data['selection'],
            'placed_odds' => $placedOdds,
            'closing_odds' => $closingOdds[$data['selection']] ?? null,
            'odds_snapshot_id' => $placedSnapshot->id,
            'staking_rule' => $rule,
            'stake_units' => $stakeUnits,
            'status' => 'PENDING',
            'clv' => $clv,
            'placed_at_utc' => now()->utc(),
        ]);

        return redirect()->route('odds.index')->with('success', 'Pronostic virtuel enregistré dans le registre immuable.');
    }
}

