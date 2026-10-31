<?php

namespace App\Http\Controllers;

use App\Models\FootballMatch;
use App\Models\League;
use App\Models\OddsSnapshot;
use App\Models\Prediction;
use App\Services\AIExplanation\GeminiExplanationService;
use App\Services\Odds\OddsService;
use App\Services\Prediction\PredictionClient;
use Illuminate\Http\Request;

class MatchController extends Controller
{
    public function index(Request $request)
    {
        $query = FootballMatch::with(['homeTeam', 'awayTeam', 'league', 'officialPrediction', 'oddsSnapshots']);

        // Filters
        if ($request->filled('league_id')) {
            $query->where('league_id', $request->league_id);
        }

        if ($request->filled('status')) {
            if ($request->status === 'SCHEDULED') {
                $query->whereIn('status', ['SCHEDULED', 'TIMED']);
            } else {
                $query->where('status', $request->status);
            }
        }

        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(function ($q) use ($s) {
                $q->whereHas('homeTeam', fn($t) => $t->where('name', 'like', "%{$s}%"))
                  ->orWhereHas('awayTeam', fn($t) => $t->where('name', 'like', "%{$s}%"));
            });
        }

        $sort = $request->get('sort', 'date_asc');
        if ($sort === 'date_asc') {
            $query->orderBy('match_date_utc', 'asc');
        } elseif ($sort === 'date_desc') {
            $query->orderBy('match_date_utc', 'desc');
        }

        $matches = $query->paginate(20)->withQueryString();
        $leagues = League::where('is_active', true)->get();

        return view('matches.index', compact('matches', 'leagues'));
    }

    public function show(
        int $id,
        OddsService $oddsService,
        PredictionClient $predictionClient,
        GeminiExplanationService $geminiService
    ) {
        $match = FootballMatch::with([
            'homeTeam',
            'homeTeam.ratings',
            'awayTeam',
            'awayTeam.ratings',
            'league',
            'season',
            'officialPrediction.hashChain',
            'officialPrediction.modelVersion',
            'oddsSnapshots.bookmaker',
            'stats',
        ])->findOrFail($id);

        $prediction = $match->officialPrediction;
        $homeRating = $match->homeTeam->ratings->sortByDesc('as_of_date')->first()?->elo_rating;
        $awayRating = $match->awayTeam->ratings->sortByDesc('as_of_date')->first()?->elo_rating;
        $recentTeamMatches = FootballMatch::query()
            ->where('status', 'FINISHED')
            ->whereNotNull('home_score')
            ->whereNotNull('away_score')
            ->where(function ($query) use ($match) {
                $query->whereIn('home_team_id', [$match->home_team_id, $match->away_team_id])
                    ->orWhereIn('away_team_id', [$match->home_team_id, $match->away_team_id]);
            })
            ->orderByDesc('match_date_utc')
            ->get();
        $formForTeam = static function (int $teamId) use ($recentTeamMatches): string {
            return $recentTeamMatches
                ->filter(fn ($recentMatch) => $recentMatch->home_team_id === $teamId || $recentMatch->away_team_id === $teamId)
                ->take(5)
                ->map(function ($recentMatch) use ($teamId): string {
                    $teamGoals = $recentMatch->home_team_id === $teamId ? $recentMatch->home_score : $recentMatch->away_score;
                    $opponentGoals = $recentMatch->home_team_id === $teamId ? $recentMatch->away_score : $recentMatch->home_score;

                    return $teamGoals > $opponentGoals ? 'V' : ($teamGoals < $opponentGoals ? 'D' : 'N');
                })
                ->implode(' ') ?: 'Non mesurée';
        };
        $homeForm = $formForTeam($match->home_team_id);
        $awayForm = $formForTeam($match->away_team_id);

        // If no prediction yet, calculate one on demand
        if (!$prediction) {
            $predData = $predictionClient->predict([
                'match_id' => (string) $match->id,
                'home_team' => $match->homeTeam->name,
                'away_team' => $match->awayTeam->name,
                'is_neutral_ground' => (bool) $match->is_neutral_ground,
                'quality_score' => (float) $match->quality_score,
            ]);
        } else {
            $predData = [
                'expected_goals' => [
                    'home' => (float) $prediction->home_lambda,
                    'away' => (float) $prediction->away_lambda,
                    'total' => round((float) $prediction->home_lambda + (float) $prediction->away_lambda, 3),
                ],
                'probabilities' => [
                    'home' => ['p' => (float)$prediction->prob_home, 'ci80' => $prediction->intervals_json['ci80']['home'] ?? [], 'ci95' => $prediction->intervals_json['ci95']['home'] ?? []],
                    'draw' => ['p' => (float)$prediction->prob_draw, 'ci80' => $prediction->intervals_json['ci80']['draw'] ?? [], 'ci95' => $prediction->intervals_json['ci95']['draw'] ?? []],
                    'away' => ['p' => (float)$prediction->prob_away, 'ci80' => $prediction->intervals_json['ci80']['away'] ?? [], 'ci95' => $prediction->intervals_json['ci95']['away'] ?? []],
                ],
                'markets' => $prediction->derived_markets_json ?? [],
                'top_scores' => $prediction->top_scores_json ?? [],
                'quality' => ['score' => (float)$prediction->quality_score, 'tier' => $prediction->quality_tier],
                'confidence_tier' => $prediction->confidence_tier,
                'abstain' => (bool)$prediction->is_abstained,
                'model' => [
                    'version' => $prediction->modelVersion?->version_name ?? 'Version inconnue',
                    'calibration_version' => $prediction->calibration_version,
                ],
            ];
        }

        // Odds Devigging analysis
        $odds1N2 = $match->oddsSnapshots->where('is_closing', false);
        $homeOdds = $odds1N2->where('selection', 'HOME')->first();
        $drawOdds = $odds1N2->where('selection', 'DRAW')->first();
        $awayOdds = $odds1N2->where('selection', 'AWAY')->first();

        $devigAnalysis = null;
        if ($homeOdds && $drawOdds && $awayOdds) {
            $decimalOdds = [(float)$homeOdds->decimal_odds, (float)$drawOdds->decimal_odds, (float)$awayOdds->decimal_odds];
            $devigAnalysis = $oddsService->removeMargin($decimalOdds, 'shin');
        }

        // Generate score matrix for heatmap (0 to 6 goals)
        $scoreMatrix = [];
        $lh = $predData['expected_goals']['home'];
        $la = $predData['expected_goals']['away'];
        for ($i = 0; $i <= 5; $i++) {
            $row = [];
            for ($j = 0; $j <= 5; $j++) {
                // Poisson approx for visual grid
                $pI = (exp(-$lh) * pow($lh, $i)) / max(1, self::factorial($i));
                $pJ = (exp(-$la) * pow($la, $j)) / max(1, self::factorial($j));
                $cellP = $pI * $pJ;
                // Tau correction for low scores
                if ($i === 0 && $j === 0) $cellP *= (1.0 - $lh * $la * (-0.04));
                elseif ($i === 0 && $j === 1) $cellP *= (1.0 + $lh * (-0.04));
                elseif ($i === 1 && $j === 0) $cellP *= (1.0 + $la * (-0.04));
                elseif ($i === 1 && $j === 1) $cellP *= (1.0 - (-0.04));
                $row[] = round($cellP, 4);
            }
            $scoreMatrix[] = $row;
        }

        // AI Explanation
        $aiContext = [
            'home_team' => $match->homeTeam->name,
            'away_team' => $match->awayTeam->name,
            'match_date' => $match->match_date_utc->toIso8601String(),
            'expected_goals' => $predData['expected_goals'],
            'probabilities' => $predData['probabilities'],
            'top_scores' => $predData['top_scores'],
            'quality' => $predData['quality'],
        ];

        $aiExplanation = $geminiService->explainMatch($aiContext, 'beginner');

        return view('matches.show', compact(
            'match',
            'prediction',
            'predData',
            'scoreMatrix',
            'devigAnalysis',
            'aiExplanation'
            , 'homeRating'
            , 'awayRating'
            , 'homeForm'
            , 'awayForm'
        ));
    }

    public function askAssistant(Request $request, int $id, GeminiExplanationService $geminiService)
    {
        $match = FootballMatch::with(['homeTeam', 'awayTeam', 'officialPrediction'])->findOrFail($id);
        $pred = $match->officialPrediction;

        $context = [
            'home_team' => $match->homeTeam->name,
            'away_team' => $match->awayTeam->name,
            'expected_goals' => [
                'home' => (float)($pred->home_lambda ?? 1.45),
                'away' => (float)($pred->away_lambda ?? 1.10),
            ],
            'probabilities' => [
                'home' => ['p' => (float)($pred->prob_home ?? 0.46)],
                'draw' => ['p' => (float)($pred->prob_draw ?? 0.27)],
                'away' => ['p' => (float)($pred->prob_away ?? 0.27)],
            ],
            'top_scores' => $pred->top_scores_json ?? [['score' => '1-1', 'p' => 0.12]],
            'quality' => ['score' => (float)($pred->quality_score ?? 85.0)],
        ];

        $mode = $request->get('mode', 'beginner');
        $question = $request->get('question', null);

        $res = $geminiService->explainMatch($context, $mode, $question);

        return response()->json($res);
    }

    public function simulateWhatIf(Request $request, int $id, PredictionClient $predictionClient)
    {
        $data = $request->validate([
            'lambda_home' => 'required|numeric|between:0.1,8',
            'lambda_away' => 'required|numeric|between:0.1,8',
            'is_neutral' => 'sometimes|boolean',
        ]);
        $match = FootballMatch::with(['homeTeam', 'awayTeam'])->findOrFail($id);

        $lh = (float) $data['lambda_home'];
        $la = (float) $data['lambda_away'];
        $isNeutral = (bool) ($data['is_neutral'] ?? false);

        $simResult = $predictionClient->predict([
            'match_id' => 'sim_' . $match->id,
            'home_team' => $match->homeTeam->name,
            'away_team' => $match->awayTeam->name,
            'is_neutral_ground' => $isNeutral,
            'lambda_home_override' => $lh,
            'lambda_away_override' => $la,
        ]);

        return response()->json($simResult);
    }

    private static function factorial(int $n): int
    {
        return $n <= 1 ? 1 : $n * self::factorial($n - 1);
    }
}

