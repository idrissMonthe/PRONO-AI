<?php

namespace App\Http\Controllers;

use App\Models\FootballMatch;
use App\Models\League;
use App\Models\OddsSnapshot;
use App\Models\Prediction;
use App\Services\AIExplanation\GeminiExplanationService;
use App\Services\Odds\OddsService;
use App\Services\Prediction\PredictionClient;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MatchController extends Controller
{
    /**
     * Liste des rencontres avec filtrage par date/fuseau horaire (Africa/Douala - UTC+1).
     */
    public function index(Request $request)
    {
        $tz = 'Africa/Douala';
        $nowDouala = Carbon::now($tz);

        // Date filter tab: 'today' (default), 'tomorrow', 'upcoming', 'live', 'finished', 'all'
        $dateFilter = $request->get('date_filter', ($request->filled('status') || $request->filled('search')) ? 'all' : 'today');

        $query = FootballMatch::with(['homeTeam', 'awayTeam', 'league', 'officialPrediction', 'oddsSnapshots']);

        // Intervalles temporels stricts calculés en Africa/Douala et convertis en UTC
        $todayStartUtc = $nowDouala->copy()->startOfDay()->utc();
        $todayEndUtc   = $nowDouala->copy()->endOfDay()->utc();

        $tomorrowStartUtc = $nowDouala->copy()->addDay()->startOfDay()->utc();
        $tomorrowEndUtc   = $nowDouala->copy()->addDay()->endOfDay()->utc();

        // Application du filtre de date / statut principal
        switch ($dateFilter) {
            case 'today':
                $query->whereBetween('match_date_utc', [$todayStartUtc, $todayEndUtc]);
                break;

            case 'tomorrow':
                $query->whereBetween('match_date_utc', [$tomorrowStartUtc, $tomorrowEndUtc]);
                break;

            case 'upcoming':
                $query->where('match_date_utc', '>=', now()->utc())
                      ->whereIn('status', ['SCHEDULED', 'TIMED']);
                break;

            case 'live':
                $query->whereIn('status', ['LIVE', 'IN_PLAY', 'PAUSED']);
                break;

            case 'finished':
                $query->whereIn('status', ['FINISHED', 'AWARDED']);
                break;

            case 'all':
            default:
                // Pas de restriction temporelle globale
                break;
        }

        // Filtre spécifique de statut si précisé en plus
        if ($request->filled('status')) {
            $statusVal = $request->status;
            if ($statusVal === 'SCHEDULED') {
                $query->whereIn('status', ['SCHEDULED', 'TIMED']);
            } elseif ($statusVal === 'FINISHED') {
                $query->whereIn('status', ['FINISHED', 'AWARDED']);
            } elseif ($statusVal === 'LIVE') {
                $query->whereIn('status', ['LIVE', 'IN_PLAY', 'PAUSED']);
            } else {
                $query->where('status', $statusVal);
            }
        }

        // Exclusion des matchs obsolètes dans les vues à venir
        if (in_array($dateFilter, ['today', 'tomorrow', 'upcoming']) && ! $request->filled('status')) {
            $query->whereNotIn('status', ['CANCELLED', 'POSTPONED', 'SUSPENDED']);
        }

        // Filtre championnat
        if ($request->filled('league_id')) {
            $query->where('league_id', $request->league_id);
        }

        // Recherche par équipe
        if ($request->filled('search')) {
            $s = trim($request->search);
            $query->where(function ($q) use ($s) {
                $q->whereHas('homeTeam', fn ($t) => $t->where('name', 'like', "%{$s}%"))
                  ->orWhereHas('awayTeam', fn ($t) => $t->where('name', 'like', "%{$s}%"));
            });
        }

        $reliableQuery = clone $query;
        $reliableQuery->whereHas('officialPrediction', function ($predictionQuery) {
                $predictionQuery->where('is_official', true)
                    ->where('is_abstained', false)
                    ->whereNotNull('published_market')
                    ->whereIn('confidence_tier', ['medium', 'high']);
            });
        $reliableCount = $reliableQuery->count();

        if ($request->boolean('reliable')) {
            $query->whereHas('officialPrediction', function ($predictionQuery) {
                $predictionQuery->where('is_official', true)
                    ->where('is_abstained', false)
                    ->whereNotNull('published_market')
                    ->whereIn('confidence_tier', ['medium', 'high']);
            });
        }

        // Tri
        $sort = $request->get('sort', 'date_asc');
        if ($sort === 'date_asc') {
            $query->orderBy('match_date_utc', 'asc');
        } elseif ($sort === 'date_desc') {
            $query->orderBy('match_date_utc', 'desc');
        }

        // “À venir” regroupe plusieurs journées. Avec 20 lignes, une journée
        // peut être coupée par la pagination (ex. 4 matchs demain en page 1
        // et le 5e en page 2), ce qui donne l'impression qu'il manque un match.
        // On affiche donc toute la fenêtre à venir sur une page raisonnable.
        $perPage = $dateFilter === 'upcoming' ? 50 : 20;
        $matches = $query->paginate($perPage)->withQueryString();
        $leagues = League::where('is_active', true)->orderBy('name')->get();

        // Comptages en temps réel pour les pastilles d'onglets
        $counts = [
            'today' => FootballMatch::whereBetween('match_date_utc', [$todayStartUtc, $todayEndUtc])
                ->whereNotIn('status', ['CANCELLED', 'POSTPONED'])
                ->count(),
            'tomorrow' => FootballMatch::whereBetween('match_date_utc', [$tomorrowStartUtc, $tomorrowEndUtc])
                ->whereNotIn('status', ['CANCELLED', 'POSTPONED'])
                ->count(),
            'upcoming' => FootballMatch::where('match_date_utc', '>=', now()->utc())
                ->whereIn('status', ['SCHEDULED', 'TIMED'])
                ->count(),
            'live' => FootballMatch::whereIn('status', ['LIVE', 'IN_PLAY', 'PAUSED'])->count(),
            'finished' => FootballMatch::whereIn('status', ['FINISHED', 'AWARDED'])->count(),
            'reliable' => $reliableCount,
        ];

        // Indicateur de dernière synchronisation depuis l'API
        $lastSync = FootballMatch::whereNotNull('last_synced_at')->max('last_synced_at');
        $lastSyncFormatted = $lastSync ? Carbon::parse($lastSync)->timezone($tz)->format('d/m/Y à H:i') : null;

        return view('matches.index', compact(
            'matches',
            'leagues',
            'dateFilter',
            'counts',
            'nowDouala',
            'lastSyncFormatted'
            ,'reliableCount'
        ));
    }

    /**
     * État court des matchs récents et à venir pour le rafraîchissement live.
     * Les scores viennent de la dernière synchronisation football-data.org.
     */
    public function liveScores(): JsonResponse
    {
        $now = Carbon::now('Africa/Douala');

        $matches = FootballMatch::with(['homeTeam:id,name', 'awayTeam:id,name'])
            ->whereBetween('match_date_utc', [
                $now->copy()->subDay()->utc(),
                $now->copy()->addDays(2)->utc(),
            ])
            ->whereIn('status', ['SCHEDULED', 'TIMED', 'LIVE', 'IN_PLAY', 'PAUSED', 'FINISHED', 'POSTPONED', 'CANCELLED'])
            ->orderBy('match_date_utc')
            ->get()
            ->map(function (FootballMatch $match) use ($now): array {
                $isLive = in_array($match->status, ['LIVE', 'IN_PLAY', 'PAUSED'], true);
                $minute = null;
                if (in_array($match->status, ['LIVE', 'IN_PLAY'], true)) {
                    $elapsedSeconds = max(0, $now->timestamp - $match->match_date_utc->timestamp);
                    $minute = max(1, min(120, (int) floor($elapsedSeconds / 60)));
                }

                return [
                    'id' => $match->id,
                    'status' => $match->status,
                    'status_label' => match ($match->status) {
                        'LIVE', 'IN_PLAY' => 'DIRECT',
                        'PAUSED' => 'MI-TEMPS',
                        'FINISHED' => 'TERMINE',
                        'POSTPONED' => 'REPORTÉ',
                        'CANCELLED' => 'ANNULÉ',
                        default => 'À VENIR',
                    },
                    'home_score' => $match->home_score,
                    'away_score' => $match->away_score,
                    'minute' => $minute,
                    'is_live' => $isLive,
                    'updated_at' => $match->last_synced_at?->timezone('Africa/Douala')?->toIso8601String(),
                ];
            });

        return response()->json([
            'data' => $matches,
            'source' => 'football-data.org',
            'checked_at' => $now->toIso8601String(),
        ])->header('Cache-Control', 'no-store, no-cache, must-revalidate');
    }

    /**
     * Endpoint API pour alimenter l'overlay modal d'analyse en 4 onglets (AJAX instantané).
     */
    public function modalData(
        int $id,
        OddsService $oddsService,
        PredictionClient $predictionClient,
        GeminiExplanationService $geminiService
    ): JsonResponse {
        $data = $this->prepareMatchFullData($id, $oddsService, $predictionClient, $geminiService);

        return response()->json($data);
    }

    /**
     * Affichage complet de la fiche match.
     */
    public function show(
        int $id,
        OddsService $oddsService,
        PredictionClient $predictionClient,
        GeminiExplanationService $geminiService
    ) {
        $data = $this->prepareMatchFullData($id, $oddsService, $predictionClient, $geminiService);

        return view('matches.show', [
            'match'         => $data['match_model'],
            'prediction'    => $data['match_model']->officialPrediction,
            'predData'      => $data['pronostic'],
            'scoreMatrix'   => $data['score_matrix'],
            'devigAnalysis' => $data['cotes']['devig_analysis'] ?? null,
            'marketOdds'    => $data['cotes']['markets'] ?? [],
            'aiExplanation' => $data['signaux_ia'],
            'homeRating'    => $data['forme']['home_elo'] ?? null,
            'awayRating'    => $data['forme']['away_elo'] ?? null,
            'homeForm'      => $data['forme']['home_form_string'] ?? 'Non mesurée',
            'awayForm'      => $data['forme']['away_form_string'] ?? 'Non mesurée',
        ]);
    }

    /**
     * Prépare l'ensemble des données d'un match réparties sur les 4 onglets fonctionnels.
     */
    protected function prepareMatchFullData(
        int $id,
        OddsService $oddsService,
        PredictionClient $predictionClient,
        GeminiExplanationService $geminiService
    ): array {
        $tz = 'Africa/Douala';

        $match = FootballMatch::with([
            'homeTeam.ratings',
            'awayTeam.ratings',
            'league',
            'season',
            'officialPrediction.hashChain',
            'officialPrediction.modelVersion',
            'oddsSnapshots.bookmaker',
            'oddsSnapshots.market',
            'stats',
        ])->findOrFail($id);

        $prediction = $match->officialPrediction;

        // ─────────────────────────────────────────────────────────────────────
        // TAB 1: PRONOSTIC (Calculs modèles Python Dixon-Coles / Poisson)
        // ─────────────────────────────────────────────────────────────────────
        if (! $prediction) {
            $predData = $predictionClient->predict([
                'match_id'          => (string) $match->id,
                'home_team'         => $match->homeTeam->name,
                'away_team'         => $match->awayTeam->name,
                'is_neutral_ground' => (bool) $match->is_neutral_ground,
                'quality_score'     => (float) $match->quality_score,
            ]);
        } else {
            $predData = [
                'expected_goals' => [
                    'home'  => $this->nonNegativeNumber($prediction->home_lambda),
                    'away'  => $this->nonNegativeNumber($prediction->away_lambda),
                    'total' => ($this->nonNegativeNumber($prediction->home_lambda) !== null && $this->nonNegativeNumber($prediction->away_lambda) !== null)
                        ? round((float) $prediction->home_lambda + (float) $prediction->away_lambda, 3)
                        : null,
                ],
                'probabilities' => [
                    'home' => [
                        'p'    => $this->unitProbability($prediction->prob_home),
                        'ci80' => $prediction->intervals_json['ci80']['home'] ?? [],
                        'ci95' => $prediction->intervals_json['ci95']['home'] ?? [],
                    ],
                    'draw' => [
                        'p'    => $this->unitProbability($prediction->prob_draw),
                        'ci80' => $prediction->intervals_json['ci80']['draw'] ?? [],
                        'ci95' => $prediction->intervals_json['ci95']['draw'] ?? [],
                    ],
                    'away' => [
                        'p'    => $this->unitProbability($prediction->prob_away),
                        'ci80' => $prediction->intervals_json['ci80']['away'] ?? [],
                        'ci95' => $prediction->intervals_json['ci95']['away'] ?? [],
                    ],
                ],
                'markets'         => $prediction->derived_markets_json ?? [],
                'top_scores'      => $prediction->top_scores_json ?? [],
                'quality'         => ['score' => (float) $prediction->quality_score, 'tier' => $prediction->quality_tier],
                'confidence_tier' => $prediction->confidence_tier,
                'abstain'         => (bool) $prediction->is_abstained,
                'status'          => $prediction->is_abstained ? 'insufficient_data' : 'ok',
                'availability_status' => $match->analysis_status ?? 'LIMITED_DATA',
                'prediction_origin' => $prediction->prediction_origin ?? 'PYTHON_ESTIMATE',
                'gemini_review' => \App\Services\Prediction\PredictionRevisionService::presentationFromPrediction($prediction),
                'source_model' => $prediction->source_model,
                'missing_data' => $prediction->missing_data_json ?? [],
                'uncertainty' => $prediction->uncertainty_tier ?? 'low',
                'model'           => [
                    'version'             => $prediction->modelVersion?->version_name ?? 'M3_DIXON_COLES',
                    'calibration_version' => $prediction->calibration_version ?? 'calib_v2',
                ],
            ];
        }

        // La grille n'est affichée que si le moteur l'a réellement fournie.
        // Aucun xG de repli et aucun rho inventé ne servent à la reconstruire.
        $scoreMatrix = $this->providedScoreMatrix($predData);

        // ─────────────────────────────────────────────────────────────────────
        // TAB 2: SIGNAUX IA GEMINI (Contextuel, contrôlé et sourcé)
        // ─────────────────────────────────────────────────────────────────────
        $aiContext = [
            'home_team'      => $match->homeTeam->name,
            'away_team'      => $match->awayTeam->name,
            'match_date'     => $match->match_date_utc->timezone($tz)->format('d/m/Y à H:i') . ' (WAT)',
            'expected_goals' => $predData['expected_goals'],
            'probabilities'  => $predData['probabilities'],
            'top_scores'     => $predData['top_scores'],
            'quality'        => $predData['quality'],
        ];

        $aiExplanation = $geminiService->explainMatch($aiContext, 'beginner');

        // ─────────────────────────────────────────────────────────────────────
        // TAB 3: COTES RÉELLES & DÉVIGEAGE (Shin / Multiplicatif)
        // ─────────────────────────────────────────────────────────────────────
        $oddsSnapshots = $match->oddsSnapshots;
        $activeOdds = $oddsSnapshots->where('is_closing', false)
            ->sortByDesc(fn ($odd) => ($odd->source === 'the-odds-api' ? 10000000000 : 0) + ($odd->snapshot_time_utc?->timestamp ?? 0));
        if ($activeOdds->isEmpty()) {
            $activeOdds = $oddsSnapshots;
        }

        $homeOdds = $activeOdds->where('selection', 'HOME')->first();
        $drawOdds = $activeOdds->where('selection', 'DRAW')->first();
        $awayOdds = $activeOdds->where('selection', 'AWAY')->first();

        $devigAnalysis = null;
        $oddsSummary = [];

        if ($homeOdds && $drawOdds && $awayOdds) {
            $decimalOdds = [
                (float) $homeOdds->decimal_odds,
                (float) $drawOdds->decimal_odds,
                (float) $awayOdds->decimal_odds,
            ];
            $devigAnalysis = $oddsService->removeMargin($decimalOdds, 'shin');

            $pHomeMod = $this->unitProbability($predData['probabilities']['home']['p'] ?? null);
            $pDrawMod = $this->unitProbability($predData['probabilities']['draw']['p'] ?? null);
            $pAwayMod = $this->unitProbability($predData['probabilities']['away']['p'] ?? null);

            $oddsSummary = [
                'home' => $this->oddsComparison($homeOdds, $pHomeMod, $tz),
                'draw' => $this->oddsComparison($drawOdds, $pDrawMod, $tz),
                'away' => $this->oddsComparison($awayOdds, $pAwayMod, $tz),
            ];
        }

        // Catalogue normalisé des marchés réellement reçus d'un fournisseur.
        // Une absence de cote reste une absence : aucune cote synthétique n'est
        // présentée comme une cote bookmaker.
        $marketOdds = $oddsSnapshots
            ->where('is_closing', false)
            ->sortByDesc(fn ($odd) => ($odd->snapshot_time_utc?->timestamp ?? 0))
            ->groupBy(fn ($odd) => $odd->market?->code ?? 'UNKNOWN')
            ->map(fn ($rows) => $rows->unique(fn ($odd) => ($odd->selection ?? '').'|'.($odd->line ?? ''))->values()->map(fn ($odd) => [
                'selection' => $odd->selection,
                'line' => $odd->line !== null ? (float) $odd->line : null,
                'odds' => (float) $odd->decimal_odds,
                'bookmaker' => $odd->bookmaker?->name ?? $odd->source,
                'updated_at' => $odd->snapshot_time_utc?->timezone($tz)->format('d/m/Y H:i'),
            ])->all())->all();

        // ─────────────────────────────────────────────────────────────────────
        // TAB 4: FORME & HISTORIQUE (5 derniers matchs réels de chaque équipe)
        // ─────────────────────────────────────────────────────────────────────
        $recentMatches = FootballMatch::with(['homeTeam', 'awayTeam', 'league'])
            ->where('status', 'FINISHED')
            ->whereNotNull('home_score')
            ->whereNotNull('away_score')
            ->where(function ($q) use ($match) {
                $q->whereIn('home_team_id', [$match->home_team_id, $match->away_team_id])
                  ->orWhereIn('away_team_id', [$match->home_team_id, $match->away_team_id]);
            })
            ->orderByDesc('match_date_utc')
            ->get();

        $buildTeamForm = function (int $teamId) use ($recentMatches, $tz) {
            $teamMatches = $recentMatches
                ->filter(fn ($m) => $m->home_team_id === $teamId || $m->away_team_id === $teamId)
                ->take(5);

            $formLetters = [];
            $matchDetails = [];

            foreach ($teamMatches as $rm) {
                $isHome = ($rm->home_team_id === $teamId);
                $myGoals  = $isHome ? $rm->home_score : $rm->away_score;
                $oppGoals = $isHome ? $rm->away_score : $rm->home_score;
                $oppName  = $isHome ? $rm->awayTeam->name : $rm->homeTeam->name;

                $letter = ($myGoals > $oppGoals) ? 'V' : (($myGoals < $oppGoals) ? 'D' : 'N');
                $formLetters[] = $letter;

                $matchDetails[] = [
                    'date'     => $rm->match_date_utc->timezone($tz)->format('d/m/Y'),
                    'opponent' => $oppName,
                    'is_home'  => $isHome,
                    'score'    => "{$rm->home_score} - {$rm->away_score}",
                    'result'   => $letter,
                ];
            }

            return [
                'letters' => $formLetters,
                'string'  => ! empty($formLetters) ? implode(' ', $formLetters) : 'Non mesurée',
                'matches' => $matchDetails,
            ];
        };

        $homeForm = $buildTeamForm($match->home_team_id);
        $awayForm = $buildTeamForm($match->away_team_id);

        // Confrontations directes (H2H)
        $h2hMatches = FootballMatch::where(function ($q) use ($match) {
            $q->where('home_team_id', $match->home_team_id)->where('away_team_id', $match->away_team_id);
        })->orWhere(function ($q) use ($match) {
            $q->where('home_team_id', $match->away_team_id)->where('away_team_id', $match->home_team_id);
        })
        ->where('status', 'FINISHED')
        ->orderByDesc('match_date_utc')
        ->take(5)
        ->get()
        ->map(fn ($m) => [
            'date'  => $m->match_date_utc->timezone($tz)->format('d/m/Y'),
            'score' => "{$m->homeTeam->short_name} {$m->home_score} - {$m->away_score} {$m->awayTeam->short_name}",
        ]);

        $homeRating = $match->homeTeam->ratings->sortByDesc('as_of_date')->first();
        $awayRating = $match->awayTeam->ratings->sortByDesc('as_of_date')->first();

        return [
            'match_model' => $match,
            'match_info'  => [
                'id'                 => $match->id,
                'home_team'          => $match->homeTeam->name,
                'away_team'          => $match->awayTeam->name,
                'home_short'         => $match->homeTeam->short_name,
                'away_short'         => $match->awayTeam->short_name,
                'league'             => $match->league->name ?? 'Compétition',
                'country'            => $match->league->country ?? 'Europe',
                'match_date_utc'     => $match->match_date_utc->toIso8601String(),
                'match_date_douala'  => $match->match_date_utc->timezone($tz)->translatedFormat('l d F Y à H:i') . ' (WAT)',
                'match_time_douala'  => $match->match_date_utc->timezone($tz)->format('H:i'),
                'status'             => $match->status,
                'home_score'         => $match->home_score,
                'away_score'         => $match->away_score,
                'quality_score'      => (float) $match->quality_score,
                'quality_tier'       => $match->quality_tier,
                'data_source'        => $match->data_source ?? 'football-data.org',
                'last_synced_at'     => $match->last_synced_at ? Carbon::parse($match->last_synced_at)->timezone($tz)->format('d/m/Y H:i') : null,
            ],
            'pronostic'    => $predData,
            'score_matrix' => $scoreMatrix,
            'signaux_ia'   => $aiExplanation,
            'cotes'        => [
                'has_odds'       => ($homeOdds && $drawOdds && $awayOdds),
                'summary'        => $oddsSummary,
                'devig_analysis' => $devigAnalysis,
                'markets'        => $marketOdds,
            ],
            'forme' => [
                'home_form_letters' => $homeForm['letters'],
                'home_form_string'  => $homeForm['string'],
                'home_matches'      => $homeForm['matches'],
                'away_form_letters' => $awayForm['letters'],
                'away_form_string'  => $awayForm['string'],
                'away_matches'      => $awayForm['matches'],
                'home_elo'          => $homeRating?->elo_rating ? round($homeRating->elo_rating) : null,
                'away_elo'          => $awayRating?->elo_rating ? round($awayRating->elo_rating) : null,
                'h2h'               => $h2hMatches,
            ],
        ];
    }

    public function askAssistant(Request $request, int $id, GeminiExplanationService $geminiService)
    {
        $data = $request->validate([
            'question' => ['nullable', 'string', 'max:1000'],
            'mode' => ['nullable', 'in:beginner,expert'],
        ]);
        $match = FootballMatch::with(['homeTeam', 'awayTeam', 'officialPrediction'])->findOrFail($id);
        $pred = $match->officialPrediction;

        $context = [
            'home_team'      => $match->homeTeam->name,
            'away_team'      => $match->awayTeam->name,
            'expected_goals' => [
                'home' => $this->nonNegativeNumber($pred?->home_lambda),
                'away' => $this->nonNegativeNumber($pred?->away_lambda),
            ],
            'probabilities' => [
                'home' => ['p' => $this->unitProbability($pred?->prob_home)],
                'draw' => ['p' => $this->unitProbability($pred?->prob_draw)],
                'away' => ['p' => $this->unitProbability($pred?->prob_away)],
            ],
            'top_scores' => is_array($pred?->top_scores_json) ? $pred->top_scores_json : [],
            'quality'    => ['score' => $this->nonNegativeNumber($pred?->quality_score)],
            'missing_data' => array_values(array_filter([
                $this->nonNegativeNumber($pred?->home_lambda) === null ? 'expected_goals_home' : null,
                $this->nonNegativeNumber($pred?->away_lambda) === null ? 'expected_goals_away' : null,
                $this->unitProbability($pred?->prob_home) === null ? 'probability_home' : null,
                $this->unitProbability($pred?->prob_draw) === null ? 'probability_draw' : null,
                $this->unitProbability($pred?->prob_away) === null ? 'probability_away' : null,
                ! is_array($pred?->top_scores_json) || $pred->top_scores_json === [] ? 'top_scores' : null,
            ])),
        ];

        $mode = $data['mode'] ?? 'beginner';
        $question = $data['question'] ?? null;

        $res = $geminiService->explainMatch($context, $mode, $question);

        return response()->json($res);
    }

    public function simulateWhatIf(Request $request, int $id, PredictionClient $predictionClient)
    {
        $data = $request->validate([
            'lambda_home' => 'required|numeric|between:0.1,8',
            'lambda_away' => 'required|numeric|between:0.1,8',
            'is_neutral'  => 'sometimes|boolean',
        ]);
        $match = FootballMatch::with(['homeTeam', 'awayTeam'])->findOrFail($id);

        $lh = (float) $data['lambda_home'];
        $la = (float) $data['lambda_away'];
        $isNeutral = (bool) ($data['is_neutral'] ?? false);

        $simResult = $predictionClient->predict([
            'match_id'             => 'sim_' . $match->id,
            'home_team'            => $match->homeTeam->name,
            'away_team'            => $match->awayTeam->name,
            'is_neutral_ground'    => $isNeutral,
            'lambda_home_override' => $lh,
            'lambda_away_override' => $la,
        ]);

        return response()->json($simResult);
    }

    private function providedScoreMatrix(array $predData): ?array
    {
        $matrix = $predData['score_matrix'] ?? null;
        if (! is_array($matrix) || $matrix === []) {
            return null;
        }

        foreach ($matrix as $row) {
            if (! is_array($row)) {
                return null;
            }
            foreach ($row as $cell) {
                if (! is_numeric($cell)) {
                    return null;
                }
            }
        }

        return $matrix;
    }

    private function unitProbability(mixed $value): ?float
    {
        if (! is_numeric($value)) {
            return null;
        }
        $number = (float) $value;

        return ($number >= 0.0 && $number <= 1.0) ? $number : null;
    }

    private function nonNegativeNumber(mixed $value): ?float
    {
        if (! is_numeric($value)) {
            return null;
        }
        $number = (float) $value;

        return $number >= 0.0 ? $number : null;
    }

    private function oddsComparison(object $odd, ?float $modelProbability, string $timezone): array
    {
        $bookmakerOdds = (float) $odd->decimal_odds;
        $comparable = $modelProbability !== null && $modelProbability > 0.0 && $bookmakerOdds > 1.0;

        return [
            'bookmaker_odds' => $bookmakerOdds,
            'fair_odds' => $comparable ? round(1.0 / $modelProbability, 2) : null,
            'ev_pct' => $comparable ? round(($modelProbability * $bookmakerOdds - 1.0) * 100, 2) : null,
            'bookmaker' => $odd->source === 'the-odds-api' ? ($odd->bookmaker?->name ?? 'The Odds API') : 'Historique local',
            'updated_at' => $odd->snapshot_time_utc ? Carbon::parse($odd->snapshot_time_utc)->timezone($timezone)->format('d/m/Y H:i') : null,
        ];
    }
}
