<?php

namespace App\Http\Controllers;

use App\Models\FootballMatch;
use App\Models\Market;
use App\Models\OddsSnapshot;
use App\Models\PaperBet;
use App\Models\Prediction;
use App\Services\Integrity\HashChainService;
use App\Services\Odds\OddsService;
use App\Services\Parlay\ParlayService;
use App\Services\Prediction\PredictionClient;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function results(Request $request)
    {
        $data = $request->validate([
            'league' => ['nullable', 'string', 'max:100'],
            'date' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $query = FootballMatch::with(['homeTeam', 'awayTeam', 'league', 'officialPrediction'])
            ->where('status', 'FINISHED')
            ->whereNotNull('home_score')
            ->whereNotNull('away_score')
            ->orderByDesc('match_date_utc');

        if (!empty($data['league'])) {
            $query->whereHas('league', fn ($q) => $q->where('name', $data['league']));
        }

        if (!empty($data['date'])) {
            $query->whereDate('match_date_utc', $data['date']);
        }

        $results = $query->paginate(24)->withQueryString();

        return view('results.index', compact('results'));
    }

    public function index(
        OddsService $oddsService,
        HashChainService $hashChainService,
        PredictionClient $predictionClient,
        ParlayService $parlayService
    ) {
        $tz = 'Africa/Douala';
        $nowDouala = Carbon::now($tz);
        $todayDouala = $nowDouala->copy()->startOfDay();

        // 1. Core KPIs
        $totalMatches = FootballMatch::count();
        $predictedMatches = Prediction::where('is_official', true)->count();
        $readiness = $predictionClient->modelReadiness();
        $championModel = !empty($readiness['model_loaded'])
            ? (object) ['version_name' => $readiness['model_version'] ?? 'Version inconnue']
            : null;

        $upcomingCount = FootballMatch::whereIn('status', ['SCHEDULED', 'TIMED'])
            ->where('match_date_utc', '>=', now()->utc())
            ->count();

        // 2. Combinés du jour automatiques (évalués pour aujourd'hui en Africa/Douala)
        $dailyParlays = $parlayService->getOrGenerateDailyParlays($todayDouala);

        // 3. Prochains matchs à surveiller (uniquement rencontres futures réelles)
        $upcomingMatches = FootballMatch::with(['homeTeam', 'awayTeam', 'league', 'officialPrediction', 'oddsSnapshots'])
            ->whereIn('status', ['SCHEDULED', 'TIMED'])
            ->where('match_date_utc', '>=', now()->utc())
            ->orderBy('match_date_utc', 'asc')
            ->take(6)
            ->get();

        if ($upcomingMatches->isEmpty()) {
            // S'il n'y a aucun match futur dans l'immédiat, afficher les derniers matchs programmés
            $upcomingMatches = FootballMatch::with(['homeTeam', 'awayTeam', 'league', 'officialPrediction', 'oddsSnapshots'])
                ->whereIn('status', ['SCHEDULED', 'TIMED'])
                ->orderBy('match_date_utc', 'desc')
                ->take(6)
                ->get();
        }

        $oneN2MarketId = Market::where('code', '1N2')->value('id');
        $dailyPicks = $this->buildDailyPicks($upcomingMatches, $oddsService, $oneN2MarketId);
        $bigParlayPicks = $this->buildBigParlayPicks($oddsService, $oneN2MarketId);
        $bigParlayProbabilities = collect($bigParlayPicks)->map(
            fn (array $picks): float => collect($picks)->reduce(
                fn (float $carry, array $pick): float => $carry * $pick['prob'],
                1.0
            )
        )->all();

        // 4. Écarts modèle / marché (Value Bets)
        $edges = [];
        foreach ($upcomingMatches as $match) {
            $pred = $match->officialPrediction;
            $oddsList = $match->oddsSnapshots->where('is_closing', false);
            $homeOdds = $oddsList->where('selection', 'HOME')->first();

            if ($pred && ! $pred->is_abstained && $homeOdds && $homeOdds->decimal_odds > 1.0) {
                $ci80 = $pred->intervals_json['ci80']['home'][0] ?? ($pred->prob_home - 0.04);
                $eval = $oddsService->evaluateValue((float)$pred->prob_home, (float)$ci80, (float)$homeOdds->decimal_odds);

                if ($eval['has_value']) {
                    $edges[] = [
                        'match'          => $match,
                        'selection'      => 'Victoire ' . $match->homeTeam->name,
                        'model_prob'     => $eval['model_prob'],
                        'market_odds'    => $eval['market_odds'],
                        'fair_odds'      => $eval['fair_odds'],
                        'ev_pct'         => $eval['ev_pct'],
                        'classification' => $eval['classification'],
                    ];
                }
            }
        }

        // 5. Derniers matchs terminés (Forme globale)
        $recentFinished = FootballMatch::with(['homeTeam', 'awayTeam', 'league', 'officialPrediction'])
            ->where('status', 'FINISHED')
            ->orderBy('match_date_utc', 'desc')
            ->take(5)
            ->get();

        // 6. Santé du modèle
        $modelHealth = [
            'champion'               => $championModel?->version_name ?? 'M3_DIXON_COLES',
            'algorithm'              => !empty($readiness['model_loaded']) ? 'Dixon-Coles Bivarié' : 'Poisson / Dixon-Coles',
            'rolling_rps'            => null,
            'reference_baseline_rps' => null,
            'skill_score'            => null,
            'calibration_ece'        => null,
            'status'                 => 'Opérationnel',
        ];

        // 7. Résumé Paper Trading
        $paperBets = PaperBet::all();
        $clvValues = $paperBets->pluck('clv')->filter(fn ($value) => $value !== null);
        $paperSummary = [
            'total_bets' => $paperBets->count(),
            'pending'    => $paperBets->where('status', 'PENDING')->count(),
            'won'        => $paperBets->where('status', 'WON')->count(),
            'lost'       => $paperBets->where('status', 'LOST')->count(),
            'avg_odds'   => $paperBets->isEmpty() ? null : round($paperBets->avg('placed_odds'), 2),
            'avg_clv'    => $clvValues->isEmpty() ? null : round($clvValues->avg(), 4),
        ];

        $integrity = $hashChainService->verifyLedgerIntegrity();

        // Dernière synchronisation
        $lastSync = FootballMatch::whereNotNull('last_synced_at')->max('last_synced_at');
        $lastSyncFormatted = $lastSync ? Carbon::parse($lastSync)->timezone($tz)->format('d/m/Y à H:i') : null;

        return view('dashboard-public', compact(
            'totalMatches',
            'predictedMatches',
            'championModel',
            'upcomingCount',
            'upcomingMatches',
            'dailyPicks',
            'bigParlayPicks',
            'bigParlayProbabilities',
            'dailyParlays',
            'edges',
            'recentFinished',
            'modelHealth',
            'paperSummary',
            'integrity',
            'nowDouala',
            'lastSyncFormatted'
        ));
    }

    /**
     * Construit des sélections par marché et par niveau de risque.
     * Les abstentions ne peuvent utiliser que des résultats dérivés de cotes 1N2 complètes.
     */
    private function buildDailyPicks(Collection $matches, OddsService $oddsService, ?int $marketId): array
    {
        $groups = [
            'prudent' => [],
            'equilibre' => [],
            'audacieux' => [],
        ];

        foreach ($matches as $match) {
            $prediction = $match->officialPrediction;
            if (! $prediction) {
                continue;
            }

            $source = 'Modèle';
            $markets = $prediction->derived_markets_json ?? [];
            if ($prediction->is_abstained) {
                $marketFallback = $marketId ? $this->marketFallback($match, $oddsService, $marketId) : null;
                if (! $marketFallback) {
                    continue;
                }

                $markets = $marketFallback;
                $source = 'Marché sans marge';
            }
            $home = $match->homeTeam->short_name;
            $away = $match->awayTeam->short_name;
            $oneN2 = $markets['1N2'] ?? [
                'home' => (float) $prediction->prob_home,
                'draw' => (float) $prediction->prob_draw,
                'away' => (float) $prediction->prob_away,
            ];
            $totals = $markets['totals'] ?? [];
            $btts = $markets['btts'] ?? [];
            $doubleChance = $markets['double_chance'] ?? [];
            $combos = $markets['combos'] ?? [];
            $goalMargin = $markets['goal_margin'] ?? [];

            $candidates = $source === 'Marché sans marge' ? [
                'prudent' => [
                    ['key' => '1X', 'label' => $home.' ou nul', 'prob' => $doubleChance['1X'] ?? null, 'market' => 'Double chance'],
                    ['key' => 'X2', 'label' => $away.' ou nul', 'prob' => $doubleChance['X2'] ?? null, 'market' => 'Double chance'],
                ],
                'equilibre' => [
                    ['key' => 'home', 'label' => 'Victoire '.$home, 'prob' => $oneN2['home'] ?? null, 'market' => 'Résultat'],
                    ['key' => 'draw', 'label' => 'Match nul', 'prob' => $oneN2['draw'] ?? null, 'market' => 'Résultat'],
                    ['key' => 'away', 'label' => 'Victoire '.$away, 'prob' => $oneN2['away'] ?? null, 'market' => 'Résultat'],
                ],
                'audacieux' => [
                    ['key' => 'home', 'label' => 'Victoire '.$home, 'prob' => $oneN2['home'] ?? null, 'market' => 'Résultat'],
                    ['key' => 'draw', 'label' => 'Match nul', 'prob' => $oneN2['draw'] ?? null, 'market' => 'Résultat'],
                    ['key' => 'away', 'label' => 'Victoire '.$away, 'prob' => $oneN2['away'] ?? null, 'market' => 'Résultat'],
                ],
            ] : [
                'prudent' => [
                    ['key' => '1X', 'label' => $home.' ou nul', 'prob' => $doubleChance['1X'] ?? null, 'market' => 'Double chance'],
                    ['key' => 'X2', 'label' => $away.' ou nul', 'prob' => $doubleChance['X2'] ?? null, 'market' => 'Double chance'],
                    ['key' => 'over_1_5', 'label' => 'Plus de 1,5 but', 'prob' => $totals['over_1_5'] ?? null, 'market' => 'Total buts'],
                    ['key' => 'under_4_5', 'label' => 'Moins de 4,5 buts', 'prob' => $totals['under_4_5'] ?? null, 'market' => 'Total buts'],
                ],
                'equilibre' => [
                    ['key' => 'home', 'label' => 'Victoire '.$home, 'prob' => $oneN2['home'] ?? null, 'market' => 'Résultat'],
                    ['key' => 'draw', 'label' => 'Match nul', 'prob' => $oneN2['draw'] ?? null, 'market' => 'Résultat'],
                    ['key' => 'away', 'label' => 'Victoire '.$away, 'prob' => $oneN2['away'] ?? null, 'market' => 'Résultat'],
                    ['key' => 'over_2_5', 'label' => 'Plus de 2,5 buts', 'prob' => $totals['over_2_5'] ?? null, 'market' => 'Total buts'],
                    ['key' => 'under_2_5', 'label' => 'Moins de 2,5 buts', 'prob' => $totals['under_2_5'] ?? null, 'market' => 'Total buts'],
                    ['key' => 'btts_yes', 'label' => 'Les deux équipes marquent', 'prob' => $btts['yes'] ?? null, 'market' => 'BTTS'],
                    ['key' => 'btts_no', 'label' => 'Au moins une équipe ne marque pas', 'prob' => $btts['no'] ?? null, 'market' => 'BTTS'],
                    ['key' => 'home_win_over_1_5', 'label' => 'Victoire '.$home.' + plus de 1,5 but', 'prob' => $combos['home_win_over_1_5'] ?? null, 'market' => 'Combiné'],
                    ['key' => 'away_win_over_1_5', 'label' => 'Victoire '.$away.' + plus de 1,5 but', 'prob' => $combos['away_win_over_1_5'] ?? null, 'market' => 'Combiné'],
                ],
                'audacieux' => [
                    ['key' => 'home_win_over_2_5', 'label' => 'Victoire '.$home.' + plus de 2,5 buts', 'prob' => $combos['home_win_over_2_5'] ?? null, 'market' => 'Combiné'],
                    ['key' => 'away_win_over_2_5', 'label' => 'Victoire '.$away.' + plus de 2,5 buts', 'prob' => $combos['away_win_over_2_5'] ?? null, 'market' => 'Combiné'],
                    ['key' => 'home_win_btts_yes', 'label' => 'Victoire '.$home.' + les deux marquent', 'prob' => $combos['home_win_btts_yes'] ?? null, 'market' => 'Combiné'],
                    ['key' => 'away_win_btts_yes', 'label' => 'Victoire '.$away.' + les deux marquent', 'prob' => $combos['away_win_btts_yes'] ?? null, 'market' => 'Combiné'],
                    ['key' => 'home_by_2_plus', 'label' => $home.' gagne par 2 buts ou plus', 'prob' => $goalMargin['home_by_2_plus'] ?? null, 'market' => 'Marge de buts'],
                    ['key' => 'away_by_2_plus', 'label' => $away.' gagne par 2 buts ou plus', 'prob' => $goalMargin['away_by_2_plus'] ?? null, 'market' => 'Marge de buts'],
                    ['key' => 'home_by_3_plus', 'label' => $home.' gagne par 3 buts ou plus', 'prob' => $goalMargin['home_by_3_plus'] ?? null, 'market' => 'Marge de buts'],
                    ['key' => 'away_by_3_plus', 'label' => $away.' gagne par 3 buts ou plus', 'prob' => $goalMargin['away_by_3_plus'] ?? null, 'market' => 'Marge de buts'],
                    ['key' => 'btts_yes', 'label' => 'Les deux équipes marquent', 'prob' => $btts['yes'] ?? null, 'market' => 'BTTS'],
                ],
            ];

            $topScore = $prediction->top_scores_json[0] ?? null;
            if ($source === 'Modèle' && $topScore && isset($topScore['p'], $topScore['score'])) {
                $candidates['audacieux'][] = [
                    'key' => 'exact_score',
                    'label' => 'Score exact '.$topScore['score'],
                    'prob' => $topScore['p'],
                    'market' => 'Score exact',
                ];
            }

            foreach ($candidates as $risk => $options) {
                $options = collect($options)
                    ->filter(fn (array $option) => is_numeric($option['prob']))
                    ->map(fn (array $option) => array_merge($option, [
                        'match' => $match,
                        'prob' => (float) $option['prob'],
                        'source' => $source,
                    ]))
                    ->sortByDesc('prob')
                    ->values();

                $minimum = $risk === 'prudent' ? 0.65 : ($risk === 'equilibre' ? 0.50 : 0.18);
                $pick = $risk === 'audacieux'
                    ? $options->first(fn (array $option) => $option['market'] === 'Marge de buts' && $option['prob'] >= 0.08)
                    : null;
                $pick ??= $options->first(fn (array $option) => $option['prob'] >= $minimum);
                if ($pick) {
                    $groups[$risk][] = $pick;
                }
            }
        }

        foreach ($groups as $risk => $picks) {
            $groups[$risk] = collect($picks)
                ->sortByDesc('prob')
                ->unique(fn (array $pick) => $pick['match']->id)
                ->take(4)
                ->values()
                ->all();
        }

        return $groups;
    }

    /**
     * Construit trois versions de combinés, de la plus sûre à la plus risquée.
     */
    private function buildBigParlayPicks(OddsService $oddsService, ?int $marketId): array
    {
        $matches = FootballMatch::with(['homeTeam', 'awayTeam', 'league', 'officialPrediction', 'oddsSnapshots'])
            ->whereIn('status', ['SCHEDULED', 'TIMED'])
            ->where('match_date_utc', '>=', now()->utc())
            ->whereHas('officialPrediction', fn ($query) => $query->where('is_official', true))
            ->orderBy('match_date_utc')
            ->get();

        $candidates = $matches->map(function (FootballMatch $match) use ($oddsService, $marketId): ?array {
            $prediction = $match->officialPrediction;
            if (! $prediction) {
                return null;
            }

            $source = 'Modèle';
            $markets = $prediction?->derived_markets_json ?? [];
            if ($prediction->is_abstained) {
                $markets = $marketId ? $this->marketFallback($match, $oddsService, $marketId) : null;
                if (! $markets) {
                    return null;
                }
                $source = 'Marché sans marge';
            }

            $home = $match->homeTeam->short_name;
            $away = $match->awayTeam->short_name;
            $oneN2 = $markets['1N2'] ?? [
                'home' => (float) $prediction->prob_home,
                'draw' => (float) $prediction->prob_draw,
                'away' => (float) $prediction->prob_away,
            ];
            $doubleChance = $markets['double_chance'] ?? [];
            $totals = $markets['totals'] ?? [];
            $btts = $markets['btts'] ?? [];
            $goalMargin = $markets['goal_margin'] ?? [];

            $safeOptions = [
                ['label' => $home.' ou nul', 'prob' => $doubleChance['1X'] ?? null, 'market' => 'Double chance'],
                ['label' => $away.' ou nul', 'prob' => $doubleChance['X2'] ?? null, 'market' => 'Double chance'],
            ];
            $balancedOptions = [
                ['label' => 'Victoire '.$home, 'prob' => $oneN2['home'] ?? null, 'market' => '1N2'],
                ['label' => 'Match nul', 'prob' => $oneN2['draw'] ?? null, 'market' => '1N2'],
                ['label' => 'Victoire '.$away, 'prob' => $oneN2['away'] ?? null, 'market' => '1N2'],
            ];
            $audaciousOptions = [];

            if ($source === 'Modèle') {
                $safeOptions = array_merge($safeOptions, [
                    ['label' => 'Plus de 1,5 but', 'prob' => $totals['over_1_5'] ?? null, 'market' => 'Total buts'],
                    ['label' => 'Moins de 4,5 buts', 'prob' => $totals['under_4_5'] ?? null, 'market' => 'Total buts'],
                ]);
                $balancedOptions = array_merge($balancedOptions, [
                    ['label' => 'Plus de 2,5 buts', 'prob' => $totals['over_2_5'] ?? null, 'market' => 'Total buts'],
                    ['label' => 'Moins de 2,5 buts', 'prob' => $totals['under_2_5'] ?? null, 'market' => 'Total buts'],
                    ['label' => 'Les deux équipes marquent', 'prob' => $btts['yes'] ?? null, 'market' => 'BTTS'],
                ]);
                $combos = $markets['combos'] ?? [];
                $audaciousOptions = [
                    ['label' => 'Victoire '.$home.' + plus de 2,5 buts', 'prob' => $combos['home_win_over_2_5'] ?? null, 'market' => 'Combiné'],
                    ['label' => 'Victoire '.$away.' + plus de 2,5 buts', 'prob' => $combos['away_win_over_2_5'] ?? null, 'market' => 'Combiné'],
                    ['label' => 'Victoire '.$home.' + les deux marquent', 'prob' => $combos['home_win_btts_yes'] ?? null, 'market' => 'Combiné'],
                    ['label' => 'Victoire '.$away.' + les deux marquent', 'prob' => $combos['away_win_btts_yes'] ?? null, 'market' => 'Combiné'],
                    ['label' => $home.' gagne par 2 buts ou plus', 'prob' => $goalMargin['home_by_2_plus'] ?? null, 'market' => 'Marge de buts'],
                    ['label' => $away.' gagne par 2 buts ou plus', 'prob' => $goalMargin['away_by_2_plus'] ?? null, 'market' => 'Marge de buts'],
                    ['label' => $home.' gagne par 3 buts ou plus', 'prob' => $goalMargin['home_by_3_plus'] ?? null, 'market' => 'Marge de buts'],
                    ['label' => $away.' gagne par 3 buts ou plus', 'prob' => $goalMargin['away_by_3_plus'] ?? null, 'market' => 'Marge de buts'],
                    ['label' => 'Score exact '.($prediction->top_scores_json[0]['score'] ?? ''), 'prob' => $prediction->top_scores_json[0]['p'] ?? null, 'market' => 'Score exact'],
                ];
            } else {
                $leastLikelyOutcome = collect($balancedOptions)
                    ->filter(fn (array $option) => is_numeric($option['prob']))
                    ->sortBy('prob')
                    ->first();
                if ($leastLikelyOutcome) {
                    $audaciousOptions[] = $leastLikelyOutcome;
                }
            }

            $pickForTier = function (
                array $options,
                float $minimum,
                bool $lowest = false,
                ?string $preferredMarket = null
            ) use ($match, $source): ?array {
                $options = collect($options)
                    ->filter(fn (array $option) => is_numeric($option['prob']) && (float) $option['prob'] >= $minimum)
                    ->map(fn (array $option) => array_merge($option, ['prob' => (float) $option['prob']]));
                $preferred = $preferredMarket
                    ? $options->where('market', $preferredMarket)->sortByDesc('prob')->first()
                    : null;
                $best = $preferred ?? ($lowest ? $options->sortBy('prob')->first() : $options->sortByDesc('prob')->first());

                return $best ? array_merge($best, [
                    'match' => $match,
                    'source' => $source,
                    'league_key' => $match->league?->id ?? $match->league?->name ?? 'unknown',
                ]) : null;
            };

            return [
                'prudent' => $pickForTier($safeOptions, 0.65),
                'equilibre' => $pickForTier($balancedOptions, 0.30),
                'audacieux' => $pickForTier(
                    $audaciousOptions,
                    0.08,
                    $source === 'Marché sans marge',
                    $source === 'Modèle' ? 'Marge de buts' : null
                ),
            ];
        })->filter()->values();
        $tiers = ['prudent' => [], 'equilibre' => [], 'audacieux' => []];
        foreach ($candidates as $matchPicks) {
            foreach ($tiers as $risk => $picks) {
                if ($matchPicks[$risk]) {
                    $tiers[$risk][] = $matchPicks[$risk];
                }
            }
        }

        foreach ($tiers as $risk => $picks) {
            $tierCandidates = collect($picks)->sortByDesc('prob');
            $selected = $tierCandidates->groupBy('league_key')->map(fn (Collection $league) => $league->first())->values();

            if ($selected->count() < 10) {
                $used = $selected->pluck('match.id')->all();
                $selected = $selected->concat(
                    $tierCandidates->reject(fn (array $pick) => in_array($pick['match']->id, $used, true))
                        ->take(10 - $selected->count())
                );
            }

            $tiers[$risk] = $selected->sortBy('prob')->values()->all();
        }

        return $tiers;
    }

    private function marketFallback(FootballMatch $match, OddsService $oddsService, int $marketId): ?array
    {
        $snapshotSet = $oddsService->latestComplete1N2Set($match->oddsSnapshots, $marketId);
        if (! $snapshotSet) {
            return null;
        }

        $probabilities = $oddsService->proportionalProbabilities(
            collect(['HOME', 'DRAW', 'AWAY'])
                ->map(fn (string $selection): float => (float) $snapshotSet[$selection]->decimal_odds)
                ->all()
        );
        [$home, $draw, $away] = $probabilities;

        return [
            '1N2' => ['home' => $home, 'draw' => $draw, 'away' => $away],
            'double_chance' => ['1X' => $home + $draw, 'X2' => $draw + $away],
        ];
    }
}
