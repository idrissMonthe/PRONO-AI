<?php

namespace App\Http\Controllers;

use App\Models\FootballMatch;
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

        $dailyPicks = $this->buildDailyPicks($upcomingMatches);
        $bigParlayPicks = $this->buildBigParlayPicks();
        $bigParlayProbability = collect($bigParlayPicks)->reduce(
            fn (float $carry, array $pick): float => $carry * $pick['prob'],
            1.0
        );

        // 4. Écarts modèle / marché (Value Bets)
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
            'bigParlayProbability',
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
     * Les probabilités viennent directement de la matrice de scores du modèle.
     */
    private function buildDailyPicks(Collection $matches): array
    {
        $groups = [
            'prudent' => [],
            'equilibre' => [],
            'audacieux' => [],
        ];

        foreach ($matches as $match) {
            $prediction = $match->officialPrediction;
            if (! $prediction || $prediction->is_abstained) {
                continue;
            }

            $markets = $prediction->derived_markets_json ?? [];
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

            $candidates = [
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
                    ['key' => 'btts_yes', 'label' => 'Les deux équipes marquent', 'prob' => $btts['yes'] ?? null, 'market' => 'BTTS'],
                ],
            ];

            $topScore = $prediction->top_scores_json[0] ?? null;
            if ($topScore && isset($topScore['p'], $topScore['score'])) {
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
                    ]))
                    ->sortByDesc('prob')
                    ->values();

                $minimum = $risk === 'prudent' ? 0.65 : ($risk === 'equilibre' ? 0.50 : 0.18);
                $pick = $options->first(fn (array $option) => $option['prob'] >= $minimum);
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
     * Sélection longue destinée au gros combiné.
     * Une seule sélection par match, priorité aux marchés à forte probabilité.
     */
    private function buildBigParlayPicks(): array
    {
        $matches = FootballMatch::with(['homeTeam', 'awayTeam', 'league', 'officialPrediction'])
            ->whereIn('status', ['SCHEDULED', 'TIMED'])
            ->where('match_date_utc', '>=', now()->utc())
            ->whereHas('officialPrediction', fn ($query) => $query
                ->where('is_official', true)
                ->where('is_abstained', false))
            ->orderBy('match_date_utc')
            ->get();

        $candidates = $matches->map(function (FootballMatch $match): ?array {
            $prediction = $match->officialPrediction;
            $markets = $prediction?->derived_markets_json ?? [];
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

            $options = [
                ['label' => $home.' ou nul', 'prob' => $doubleChance['1X'] ?? null, 'market' => 'Double chance'],
                ['label' => $away.' ou nul', 'prob' => $doubleChance['X2'] ?? null, 'market' => 'Double chance'],
                ['label' => 'Plus de 1,5 but', 'prob' => $totals['over_1_5'] ?? null, 'market' => 'Total buts'],
                ['label' => 'Moins de 4,5 buts', 'prob' => $totals['under_4_5'] ?? null, 'market' => 'Total buts'],
                ['label' => 'Victoire '.$home, 'prob' => $oneN2['home'] ?? null, 'market' => '1N2'],
                ['label' => 'Victoire '.$away, 'prob' => $oneN2['away'] ?? null, 'market' => '1N2'],
                ['label' => 'Les deux équipes marquent', 'prob' => $btts['yes'] ?? null, 'market' => 'BTTS'],
            ];

            $best = collect($options)
                ->filter(fn (array $option) => is_numeric($option['prob']))
                ->map(fn (array $option) => array_merge($option, ['prob' => (float) $option['prob']]))
                ->sortByDesc('prob')
                ->first(fn (array $option) => $option['prob'] >= 0.55);

            if (! $best) return null;

            return [
                'match' => $match,
                'label' => $best['label'],
                'market' => $best['market'],
                'prob' => $best['prob'],
                'league_key' => $match->league?->id ?? $match->league?->name ?? 'unknown',
            ];
        })->filter()->sortByDesc('prob')->values();

        $diversified = $candidates->groupBy('league_key')->map(fn (Collection $league) => $league->first());
        $selected = $diversified->values();

        if ($selected->count() < 10) {
            $used = $selected->pluck('match.id')->all();
            $selected = $selected->concat(
                $candidates->reject(fn (array $pick) => in_array($pick['match']->id, $used, true))
                    ->take(10 - $selected->count())
            );
        }

        return $selected->sortBy('prob')->values()->all();
    }
}
