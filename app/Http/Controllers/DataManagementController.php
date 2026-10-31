<?php

namespace App\Http\Controllers;

use App\Models\DataQuarantine;
use App\Models\FootballMatch;
use App\Models\League;
use App\Models\Team;
use App\Models\TeamAlias;
use App\Services\FootballData\FootballDataService;
use Illuminate\Http\Request;
use RuntimeException;

class DataManagementController extends Controller
{
    public function index()
    {
        $teamsCount = Team::count();
        $matchesCount = FootballMatch::count();
        $aliasesCount = TeamAlias::count();
        $quarantineCount = DataQuarantine::count();

        // High quality vs degraded matches
        $usableCount = FootballMatch::where('quality_tier', 'usable')->count();
        $degradedCount = FootballMatch::where('quality_tier', 'degraded')->count();
        $insufficientCount = FootballMatch::where('quality_tier', 'insufficient')->count();

        // Team Aliases
        $aliases = TeamAlias::with('team')->orderBy('id', 'desc')->take(15)->get();

        // Quarantined records
        $quarantines = DataQuarantine::orderBy('quarantined_at_utc', 'desc')->take(10)->get();

        $leagues = League::withCount('matches')->get();

        return view('data.index', compact(
            'teamsCount',
            'matchesCount',
            'aliasesCount',
            'quarantineCount',
            'usableCount',
            'degradedCount',
            'insufficientCount',
            'aliases',
            'quarantines',
            'leagues'
        ));
    }

    public function addAlias(Request $request)
    {
        $request->validate([
            'team_id' => 'required|exists:teams,id',
            'alias' => 'required|string|max:100',
        ]);

        TeamAlias::create([
            'team_id' => $request->team_id,
            'alias' => trim($request->alias),
            'source' => 'manual_entry',
            'confidence' => 100.0,
            'is_validated' => true,
        ]);

        return redirect()->route('data.index')->with('success', 'Nouvel alias d\'équipe enregistré avec succès.');
    }

    public function syncApi(FootballDataService $service)
    {
        try {
            $result = $service->syncFromFootballDataOrg();
        } catch (RuntimeException $exception) {
            return redirect()->route('data.index')->with('error', $exception->getMessage());
        }

        return redirect()->route('data.index')->with(
            'success',
            $result['imported'].' match(s) importé(s) depuis football-data.org; '
                .$result['rejected'].' rejeté(s) sur '.$result['received'].' reçu(s).'
        );
    }
}
