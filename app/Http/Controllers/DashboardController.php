<?php

namespace App\Http\Controllers;

use App\Models\FootballMatch;
use App\Models\PaperBet;
use App\Models\Prediction;
use App\Models\SystemAlert;
use App\Services\Integrity\HashChainService;
use App\Services\Odds\OddsService;
use App\Services\Parlay\ParlayService;
use App\Services\Prediction\MarketNormalizationService;
use App\Services\Prediction\PredictionClient;
use App\Services\Prediction\PredictionRevisionService;
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
        // KPI par rencontre, pas par ligne d'historique : une seule prédiction
        // officielle est active par match depuis la correction d'intégrité.
        $predictedMatches = Prediction::where('is_official', true)->distinct()->count('match_id');
        $readiness = $predictionClient->modelReadiness();
        $championModel = !empty($readiness['model_loaded'])
            ? (object) ['version_name' => $readiness['model_version'] ?? 'Version inconnue']
            : null;

        $upcomingCount = FootballMatch::whereIn('status', ['SCHEDULED', 'TIMED'])
            ->where('match_date_utc', '>=', now()->utc())
            ->count();

        // 2. Combinés du jour automatiques (évalués pour aujourd'hui en Africa/Douala)
        $dailyParlays = $parlayService->getOrGenerateDailyParlays($todayDouala);

        // 3. Prochaines rencontres réelles (bassin des sélections du jour).
        // Le bassin ne doit pas être limité à six rencontres : une compétition
        // dont les six prochains matchs sont tous en attente laisserait la
        // rubrique vide alors que d'autres rencontres sont exploitables.
        $upcomingMatches = FootballMatch::with(['homeTeam', 'awayTeam', 'league', 'officialPrediction', 'oddsSnapshots'])
            ->whereIn('status', ['SCHEDULED', 'TIMED'])
            ->where('match_date_utc', '>=', now()->utc())
            ->orderBy('match_date_utc', 'asc')
            ->take(40)
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
                    ->when(
                        ! (bool) config('services.prediction.allow_limited_data', true),
                        fn ($limited) => $limited->where('is_abstained', false)
                    ))
                ->count(),
        ];

        // 4. Écarts modèle / marché (Value Bets)
        $edges = [];
        foreach ($upcomingMatches as $match) {
            $pred = $match->officialPrediction;
            $oddsList = $match->oddsSnapshots->where('is_closing', false);
            $homeOdds = $oddsList->where('selection', 'HOME')->first();

            if ($pred && ! $pred->is_abstained && $homeOdds && $homeOdds->decimal_odds > 1.0) {
                $ci80 = $pred->intervals_json['ci80']['home'][0] ?? null;
                if (! is_numeric($ci80)) {
                    continue;
                }
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

            // Python reste souverain : un verdict Gemini défavorable, une
            // validation en attente ou une indisponibilité technique ne
            // supprime jamais le pronostic Python. Le verdict accompagne la
            // sélection sous forme d'indicateur, il ne la remplace pas.

            // Les familles étendues (corners, cartons, périodes…) sont
            // classées par le moteur Python. Elles enrichissent les catégories
            // même si le pronostic officiel n'est pas affichable dans la carte
            // (market/selection non reconnu par le libellé historique).
            foreach ($this->extendedPicks($prediction, $match) as $extendedRisk => $extendedPick) {
                $groups[$extendedRisk][] = $extendedPick;
            }

            // Une prédiction abstentionniste reste affichée : elle utilise ses
            // marchés calculés et porte une étiquette de fiabilité limitée.
            if ($prediction->published_market && ! $prediction->is_abstained) {
                $published = $this->publishedPick($match, $prediction);
                if (! $published) continue;

                // Cartons/discipline : ne peuvent JAMAIS entrer dans "Les plus sûrs" (prudent)
                $isCard = MarketNormalizationService::isCardsMarket(
                    $prediction->published_market,
                    $prediction->published_family ?? null
                );
                if ($isCard) {
                    $risk = 'equilibre';
                } else {
                    $risk = $published['prob'] >= 0.65 ? 'prudent' : ($published['prob'] >= 0.50 ? 'equilibre' : 'audacieux');
                }

                $groups[$risk][] = array_merge($published, [
                    'match' => $match,
                    'market_family' => $prediction->published_family ?? ($isCard ? 'cards' : null),
                    'source' => $prediction->prediction_origin === 'AI_ESTIMATE'
                        ? 'Estimation IA'
                        : ($prediction->review_decision === PredictionRevisionService::STATUS_VALIDATED
                            ? 'Statistiques Python · revue Gemini : validé'
                            : ($prediction->prediction_origin === 'AI_ASSISTED_PYTHON' ? 'Hybride' : 'Statistiques Python')),
                ]);
                continue;
            }

            $source = 'Modèle';
            $sourceLabel = $this->dailySourceLabel($prediction);
            $markets = $prediction->derived_markets_json ?? [];
            $supportsScoreMarkets = $this->supportsScoreMarketPicks($prediction);
            if (! $supportsScoreMarkets) {
                $markets = array_intersect_key($markets, ['1N2' => true, 'double_chance' => true]);
            }
            $home = $this->displayTeamName($match->homeTeam->name ?? $match->homeTeam->short_name);
            $away = $this->displayTeamName($match->awayTeam->name ?? $match->awayTeam->short_name);
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
                    ['key' => 'under_3_5', 'label' => 'Moins de 3,5 buts', 'prob' => $totals['under_3_5'] ?? null, 'market' => 'Total buts'],
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

            // Sélections étendues déjà classées par le moteur de marché.
            foreach ($this->extendedPicks($prediction, $match) as $extendedRisk => $extendedPick) {
                $candidates[$extendedRisk][] = array_merge($extendedPick, [
                    'preclassified' => true,
                    'prob' => (float) $extendedPick['prob'],
                    'source' => $sourceLabel,
                ]);
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
                // Une sélection déjà classée par le moteur de marché garde sa
                // catégorie : le seuil mécanique ne la rétrograde pas.
                $preclassified = $options->firstWhere('preclassified', true);
                $eligible = $options->filter(fn (array $option): bool => isset($option['preclassified'])
                    || ($option['prob'] >= $minimum && $option['prob'] <= $maximum));
                $pick = $preclassified ?: $this->selectMarketOption($eligible, $risk, $source, (int) $match->id);
                if ($pick) {
                    $groups[$risk][] = $pick;
                }
            }
        }

        // Cartons/discipline : strictement exclus de "prudent" ("Les plus sûrs")
        // et plafonnés à 1 seule sélection au maximum sur l'ensemble du dashboard.
        $totalCardsCount = 0;
        foreach (['prudent', 'equilibre', 'audacieux'] as $risk) {
            $filtered = [];
            foreach ($groups[$risk] as $pick) {
                $isCard = MarketNormalizationService::isCardsMarket(
                    $pick['key'] ?? $pick['market'] ?? '',
                    $pick['market_family'] ?? null
                );
                if ($isCard) {
                    if ($risk === 'prudent') {
                        continue;
                    }
                    if ($totalCardsCount >= 1) {
                        continue;
                    }
                    $totalCardsCount++;
                }
                $filtered[] = $pick;
            }

            $groups[$risk] = collect($filtered)
                ->sortByDesc('prob')
                // Un même match peut apporter deux familles distinctes, jamais
                // la même sélection répétée.
                ->unique(fn (array $pick) => $pick['match']->id.'|'.($pick['market'] ?? ''))
                ->take(4)
                ->map(function (array $pick): array {
                    $prediction = $pick['match']->officialPrediction ?? null;
                    $pick['gemini'] = $prediction ? $this->geminiBadge($prediction) : [
                        'state' => 'not_evaluated',
                        'label' => 'Non évalué',
                    ];
                    $pick['gemini_alternative'] = $prediction ? $this->geminiAlternative($prediction) : null;

                    return $pick;
                })
                ->values()
                ->all();
        }

        return $groups;
    }

    /**
     * Les trois états de validation Gemini, sans jamais confondre une
     * indisponibilité technique avec un rejet statistique.
     *
     * @return array{state: string, label: string}
     */
    private function geminiBadge(Prediction $prediction): array
    {
        return match ((string) ($prediction->review_decision ?? '')) {
            PredictionRevisionService::STATUS_VALIDATED => [
                'state' => 'validated',
                'label' => 'Validé par Gemini',
            ],
            PredictionRevisionService::STATUS_WITH_ALTERNATIVE,
            PredictionRevisionService::STATUS_WITHOUT_ALTERNATIVE => [
                'state' => 'not_validated',
                'label' => 'Non validé par Gemini',
            ],
            // Échec technique, informations insuffisantes, ou revue non
            // effectuée : ce n'est pas un refus statistique.
            default => [
                'state' => 'not_evaluated',
                'label' => 'Non évalué par Gemini',
            ],
        };
    }

    /**
     * Proposition alternative de Gemini, lorsqu'elle existe. Elle ne remplace
     * jamais la sélection Python et ne porte aucune probabilité inventée.
     *
     * @return array{label: string, market: string, justification: string, origin: string}|null
     */
    private function geminiAlternative(Prediction $prediction): ?array
    {
        // Case-insensitive comparison to handle status variants
        if (strcasecmp((string) $prediction->review_decision, PredictionRevisionService::STATUS_WITH_ALTERNATIVE) !== 0) {
            return null;
        }

        $alternative = data_get($prediction->market_metrics_json, 'gemini_review.alternative');
        if (! is_array($alternative) || empty($alternative['selection'])) {
            return null;
        }

        return [
            'label' => (string) ($alternative['label'] ?? $alternative['selection']),
            'market' => (string) ($alternative['market'] ?? ''),
            'justification' => (string) ($alternative['justification'] ?? ''),
            'origin' => (string) ($alternative['origin'] ?? 'AI_ESTIMATE'),
        ];
    }

    /**
     * Une prédiction est « à fiabilité limitée » dès que l'abstention, la
     * qualité ou la confiance l'indiquent — pas uniquement en cas d'abstention.
     */
    private function isLimitedData(Prediction $prediction): bool
    {
        return $prediction->is_abstained
            || (float) $prediction->quality_score < 58
            || in_array($prediction->confidence_tier, ['low', 'insufficient'], true)
            || in_array($prediction->uncertainty_tier, ['high', 'insufficient'], true);
    }

    private function dailySourceLabel(Prediction $prediction): string
    {
        if ($this->isLimitedData($prediction)) {
            return 'Modèle · fiabilité limitée';
        }

        return match ($prediction->prediction_origin) {
            'AI_ESTIMATE' => 'Estimation IA',
            'AI_ASSISTED_PYTHON' => 'Hybride',
            default => 'Statistiques Python',
        };
    }

    /**
     * Sélections des familles étendues, déjà classées et fiabilisées par le
     * moteur Python. Aucune n'est recalculée ici.
     */
    private function extendedPicks(Prediction $prediction, FootballMatch $match): array
    {
        $categories = $prediction->derived_markets_json['market_categories'] ?? [];
        if (! is_array($categories)) {
            return [];
        }

        $picks = [];
        foreach (['prudent', 'equilibre', 'audacieux'] as $risk) {
            foreach (($categories[$risk] ?? []) as $item) {
                if (! is_array($item) || ! is_numeric($item['probability'] ?? null)) {
                    continue;
                }
                if (($item['coverage'] ?? 'none') === 'none') {
                    continue;
                }
                // Les cartons/discipline ne peuvent JAMAIS entrer dans la catégorie prudent ("Les plus sûrs")
                if ($risk === 'prudent' && MarketNormalizationService::isCardsMarket($item['market_id'] ?? '', $item['family'] ?? null)) {
                    continue;
                }
                $picks[$risk] = [
                    'key' => ($item['market_id'] ?? 'market').':'.($item['selection'] ?? ''),
                    'label' => (string) ($item['selection_label'] ?? $item['market_name'] ?? 'Marché'),
                    'prob' => (float) $item['probability'],
                    'odds' => null,
                    'market' => (string) ($item['market_name'] ?? $item['market_id'] ?? 'Marché'),
                    'market_family' => (string) ($item['family'] ?? ''),
                    'reliability' => $item['reliability'] ?? null,
                    'coverage' => $item['coverage'] ?? null,
                    'match' => $match,
                    'source' => 'Moteur marchés',
                ];
                break;
            }
        }

        return $picks;
    }

    private function publishedPick(FootballMatch $match, Prediction $prediction): ?array
    {
        $market = $prediction->published_market;
        $selection = $prediction->published_selection;
        $probability = (float) ($prediction->published_probability ?? 0);
        if (! $market || ! $selection || $probability <= 0 || $probability >= 1) return null;

        // Exclusion stricte des marchés triviaux/non rentables
        if (in_array($market, ['totals', 'total_goals'], true) && in_array($selection, ['over_0_5', 'under_0_5', 'over_5_5', 'under_5_5', 'under_4_5'], true)) {
            return null;
        }
        if ($probability > 0.82) {
            return null;
        }
        $home = $this->displayTeamName($match->homeTeam->name ?? $match->homeTeam->short_name);
        $away = $this->displayTeamName($match->awayTeam->name ?? $match->awayTeam->short_name);
        $label = match ($market) {
            '1N2', '1x2' => ['home' => 'Victoire '.$home, 'draw' => 'Match nul', 'away' => 'Victoire '.$away][$selection] ?? null,
            'double_chance' => ['1X' => $home.' ou nul', 'X2' => $away.' ou nul', '12' => 'Pas de match nul'][$selection] ?? null,
            'btts' => ['yes' => 'Les deux équipes marquent', 'no' => 'Au moins une équipe ne marque pas'][$selection] ?? null,
            'dnb', 'draw_no_bet' => ['home' => 'Victoire '.$home.' (Remboursé si nul)', 'away' => 'Victoire '.$away.' (Remboursé si nul)'][$selection] ?? null,
            'team_totals', 'team_total_goals' => preg_match('/^(HOME|AWAY|home|away)_(OVER|UNDER|over|under)_(\d+(?:\.\d+)?)$/i', $selection, $parts)
                ? ((strtoupper($parts[2]) === 'OVER') ? 'Plus de ' : 'Moins de ').str_replace('.', ',', $parts[3]).' buts — '.(strtoupper($parts[1]) === 'HOME' ? $home : $away)
                : null,
            'asian_handicap' => preg_match('/^AH_(HOME|AWAY)_(-?\d+(?:\.\d+)?)$/', $selection, $parts)
                ? ($parts[1] === 'HOME' ? $home : $away).' handicap '.($parts[2] > 0 ? '+' : '').rtrim(rtrim($parts[2], '0'), '.')
                : null,
            'european_handicap' => preg_match('/^EH_(HOME|AWAY|DRAW)_(-?\d+)$/', $selection, $parts)
                ? match($parts[1]) {
                    'HOME' => $home.' -'.abs((int)$parts[2]).' (Handicap européen)',
                    'AWAY' => $away.' -'.abs((int)$parts[2]).' (Handicap européen)',
                    'DRAW' => 'Nul handicap '.$parts[2],
                    default => null
                }
                : null,
            'exact_score' => 'Score exact '.$selection,
            'totals', 'total_goals' => preg_match('/^(over|under)_(\d+)(?:_(\d+))?$/', $selection, $parts)
                ? ($parts[1] === 'over' ? 'Plus de ' : 'Moins de ').$parts[2].(isset($parts[3]) ? ','.$parts[3] : '').' buts' : null,
            'half_time_result', 'ht_1x2' => ['home' => 'Mi-temps : '.$home.' mène', 'draw' => 'Mi-temps : égalité', 'away' => 'Mi-temps : '.$away.' mène'][$selection] ?? null,
            'half_time_totals', 'ht_total_goals' => preg_match('/^(over|under)_(\d+)(?:_(\d+))?(?:_ht)?$/', $selection, $parts)
                ? ($parts[1] === 'over' ? 'Plus de ' : 'Moins de ').$parts[2].(isset($parts[3]) ? ','.$parts[3] : '').' buts (MT)'
                : 'MT : '.str_replace('_', ' ', $selection),
            'corners', 'total_corners', 'corners_total' => preg_match('/^(over|under)_(\d+)(?:_(\d+))?$/', $selection, $parts)
                ? ($parts[1] === 'over' ? 'Plus de ' : 'Moins de ').$parts[2].(isset($parts[3]) ? ','.$parts[3] : '').' corners'
                : 'Corners : '.str_replace('_', ' ', $selection),
            'cards', 'total_cards', 'cards_total' => preg_match('/^(over|under)_(\d+)(?:_(\d+))?$/', $selection, $parts)
                ? ($parts[1] === 'over' ? 'Plus de ' : 'Moins de ').$parts[2].(isset($parts[3]) ? ','.$parts[3] : '').' cartons'
                : 'Cartons : '.str_replace('_', ' ', $selection),
            'shots', 'total_shots', 'shots_total' => preg_match('/^(over|under)_(\d+)(?:_(\d+))?$/', $selection, $parts)
                ? ($parts[1] === 'over' ? 'Plus de ' : 'Moins de ').$parts[2].(isset($parts[3]) ? ','.$parts[3] : '').' tirs'
                : 'Tirs : '.str_replace('_', ' ', $selection),
            'clean_sheet' => ['home' => $away.' ne marque pas', 'away' => $home.' ne marque pas'][$selection] ?? 'Clean sheet : '.str_replace('_', ' ', $selection),
            'win_to_nil' => ['home' => $home.' gagne sans encaisser', 'away' => $away.' gagne sans encaisser'][$selection] ?? null,
            'result_total_combo', 'result_btts_combo' => (string) ($selection),
            default => null,
        };
        if (! $label) {
            $label = MarketNormalizationService::getSelectionLabel(
                $market,
                $selection,
                $home,
                $away,
                $prediction->published_line ? (float) $prediction->published_line : null
            );
        }
        if (! $label) return null;

        // Cote bookmaker réelle uniquement : une cote estimée depuis la
        // probabilité n'est jamais affichée comme un prix de marché.
        $odds = null;
        $metrics = $prediction->market_metrics_json ?? [];
        if (($metrics['published_odds_source'] ?? null) === 'bookmaker'
            && isset($metrics['published_odds'])
            && is_numeric($metrics['published_odds'])) {
            $odds = (float) $metrics['published_odds'];
        }
        if (! $odds || $odds <= 1.01) {
            $odds = $this->lookupOddFromSnapshot($match, $market, $selection, (float) ($prediction->published_line ?? 0));
        }

        if ($odds && $odds < 1.25) {
            return null;
        }

        return [
            'key' => $selection,
            'label' => $label,
            'prob' => $probability,
            'odds' => $odds,
            'market' => match ($market) {
                '1N2', '1x2'         => 'Résultat',
                'double_chance'      => 'Double chance',
                'btts'               => 'BTTS',
                'totals', 'total_goals' => 'Total buts',
                'asian_handicap'     => 'Handicap asiatique',
                'european_handicap'  => 'Handicap européen',
                'dnb', 'draw_no_bet' => 'Draw No Bet',
                'team_totals', 'team_total_goals' => 'Total par équipe',
                'exact_score'        => 'Score exact',
                'half_time_result', 'ht_1x2' => 'Mi-temps 1X2',
                'half_time_totals', 'ht_total_goals' => 'Total buts MT',
                'corners', 'total_corners', 'corners_total' => 'Corners',
                'cards', 'total_cards', 'cards_total' => 'Cartons',
                'shots', 'total_shots', 'shots_total' => 'Tirs',
                'clean_sheet'        => 'Clean sheet',
                'win_to_nil'         => 'Win to nil',
                'result_total_combo' => 'Résultat + Buts',
                'result_btts_combo'  => 'Résultat + BTTS',
                default              => $market,
            },
        ];
    }

    private function lookupOddFromSnapshot(FootballMatch $match, string $market, string $selection, float $line = 0): ?float
    {
        // Les cotes sont lues par marché : « HOME » existe en 1N2 comme en
        // handicap, et mélanger les deux fausse le prix affiché.
        if ($market === '1N2') {
            $selMap = ['home' => 'HOME', 'draw' => 'DRAW', 'away' => 'AWAY'];
            $target = $selMap[$selection] ?? strtoupper($selection);
            $odd = $this->oddsForMarket($match, '1N2')->firstWhere('selection', $target);
            return $odd ? (float) $odd->decimal_odds : null;
        }

        if ($market === 'double_chance') {
            $real = $this->oddsForMarket($match, 'double_chance')->firstWhere('selection', $selection);
            if ($real) {
                return (float) $real->decimal_odds;
            }

            $h2h = $this->oddsForMarket($match, '1N2');
            $h = $h2h->firstWhere('selection', 'HOME');
            $d = $h2h->firstWhere('selection', 'DRAW');
            $a = $h2h->firstWhere('selection', 'AWAY');
            $o1 = $h ? (float) $h->decimal_odds : 0;
            $oX = $d ? (float) $d->decimal_odds : 0;
            $o2 = $a ? (float) $a->decimal_odds : 0;
            if ($selection === '1X' && $o1 > 1 && $oX > 1) {
                return round(1.0 / ((1.0 / $o1) + (1.0 / $oX)), 2);
            }
            if ($selection === 'X2' && $oX > 1 && $o2 > 1) {
                return round(1.0 / ((1.0 / $oX) + (1.0 / $o2)), 2);
            }
            if ($selection === '12' && $o1 > 1 && $o2 > 1) {
                return round(1.0 / ((1.0 / $o1) + (1.0 / $o2)), 2);
            }
        }

        if ($market === 'totals') {
            $type = str_starts_with($selection, 'over') ? 'OVER' : 'UNDER';
            $odd = $this->oddsForMarket($match, 'totals')->where('selection', $type)
                ->first(fn ($o) => abs((float) $o->line - $line) < 0.01);
            return $odd ? (float) $odd->decimal_odds : null;
        }

        if ($market === 'btts') {
            $target = $selection === 'yes' ? 'YES' : 'NO';
            $odd = $this->oddsForMarket($match, 'btts')->firstWhere('selection', $target);
            return $odd ? (float) $odd->decimal_odds : null;
        }

        if ($market === 'team_totals' && preg_match('/^(HOME|AWAY)_(OVER|UNDER)_(\d+(?:\.\d+)?)$/', $selection, $parts)) {
            $odd = $this->oddsForMarket($match, 'team_totals')
                ->where('selection', $parts[1].'_'.$parts[2])
                ->first(fn ($o) => abs((float) $o->line - (float) $parts[3]) < 0.01);
            return $odd ? (float) $odd->decimal_odds : null;
        }

        if ($market === 'asian_handicap' && preg_match('/^AH_(HOME|AWAY)_(-?\d+(?:\.\d+)?)$/', $selection, $parts)) {
            $odd = $this->oddsForMarket($match, 'asian_handicap')
                ->where('selection', $parts[1])
                ->first(fn ($o) => abs((float) $o->line - (float) $parts[2]) < 0.01);
            return $odd ? (float) $odd->decimal_odds : null;
        }

        return null;
    }

    private function oddsForMarket(FootballMatch $match, string $code): Collection
    {
        static $cache = [];
        if (! array_key_exists($code, $cache)) {
            $cache[$code] = \App\Models\Market::where('code', $code)->value('id');
        }
        if ($cache[$code] === null) {
            return collect();
        }

        return ($match->oddsSnapshots ?? collect())->where('market_id', $cache[$code]);
    }

    private function displayTeamName(?string $name): string
    {
        if (! $name) return '';
        $cleaned = preg_replace('/\b(FC|CF|SSC|AC|US|SV\s*07|1\.\s*FSV|1\.\s*FC|RCD)\b/i', '', $name);
        $cleaned = trim(preg_replace('/\s+/', ' ', $cleaned));
        return $cleaned ?: $name;
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
                ->where('is_official', true))
            ->orderBy('match_date_utc')
            ->get();

        $candidates = $matches->map(function (FootballMatch $match): ?array {
            $prediction = $match->officialPrediction;
            if (! $prediction) {
                return null;
            }

            if ($prediction->published_market && ! $prediction->is_abstained) {
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
            $sourceLabel = $this->isLimitedData($prediction) ? 'Modèle · fiabilité limitée' : 'Modèle';
            $markets = $prediction?->derived_markets_json ?? [];
            $supportsScoreMarkets = $this->supportsScoreMarketPicks($prediction);
            if (! $supportsScoreMarkets) {
                $markets = array_intersect_key($markets, ['1N2' => true, 'double_chance' => true]);
            }

            $home = $this->displayTeamName($match->homeTeam->name ?? $match->homeTeam->short_name);
            $away = $this->displayTeamName($match->awayTeam->name ?? $match->awayTeam->short_name);
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
                    ['label' => 'Moins de 3,5 buts', 'prob' => $totals['under_3_5'] ?? null, 'market' => 'Total buts'],
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
