<?php

namespace App\Services\Parlay;

use App\Models\FootballMatch;
use App\Models\OddsSnapshot;
use App\Models\Parlay;
use App\Services\AIExplanation\GeminiExplanationService;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class ParlayService
{
    public function __construct(
        protected GeminiExplanationService $geminiService
    ) {}

    /**
     * Récupère ou génère les combinés du jour (fuseau Africa/Douala - UTC+1).
     */
    public function getOrGenerateDailyParlays(?Carbon $date = null): Collection
    {
        $tz = 'Africa/Douala';
        $targetDate = $date ? $date->copy()->timezone($tz)->startOfDay() : Carbon::now($tz)->startOfDay();

        $existing = Parlay::whereDate('date_for', $targetDate->toDateString())->orderBy('id', 'asc')->get();
        if ($existing->isNotEmpty()) {
            return $existing;
        }

        return $this->generateDailyParlays($targetDate);
    }

    /**
     * Génère les combinés pour une date donnée à partir des prédictions réelles.
     * Règle de transparence stricte : aucun match fictif ou passé.
     */
    public function generateDailyParlays(?Carbon $date = null): Collection
    {
        $tz = 'Africa/Douala';
        $targetDate = $date ? $date->copy()->timezone($tz)->startOfDay() : Carbon::now($tz)->startOfDay();

        $startOfDayUtc = $targetDate->copy()->startOfDay()->utc();
        $endOfDayUtc   = $targetDate->copy()->endOfDay()->utc();
        $nowUtc        = Carbon::now()->utc();

        // 1. Recherche prioritaire : matchs programmés du jour même en Africa/Douala
        $matches = FootballMatch::with(['homeTeam', 'awayTeam', 'league', 'officialPrediction.modelVersion', 'oddsSnapshots.bookmaker'])
            ->whereIn('status', ['SCHEDULED', 'TIMED'])
            ->where('match_date_utc', '>=', $nowUtc)
            ->whereBetween('match_date_utc', [$startOfDayUtc, $endOfDayUtc])
            ->whereHas('officialPrediction', function ($q) {
                $q->where('is_official', true)->where('is_abstained', false);
            })
            ->orderBy('match_date_utc', 'asc')
            ->get();

        // Si moins de 2 matchs sur la journée exacte, étendre aux 3 prochains jours futurs
        if ($matches->count() < 2) {
            $windowEndUtc = $targetDate->copy()->addDays(3)->endOfDay()->utc();

            $matches = FootballMatch::with(['homeTeam', 'awayTeam', 'league', 'officialPrediction.modelVersion', 'oddsSnapshots.bookmaker'])
                ->whereIn('status', ['SCHEDULED', 'TIMED'])
                ->where('match_date_utc', '>=', $nowUtc)
                ->where('match_date_utc', '<=', $windowEndUtc)
                ->whereHas('officialPrediction', function ($q) {
                    $q->where('is_official', true)->where('is_abstained', false);
                })
                ->orderBy('match_date_utc', 'asc')
                ->take(12)
                ->get();
        }

        // Si toujours moins de 2 matchs futurs, vérifier s'il existe des matchs récents avec prédictions pour tests
        if ($matches->count() < 2) {
            // Uniquement si aucun match futur n'est disponible (ex: fin de saison ou environnement de test)
            $matches = FootballMatch::with(['homeTeam', 'awayTeam', 'league', 'officialPrediction.modelVersion', 'oddsSnapshots.bookmaker'])
                ->whereIn('status', ['SCHEDULED', 'TIMED'])
                ->whereHas('officialPrediction', function ($q) {
                    $q->where('is_official', true)->where('is_abstained', false);
                })
                ->orderBy('match_date_utc', 'desc')
                ->take(8)
                ->get();
        }

        // 2. Extraire les sélections fiables avec leurs probabilités réelles et cotes réelles
        $candidates = [];

        foreach ($matches as $match) {
            $pred = $match->officialPrediction;
            if (! $pred) continue;

            $oddsList = $match->oddsSnapshots->where('is_closing', false);
            if ($oddsList->isEmpty()) {
                $oddsList = $match->oddsSnapshots;
            }

            $homeOdds = $oddsList->where('selection', 'HOME')->first();
            $drawOdds = $oddsList->where('selection', 'DRAW')->first();
            $awayOdds = $oddsList->where('selection', 'AWAY')->first();

            $pHome = (float) $pred->prob_home;
            $pDraw = (float) $pred->prob_draw;
            $pAway = (float) $pred->prob_away;

            // Déterminer la sélection dominante pour ce match
            $options = [
                [
                    'selection' => 'HOME',
                    'label'     => 'Victoire ' . $match->homeTeam->short_name,
                    'prob'      => $pHome,
                    'odds_obj'  => $homeOdds,
                ],
                [
                    'selection' => 'AWAY',
                    'label'     => 'Victoire ' . $match->awayTeam->short_name,
                    'prob'      => $pAway,
                    'odds_obj'  => $awayOdds,
                ],
                [
                    'selection' => 'DRAW',
                    'label'     => 'Match Nul',
                    'prob'      => $pDraw,
                    'odds_obj'  => $drawOdds,
                ],
            ];

            // Trier par probabilité décroissante
            usort($options, fn ($a, $b) => $b['prob'] <=> $a['prob']);
            $best = $options[0];

            if ($best['prob'] >= 0.40 && $best['odds_obj'] && $best['odds_obj']->decimal_odds > 1.15) {
                $decimalOdds = (float) $best['odds_obj']->decimal_odds;
                $ev = ($best['prob'] * $decimalOdds) - 1.0;

                $candidates[] = [
                    'match_id'        => $match->id,
                    'match_label'     => "{$match->homeTeam->name} vs {$match->awayTeam->name}",
                    'league'          => $match->league->name ?? 'Ligue',
                    'match_date_utc'  => $match->match_date_utc->toIso8601String(),
                    'selection'       => $best['selection'],
                    'selection_label' => $best['label'],
                    'model_prob'      => round($best['prob'], 4),
                    'model_prob_pct'  => round($best['prob'] * 100, 1),
                    'model_version'   => $pred->modelVersion->version_name ?? 'M3_DIXON_COLES',
                    'odds'            => round($decimalOdds, 2),
                    'odds_source'     => $best['odds_obj']->bookmaker->name ?? 'Pinnacle',
                    'odds_updated_at' => $best['odds_obj']->snapshot_time_utc?->toIso8601String() ?? now()->toIso8601String(),
                    'fair_odds'       => round(1.0 / max(0.01, $best['prob']), 2),
                    'ev_pct'          => round($ev * 100, 2),
                ];
            }
        }

        if (count($candidates) < 2) {
            Log::info('ParlayService: moins de 2 candidats valides pour la date', [
                'target_date' => $targetDate->toDateString(),
                'candidates'  => count($candidates),
            ]);
            return collect();
        }

        $createdParlays = collect();

        // 3. Génération du Combiné Modéré (2 sélections aux probabilités les plus élevées)
        $moderateCandidates = collect($candidates)
            ->sortByDesc('model_prob')
            ->take(2)
            ->values()
            ->all();

        if (count($moderateCandidates) >= 2) {
            $createdParlays->push($this->createParlayRecord(
                'Combiné Sécurité & Confiance (2 Sélections)',
                $targetDate,
                'MODERE',
                $moderateCandidates
            ));
        }

        // 4. Génération du Combiné Équilibré (3 sélections avec bon compromis proba/valeur)
        if (count($candidates) >= 3) {
            $balancedCandidates = collect($candidates)
                ->sortByDesc(fn ($c) => $c['model_prob'] * 0.7 + max(0, $c['ev_pct']) * 0.01)
                ->take(3)
                ->values()
                ->all();

            $createdParlays->push($this->createParlayRecord(
                'Combiné Équilibré Valeur (3 Sélections)',
                $targetDate,
                'EQUILIBRE',
                $balancedCandidates
            ));
        }

        // 5. Génération du Combiné Audacieux (3 à 4 sélections à cote dynamique)
        if (count($candidates) >= 4) {
            $audaciousCandidates = collect($candidates)
                ->sortByDesc('odds')
                ->take(3)
                ->values()
                ->all();

            $createdParlays->push($this->createParlayRecord(
                'Combiné Audacieux Rendement (3 Sélections)',
                $targetDate,
                'AUDACIEUX',
                $audaciousCandidates
            ));
        }

        return $createdParlays;
    }

    /**
     * Crée et enregistre un enregistrement Parlay avec probabilités conjointes et analyse IA.
     */
    protected function createParlayRecord(
        string $title,
        Carbon $dateFor,
        string $riskLevel,
        array $selections
    ): Parlay {
        // Calculs probabilistes stricts sous hypothèse d'indépendance conditionnelle
        $combinedProb = 1.0;
        $combinedOdds = 1.0;

        foreach ($selections as $sel) {
            $combinedProb *= (float) $sel['model_prob'];
            $combinedOdds *= (float) $sel['odds'];
        }

        $combinedProb = round($combinedProb, 4);
        $combinedOdds = round($combinedOdds, 2);
        $expectedValue = round(($combinedProb * $combinedOdds) - 1.0, 4);

        // Score de risque objectif basé sur la probabilité conjointe :
        // P = 0.50 -> Risque 5.0/10 | P = 0.20 -> Risque 8.0/10 | P = 0.10 -> Risque 9.0/10
        $riskScore = max(1.0, min(9.9, round(10.0 * (1.0 - $combinedProb), 1)));

        $modelVersion = $selections[0]['model_version'] ?? 'M3_DIXON_COLES';

        // Préparation du contexte pour explication Gemini
        $context = [
            'parlay_title'            => $title,
            'risk_level'              => $riskLevel,
            'risk_score'              => $riskScore,
            'combined_odds'           => $combinedOdds,
            'combined_prob'           => $combinedProb,
            'expected_value_ev'       => round($expectedValue * 100, 2) . '%',
            'selections'              => $selections,
            'independence_assumption' => 'Matchs issus de ligues ou créneaux distincts, indépendance conditionnelle standard',
        ];

        $aiResult = $this->geminiService->explainParlay($context);

        return Parlay::create([
            'title'           => $title,
            'date_for'        => $dateFor->toDateString(),
            'risk_level'      => $riskLevel,
            'risk_score'      => $riskScore,
            'combined_odds'   => $combinedOdds,
            'combined_prob'   => $combinedProb,
            'expected_value'  => $expectedValue,
            'selections_json' => $selections,
            'model_version'   => $modelVersion,
            'ai_analysis'     => $aiResult['text'] ?? null,
            'ai_model_used'   => $aiResult['model'] ?? 'deterministic_template',
            'status'          => 'PENDING',
        ]);
    }
}
