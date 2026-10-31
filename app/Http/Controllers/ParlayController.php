<?php

namespace App\Http\Controllers;

use App\Models\Parlay;
use App\Services\Parlay\ParlayService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ParlayController extends Controller
{
    public function index(Request $request, ParlayService $parlayService)
    {
        $tz = 'Africa/Douala';
        $dateStr = $request->get('date', Carbon::now($tz)->toDateString());
        $date = Carbon::parse($dateStr, $tz);

        $parlays = $parlayService->getOrGenerateDailyParlays($date);

        // Historique des combinés précédents
        $history = Parlay::whereDate('date_for', '<', $date->toDateString())
            ->orderBy('date_for', 'desc')
            ->orderBy('id', 'desc')
            ->take(10)
            ->get();

        return view('parlays.index', compact('parlays', 'date', 'history'));
    }

    /**
     * Régénère les combinés pour la date sélectionnée.
     * Prend en charge les requêtes AJAX pour une mise à jour fluide sans rechargement.
     */
    public function generate(Request $request, ParlayService $parlayService): JsonResponse|RedirectResponse
    {
        $tz = 'Africa/Douala';
        $dateStr = $request->get('date', Carbon::now($tz)->toDateString());
        $date = Carbon::parse($dateStr, $tz);

        // Supprimer les combinés non verrouillés de cette date pour forcer le recalcul
        Parlay::whereDate('date_for', $date->toDateString())->where('status', 'PENDING')->delete();

        $parlays = $parlayService->generateDailyParlays($date);

        if ($request->wantsJson() || $request->ajax() || $request->header('X-Requested-With') === 'XMLHttpRequest') {
            return response()->json([
                'success'      => true,
                'count'        => $parlays->count(),
                'message'      => "{$parlays->count()} combiné(s) probabiliste(s) généré(s) pour le {$date->translatedFormat('d F Y')}.",
                'generated_at' => Carbon::now($tz)->format('d/m/Y à H:i:s'),
                'parlays'      => $parlays,
            ]);
        }

        return redirect()->route('parlays.index', ['date' => $date->toDateString()])
            ->with('success', "{$parlays->count()} combiné(s) probabiliste(s) généré(s) pour le {$date->translatedFormat('d F Y')}.");
    }

    public function show(int $id)
    {
        $parlay = Parlay::findOrFail($id);

        return view('parlays.show', compact('parlay'));
    }
}
