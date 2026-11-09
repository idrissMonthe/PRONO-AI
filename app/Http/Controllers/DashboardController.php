<?php

namespace App\Http\Controllers;

use App\Models\FootballMatch;
use App\Models\PaperBet;
use App\Models\Prediction;
use App\Models\SystemAlert;
use App\Services\Integrity\HashChainService;
use App\Services\Odds\OddsService;
use App\Services\Parlay\ParlayService;
use App\Services\Prediction\PredictionClient;
use App\Services\Prediction\LiveFlashPickService;
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
        ParlayService $parlayService,
        LiveFlashPickService $liveFlashPickService
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
        $liveMatches = FootballMatch::with(['homeTeam', 'awayTeam', 'league', 'officialPrediction', 'stats'])
            ->whereIn('status', ['LIVE', 'IN_PLAY', 'PAUSED'])
            ->whereNotNull('home_score')
            ->whereNotNull('away_score')
            ->where('last_synced_at', '>=', now()->utc()->subMinutes(3))
            ->get();
        $hasLiveMatches = $liveMatches->isNotEmpty();
        $liveFlashPicks = $liveMatches
            ->map(fn (FootballMatch $match): ?array => $liveFlashPickService->build($match))
            ->filter()
            ->sortByDesc('prob')
            ->values()
            ->all();
        $bigParlayPicks = $this->buildBigParlayPicks();
        $bigParlayProbabilities = collect($bigParlayPicks)->map(
            fn (array $picks): float => collect($picks)->reduce(
                fn (float $carry, array $pick): float => $carry * $pick['prob'],
                1.0
            )
        )->all();
        $bigParlayStatus = [
            'future_matches' => FootballMatch::whereIn('status', ['SCHEDULED', 'TIMED'])
                ->where('match_date_utc', '>=', now()->utc())
                ->count(),
            'official_predictions' => FootballMatch::whereIn('status', ['SCHEDULED', 'TIMED'])
                ->where('match_date_utc', '>=', now()->utc())
                ->whereHas('officialPrediction', fn ($query) => $query->where('is_official', true))
                ->count(),
            'validated_predictions' => FootballMatch::whereIn('status', ['SCHEDULED', 'TIMED'])
                ->where('match_date_utc', '>=', now()->utc())
                ->whereHas('officialPrediction', fn ($query) => $query
                    ->where('is_official', true)
                    ->where('is_abstained', false))
                ->count(),
        ];

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
        $systemAlerts = SystemAlert::where('status', 'open')->orderByDesc('severity')->orderByDesc('last_seen_at_utc')->take(3)->get();

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
            'liveFlashPicks',
            'hasLiveMatches',
            'bigParlayPicks',
            'bigParlayProbabilities',
            'bigParlayStatus',
            'dailyParlays',
            'edges',
            'recentFinished',
            'modelHealth',
            'paperSummary',
            'integrity',
            'systemAlerts',
            'nowDouala',
            'lastSyncFormatted'
        ));
    }

    /**
     * Construit des sélections par marché et par niveau de risque.
     * Les probabilités affichées proviennent toujours de la prédiction du modèle.
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
            if (! $prediction) {
                continue;
            }

            if ($prediction->published_market) {
                if ($prediction->is_abstained) continue;
                $published = $this->publishedPick($match, $prediction);
                if (! $published) continue;
                $risk = $published['prob'] >= 0.65 ? 'prudent' : ($published['prob'] >= 0.50 ? 'equilibre' : 'audacieux');
                $groups[$risk][] = array_merge($published, [
                    'match' => $match,
                    'source' => $prediction->prediction_origin === 'AI_ESTIMATE'
                        ? 'Estimation IA'
                        : ($prediction->prediction_origin === 'AI_ASSISTED_PYTHON' ? 'Hybride' : 'Révision Python'),
                ]);
                continue;
            }

            $source = 'Modèle';
            $sourceLabel = $prediction->is_abstained
                ? 'Données limitées'
                : ($prediction->prediction_origin === 'AI_ESTIMATE'
                    ? 'Estimation IA'
                    : ($prediction->prediction_origin === 'AI_ASSISTED_PYTHON' ? 'Hybride' : 'Statistiques Python'));
            $markets = $prediction->derived_markets_json ?? [];
            $supportsScoreMarkets = $this->supportsScoreMarketPicks($prediction);
            if (! $supportsScoreMarkets) {
                $markets = array_intersect_key($markets, ['1N2' => true, 'double_chance' => true]);
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
            $teamTotals = $markets['team_totals'] ?? [];
            $cleanSheet = $markets['clean_sheet'] ?? [];

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
                    ['key' => 'home_by_2_plus', 'label' => $home.' gagne par 2 buts ou plus', 'prob' => $goalMargin['home_by_2_plus'] ?? null, 'market' => 'Marge de buts'],
                    ['key' => 'away_by_2_plus', 'label' => $away.' gagne par 2 buts ou plus', 'prob' => $goalMargin['away_by_2_plus'] ?? null, 'market' => 'Marge de buts'],
                    ['key' => 'home_by_3_plus', 'label' => $home.' gagne par 3 buts ou plus', 'prob' => $goalMargin['home_by_3_plus'] ?? null, 'market' => 'Marge de buts'],
                    ['key' => 'away_by_3_plus', 'label' => $away.' gagne par 3 buts ou plus', 'prob' => $goalMargin['away_by_3_plus'] ?? null, 'market' => 'Marge de buts'],
                    ['key' => 'btts_yes', 'label' => 'Les deux équipes marquent', 'prob' => $btts['yes'] ?? null, 'market' => 'BTTS'],
                ],
            ];

            if ($source === 'Modèle') {
                $candidates['prudent'] = array_merge($candidates['prudent'], [
                    ['key' => 'home_over_0_5', 'label' => $home.' marque au moins 1 but', 'prob' => $teamTotals['home']['over_0_5'] ?? null, 'market' => 'Buts par équipe'],
                    ['key' => 'away_over_0_5', 'label' => $away.' marque au moins 1 but', 'prob' => $teamTotals['away']['over_0_5'] ?? null, 'market' => 'Buts par équipe'],
                    ['key' => 'home_clean_sheet', 'label' => $away.' ne marque pas', 'prob' => $cleanSheet['home'] ?? null, 'market' => 'Clean sheet'],
                    ['key' => 'away_clean_sheet', 'label' => $home.' ne marque pas', 'prob' => $cleanSheet['away'] ?? null, 'market' => 'Clean sheet'],
                ]);
                $candidates['equilibre'] = array_merge($candidates['equilibre'], [
                    ['key' => 'home_over_1_5', 'label' => $home.' marque au moins 2 buts', 'prob' => $teamTotals['home']['over_1_5'] ?? null, 'market' => 'Buts par équipe'],
                    ['key' => 'away_over_1_5', 'label' => $away.' marque au moins 2 buts', 'prob' => $teamTotals['away']['over_1_5'] ?? null, 'market' => 'Buts par équipe'],
                    ['key' => 'home_under_0_5', 'label' => $home.' ne marque pas', 'prob' => $teamTotals['home']['under_0_5'] ?? null, 'market' => 'Buts par équipe'],
                    ['key' => 'away_under_0_5', 'label' => $away.' ne marque pas', 'prob' => $teamTotals['away']['under_0_5'] ?? null, 'market' => 'Buts par équipe'],
                ]);
                $candidates['audacieux'] = array_merge($candidates['audacieux'], [
                    ['key' => 'home_over_2_5', 'label' => $home.' marque au moins 3 buts', 'prob' => $teamTotals['home']['over_2_5'] ?? null, 'market' => 'Buts par équipe'],
                    ['key' => 'away_over_2_5', 'label' => $away.' marque au moins 3 buts', 'prob' => $teamTotals['away']['over_2_5'] ?? null, 'market' => 'Buts par équipe'],
                ]);
            }

            $topScore = $prediction->top_scores_json[0] ?? null;
            if ($supportsScoreMarkets && $topScore && isset($topScore['p'], $topScore['score'])) {
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
                        'source' => $sourceLabel,
                    ]))
                    ->sortByDesc('prob')
                    ->values();

                $minimum = $risk === 'prudent' ? 0.65 : ($risk === 'equilibre' ? 0.50 : 0.18);
                $maximum = $risk === 'audacieux' ? 0.45 : 1.0;
                if ($risk === 'audacieux' && $source === 'Modèle') {
                    $minimum = 0.12;
                }
                $eligible = $options->filter(fn (array $option): bool => $option['prob'] >= $minimum
                    && $option['prob'] <= $maximum);
                $pick = $this->selectMarketOption($eligible, $risk, $source, (int) $match->id);
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

    private function publishedPick(FootballMatch $match, Prediction $prediction): ?array
    {
        $market = $prediction->published_market;
        $selection = $prediction->published_selection;
        $probability = (float) ($prediction->published_probability ?? 0);
        if (! $market || ! $selection || $probability <= 0 || $probability >= 1) return null;
        $home = $match->homeTeam->short_name;
        $away = $match->awayTeam->short_name;
        $label = match ($market) {
            '1N2' => ['home' => 'Victoire '.$home, 'draw' => 'Match nul', 'away' => 'Victoire '.$away][$selection] ?? null,
            'double_chance' => ['1X' => $home.' ou nul', 'X2' => $away.' ou nul', '12' => 'Pas de match nul'][$selection] ?? null,
            'btts' => ['yes' => 'Les deux équipes marquent', 'no' => 'Au moins une équipe ne marque pas'][$selection] ?? null,
            'exact_score' => 'Score exact '.$selection,
            'totals' => preg_match('/^(over|under)_(\d+)_(\d+)$/', $selection, $parts)
                ? ($parts[1] === 'over' ? 'Plus de ' : 'Moins de ').$parts[2].','.$parts[3].' buts' : null,
            default => null,
        };
        if (! $label) return null;
        return [
            'key' => $selection,
            'label' => $label,
            'prob' => $probability,
            'market' => match ($market) {
                '1N2' => 'Résultat', 'double_chance' => 'Double chance', 'btts' => 'BTTS', 'totals' => 'Total buts', 'exact_score' => 'Score exact', default => $market,
            },
        ];
    }

    /**
     * Construit trois versions de combinés, de la plus sûre à la plus risquée.
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
            if (! $prediction || $prediction->is_abstained) {
                return null;
            }

            if ($prediction->published_market) {
                $published = $this->publishedPick($match, $prediction);
                if (! $published) return null;
                $risk = $published['prob'] >= 0.65 ? 'prudent' : ($published['prob'] >= 0.50 ? 'equilibre' : 'audacieux');
                return [
                    'prudent' => $risk === 'prudent' ? array_merge($published, ['match' => $match, 'source' => 'Révision validée', 'league_key' => $match->league?->id ?? 'unknown']) : null,
                    'equilibre' => $risk === 'equilibre' ? array_merge($published, ['match' => $match, 'source' => 'Révision validée', 'league_key' => $match->league?->id ?? 'unknown']) : null,
                    'audacieux' => $risk === 'audacieux' ? array_merge($published, ['match' => $match, 'source' => 'Révision validée', 'league_key' => $match->league?->id ?? 'unknown']) : null,
                ];
            }

            $source = 'Modèle';
            $sourceLabel = 'Modèle';
            $markets = $prediction?->derived_markets_json ?? [];
            $supportsScoreMarkets = $this->supportsScoreMarketPicks($prediction);
            if (! $supportsScoreMarkets) {
                $markets = array_intersect_key($markets, ['1N2' => true, 'double_chance' => true]);
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
            $teamTotals = $markets['team_totals'] ?? [];
            $cleanSheet = $markets['clean_sheet'] ?? [];

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

            $safeOptions = array_merge($safeOptions, [
                    ['label' => 'Plus de 1,5 but', 'prob' => $totals['over_1_5'] ?? null, 'market' => 'Total buts'],
                    ['label' => 'Moins de 4,5 buts', 'prob' => $totals['under_4_5'] ?? null, 'market' => 'Total buts'],
                    ['label' => $home.' marque au moins 1 but', 'prob' => $teamTotals['home']['over_0_5'] ?? null, 'market' => 'Buts par équipe'],
                    ['label' => $away.' marque au moins 1 but', 'prob' => $teamTotals['away']['over_0_5'] ?? null, 'market' => 'Buts par équipe'],
                    ['label' => $away.' ne marque pas', 'prob' => $cleanSheet['home'] ?? null, 'market' => 'Clean sheet'],
                    ['label' => $home.' ne marque pas', 'prob' => $cleanSheet['away'] ?? null, 'market' => 'Clean sheet'],
            ]);
            $balancedOptions = array_merge($balancedOptions, [
                    ['label' => 'Plus de 2,5 buts', 'prob' => $totals['over_2_5'] ?? null, 'market' => 'Total buts'],
                    ['label' => 'Moins de 2,5 buts', 'prob' => $totals['under_2_5'] ?? null, 'market' => 'Total buts'],
                    ['label' => 'Les deux équipes marquent', 'prob' => $btts['yes'] ?? null, 'market' => 'BTTS'],
                    ['label' => $home.' marque au moins 2 buts', 'prob' => $teamTotals['home']['over_1_5'] ?? null, 'market' => 'Buts par équipe'],
                    ['label' => $away.' marque au moins 2 buts', 'prob' => $teamTotals['away']['over_1_5'] ?? null, 'market' => 'Buts par équipe'],
                    ['label' => $home.' ne marque pas', 'prob' => $teamTotals['home']['under_0_5'] ?? null, 'market' => 'Buts par équipe'],
                    ['label' => $away.' ne marque pas', 'prob' => $teamTotals['away']['under_0_5'] ?? null, 'market' => 'Buts par équipe'],
            ]);
            $combos = $markets['combos'] ?? [];
            $audaciousOptions = [
                    ['label' => 'Victoire '.$home, 'prob' => $oneN2['home'] ?? null, 'market' => '1N2'],
                    ['label' => 'Match nul', 'prob' => $oneN2['draw'] ?? null, 'market' => '1N2'],
                    ['label' => 'Victoire '.$away, 'prob' => $oneN2['away'] ?? null, 'market' => '1N2'],
                    ['label' => 'Les deux équipes marquent', 'prob' => $btts['yes'] ?? null, 'market' => 'BTTS'],
                    ['label' => 'Au moins une équipe ne marque pas', 'prob' => $btts['no'] ?? null, 'market' => 'BTTS'],
                    ['label' => 'Plus de 2,5 buts', 'prob' => $totals['over_2_5'] ?? null, 'market' => 'Total buts'],
                    ['label' => 'Moins de 2,5 buts', 'prob' => $totals['under_2_5'] ?? null, 'market' => 'Total buts'],
                    ['label' => 'Victoire '.$home.' + plus de 2,5 buts', 'prob' => $combos['home_win_over_2_5'] ?? null, 'market' => 'Combiné'],
                    ['label' => 'Victoire '.$away.' + plus de 2,5 buts', 'prob' => $combos['away_win_over_2_5'] ?? null, 'market' => 'Combiné'],
                    ['label' => 'Victoire '.$home.' + les deux marquent', 'prob' => $combos['home_win_btts_yes'] ?? null, 'market' => 'Combiné'],
                    ['label' => 'Victoire '.$away.' + les deux marquent', 'prob' => $combos['away_win_btts_yes'] ?? null, 'market' => 'Combiné'],
                    ['label' => $home.' gagne par 2 buts ou plus', 'prob' => $goalMargin['home_by_2_plus'] ?? null, 'market' => 'Marge de buts'],
                    ['label' => $away.' gagne par 2 buts ou plus', 'prob' => $goalMargin['away_by_2_plus'] ?? null, 'market' => 'Marge de buts'],
                    ['label' => $home.' gagne par 3 buts ou plus', 'prob' => $goalMargin['home_by_3_plus'] ?? null, 'market' => 'Marge de buts'],
                    ['label' => $away.' gagne par 3 buts ou plus', 'prob' => $goalMargin['away_by_3_plus'] ?? null, 'market' => 'Marge de buts'],
                    ['label' => $home.' marque au moins 3 buts', 'prob' => $teamTotals['home']['over_2_5'] ?? null, 'market' => 'Buts par équipe'],
                    ['label' => $away.' marque au moins 3 buts', 'prob' => $teamTotals['away']['over_2_5'] ?? null, 'market' => 'Buts par équipe'],
                    ['label' => 'Score exact '.($prediction->top_scores_json[0]['score'] ?? ''), 'prob' => $supportsScoreMarkets ? ($prediction->top_scores_json[0]['p'] ?? null) : null, 'market' => 'Score exact'],
            ];

            $pickForTier = function (
                array $options,
                float $minimum,
                float $maximum = 1.0
            ) use ($match, $source, $sourceLabel): ?array {
                $options = collect($options)
                    ->filter(fn (array $option) => is_numeric($option['prob'])
                        && (float) $option['prob'] >= $minimum
                        && (float) $option['prob'] <= $maximum)
                    ->map(fn (array $option) => array_merge($option, ['prob' => (float) $option['prob']]));
                $risk = $minimum >= 0.65 ? 'prudent' : ($maximum <= 0.45 ? 'audacieux' : 'equilibre');
                $best = $this->selectMarketOption($options, $risk, $source, (int) $match->id);

                return $best ? array_merge($best, [
                    'match' => $match,
                    'source' => $sourceLabel,
                    'league_key' => $match->league?->id ?? $match->league?->name ?? 'unknown',
                ]) : null;
            };

            return [
                'prudent' => $pickForTier($safeOptions, 0.65),
                'equilibre' => $pickForTier($balancedOptions, 0.30),
                'audacieux' => $pickForTier($audaciousOptions, $source === 'Modèle' ? 0.12 : 0.18, 0.45),
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

    private function selectMarketOption(Collection $options, string $risk, string $source, int $offset): ?array
    {
        if ($options->isEmpty()) {
            return null;
        }

        $marketOrder = match ($risk) {
            'prudent' => ['Total buts', 'Buts par équipe', 'Double chance', 'Clean sheet', '1N2'],
            'equilibre' => ['BTTS', 'Total buts', 'Buts par équipe', 'Combiné', '1N2', 'Résultat', 'Double chance'],
            default => ['Marge de buts', 'Combiné', 'Buts par équipe', 'BTTS', 'Total buts', '1N2', 'Résultat', 'Score exact'],
        };
        $rotation = $offset % count($marketOrder);
        $marketOrder = array_merge(array_slice($marketOrder, $rotation), array_slice($marketOrder, 0, $rotation));

        foreach ($marketOrder as $market) {
            $pick = $options->where('market', $market)->sortByDesc('prob')->first();
            if ($pick) {
                return $pick;
            }
        }

        return $options->sortByDesc('prob')->first();
    }

    private function supportsScoreMarketPicks(Prediction $prediction): bool
    {
        if ($prediction->is_abstained
            || ! in_array($prediction->confidence_tier, ['medium', 'high'], true)
            || (float) $prediction->quality_score < 70
            || (float) $prediction->home_lambda <= 0
            || (float) $prediction->away_lambda <= 0
            || (float) $prediction->truncated_residual_mass > 0.01) {
            return false;
        }

        return ! collect($prediction->warnings_json ?? [])->contains(
            fn ($warning): bool => str_starts_with((string) $warning, 'model_not_trained')
                || str_starts_with((string) $warning, 'untrained_poisson_fallback')
                || str_starts_with((string) $warning, 'unknown_or_promoted_team:')
                || $warning === 'expected_goals_clamped'
        );
    }

}
