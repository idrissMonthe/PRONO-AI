<?php

namespace App\Http\Controllers;

use App\Models\FootballMatch;
use App\Models\Team;
use App\Services\Prediction\PredictionClient;
use Illuminate\Http\Request;

class ComparatorController extends Controller
{
    public function index(Request $request, PredictionClient $predictionClient)
    {
        $teams = Team::with('ratings')->orderBy('name', 'asc')->get();

        $teamAId = $request->get('team_a', $teams->first()?->id);
        $teamBId = $request->get('team_b', $teams->skip(1)->first()?->id);

        $teamA = Team::with('ratings')->find($teamAId);
        $teamB = Team::with('ratings')->find($teamBId);

        $simulation = null;
        $simulationError = null;
        if ($teamA && $teamB) {
            try {
                $simulation = $predictionClient->predict([
                    'match_id' => 'hypothetical_sim',
                    'home_team' => $teamA->name,
                    'away_team' => $teamB->name,
                    'is_neutral_ground' => (bool)$request->get('is_neutral', false),
                    'quality_score' => 95.0,
                ]);
            } catch (\Throwable $exception) {
                // Le comparateur doit rester consultable même lorsque le
                // service de prédiction est momentanément indisponible.
                $simulationError = $exception->getMessage();
            }
        }

        // Head-to-head past matches
        $h2hMatches = [];
        if ($teamA && $teamB) {
            $h2hMatches = FootballMatch::where(function ($q) use ($teamA, $teamB) {
                $q->where('home_team_id', $teamA->id)->where('away_team_id', $teamB->id);
            })->orWhere(function ($q) use ($teamA, $teamB) {
                $q->where('home_team_id', $teamB->id)->where('away_team_id', $teamA->id);
            })->orderBy('match_date_utc', 'desc')->take(6)->get();
        }

        return view('comparator.index', compact('teams', 'teamA', 'teamB', 'simulation', 'simulationError', 'h2hMatches'));
    }
}

