<?php

namespace App\Http\Controllers;

use App\Models\FootballMatch;
use App\Models\Market;
use App\Models\OddsSnapshot;
use App\Models\PaperBet;
use App\Services\Odds\OddsService;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

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
            ->where('match_date_utc', '>=', now()->utc())
            ->orderBy('match_date_utc', 'asc')
            ->get();

        $valueOpportunities = [];
        $oddsMovements = [];
        $marketId = Market::where('code', '1N2')->value('id');
        foreach ($upcoming as $match) {
            $pred = $match->officialPrediction;
            if (! $pred || $pred->is_abstained || ! $marketId) {
                continue;
            }

            $completeSets = $this->complete1N2SnapshotSets($match->oddsSnapshots, (int) $marketId);
            if ($completeSets->isEmpty()) {
                continue;
            }

            $latestSet = $completeSets->last();
            $openingSet = $completeSets
                ->where('bookmaker_id', $latestSet['bookmaker_id'])
                ->first();
            $latestOdds = $this->oddsServiceProbabilities($oddsService, $latestSet['snapshots']);
            $openingOdds = $this->oddsServiceProbabilities($oddsService, $openingSet['snapshots']);

            $checks = [
                ['sel' => 'HOME', 'label' => 'Victoire ' . $match->homeTeam->short_name, 'prob' => (float) $pred->prob_home, 'ci_low' => (float) ($pred->intervals_json['ci80']['home'][0] ?? $pred->prob_home - 0.04)],
                ['sel' => 'DRAW', 'label' => 'Match Nul', 'prob' => (float) $pred->prob_draw, 'ci_low' => (float) ($pred->intervals_json['ci80']['draw'][0] ?? $pred->prob_draw - 0.03)],
                ['sel' => 'AWAY', 'label' => 'Victoire ' . $match->awayTeam->short_name, 'prob' => (float) $pred->prob_away, 'ci_low' => (float) ($pred->intervals_json['ci80']['away'][0] ?? $pred->prob_away - 0.04)],
            ];

            foreach ($checks as $c) {
                $snapshot = $latestSet['snapshots'][$c['sel']];
                $marketProbability = $latestOdds['true_probabilities'][$c['sel']];
                $probabilityEdge = $c['prob'] - $marketProbability;
                $ev = $oddsService->evaluateValue($c['prob'], $c['ci_low'], (float) $snapshot->decimal_odds);

                $oddsMovements[] = [
                    'match' => $match,
                    'selection_label' => $c['label'],
                    'selection_code' => $c['sel'],
                    'bookmaker' => $snapshot->bookmaker->name ?? 'Marché',
                    'opening_odds' => (float) $openingSet['snapshots'][$c['sel']]->decimal_odds,
                    'current_odds' => (float) $snapshot->decimal_odds,
                    'opening_at' => $openingSet['timestamp'],
                    'current_at' => $latestSet['timestamp'],
                    'opening_probability' => $openingOdds['true_probabilities'][$c['sel']],
                    'current_probability' => $marketProbability,
                    'market_movement' => $marketProbability - $openingOdds['true_probabilities'][$c['sel']],
                    'model_probability' => $c['prob'],
                    'model_market_edge' => $probabilityEdge,
                ];

                if ($ev['has_value']) {
                    $valueOpportunities[] = [
                        'match' => $match,
                        'selection_code' => $c['sel'],
                        'selection_label' => $c['label'],
                        'bookmaker' => $snapshot->bookmaker->name ?? 'Marché',
                        'market_odds' => $ev['market_odds'],
                        'fair_odds' => $ev['fair_odds'],
                        'model_prob' => $ev['model_prob'],
                        'market_prob' => $marketProbability,
                        'model_market_edge' => $probabilityEdge,
                        'ev_pct' => $ev['ev_pct'],
                        'classification' => $ev['classification'],
                        'odds_snapshot_id' => $snapshot->id,
                    ];
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
            'oddsMovements',
            'calculatorResult'
        ));
    }

    private function complete1N2SnapshotSets(Collection $snapshots, int $marketId): Collection
    {
        return $snapshots
            ->where('market_id', $marketId)
            ->groupBy('bookmaker_id')
            ->flatMap(function ($bookmakerSnapshots, $bookmakerId) {
                return $bookmakerSnapshots
                    ->groupBy(fn (OddsSnapshot $snapshot): int => $snapshot->snapshot_time_utc->timestamp)
                    ->map(function ($snapshotGroup) use ($bookmakerId) {
                        $bySelection = $snapshotGroup->keyBy('selection');
                        if (! collect(['HOME', 'DRAW', 'AWAY'])->every(fn (string $selection): bool => $bySelection->has($selection))) {
                            return null;
                        }

                        return [
                            'bookmaker_id' => (int) $bookmakerId,
                            'timestamp' => $snapshotGroup->max(fn (OddsSnapshot $snapshot): int => $snapshot->snapshot_time_utc->timestamp),
                            'snapshots' => $bySelection,
                        ];
                    })
                    ->filter();
            })
            ->sortBy('timestamp')
            ->values();
    }

    private function oddsServiceProbabilities(OddsService $oddsService, Collection $snapshots): array
    {
        $result = $oddsService->removeMargin([
            (float) $snapshots['HOME']->decimal_odds,
            (float) $snapshots['DRAW']->decimal_odds,
            (float) $snapshots['AWAY']->decimal_odds,
        ]);

        return [
            'true_probabilities' => array_combine(
                ['HOME', 'DRAW', 'AWAY'],
                $result['true_probabilities']
            ),
        ];
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
