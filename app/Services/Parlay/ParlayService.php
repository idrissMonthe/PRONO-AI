<?php

namespace App\Services\Parlay;

use App\Models\FootballMatch;
use App\Models\OddsSnapshot;
use App\Models\Parlay;
use App\Models\Prediction;
use App\Services\AIExplanation\GeminiExplanationService;
use App\Services\Prediction\PredictionRevisionService;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class ParlayService
{
    public function __construct(
        protected GeminiExplanationService $geminiService,
        protected PredictionRevisionService $revisionService
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
            $modelTitles = [
                'Gros combiné — 10 sélections diversifiées',
                'Scénario modèle prudent (marchés de buts)',
                'Scénario modèle équilibré (marchés variés)',
                'Scénario modèle audacieux (marchés de buts)',
            ];
            $existingModelTitles = $existing
                ->filter(fn (Parlay $parlay): bool => collect($parlay->selections_json ?? [])
                    ->contains(fn (array $selection): bool => ($selection['price_basis'] ?? null) === 'model_fair'))
                ->pluck('title')
                ->all();

            return count(array_diff($modelTitles, $existingModelTitles)) === 0
                ? $existing
                : $existing->merge($this->generateModelMarketParlays($targetDate));
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

        // 2. Extraire les sélections fiables avec leurs probabilités réelles et cotes réelles
        $candidates = [];
        $modelCandidates = [];

        foreach ($matches as $match) {
            $pred = $match->officialPrediction;
            if (! $pred) continue;

            if ($pred->published_market && $pred->published_market !== '1N2') {
                continue;
            }

            $modelCandidates = array_merge(
                $modelCandidates,
                $this->buildModelMarketCandidates($match, $pred)
            );

            $oddsList = $match->oddsSnapshots->where('is_closing', false)
                ->sortByDesc(fn ($odd) => ($odd->source === 'the-odds-api' ? 10000000000 : 0) + ($odd->snapshot_time_utc?->timestamp ?? 0));
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
            $publishedSelection = match ($pred->published_selection) {
                'home' => 'HOME',
                'draw' => 'DRAW',
                'away' => 'AWAY',
                default => null,
            };
            $best = ($pred->published_market === '1N2' && $publishedSelection)
                ? (collect($options)->firstWhere('selection', $publishedSelection) ?? $options[0])
                : $options[0];

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
                    'odds_source'     => $best['odds_obj']->source === 'the-odds-api'
                        ? ($best['odds_obj']->bookmaker->name ?? 'The Odds API')
                        : 'Historique local',
                    'odds_updated_at' => $best['odds_obj']->snapshot_time_utc?->toIso8601String() ?? now()->toIso8601String(),
                    'fair_odds'       => round(1.0 / max(0.01, $best['prob']), 2),
                    'ev_pct'          => round($ev * 100, 2),
                    'price_basis'     => 'bookmaker',
                ];
            }
        }

        if (count($candidates) < 2) {
            Log::info('ParlayService: moins de 2 candidats valides pour la date', [
                'target_date' => $targetDate->toDateString(),
                'candidates'  => count($candidates),
            ]);
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

        return $createdParlays->merge(
            $this->createModelMarketParlays($modelCandidates, $targetDate)
        );
    }

    /**
     * Génère les marchés modélisés manquants sans dupliquer les coupons déjà enregistrés.
     */
    private function generateModelMarketParlays(Carbon $targetDate): Collection
    {
        $matches = FootballMatch::with(['homeTeam', 'awayTeam', 'league', 'officialPrediction.modelVersion'])
            ->whereIn('status', ['SCHEDULED', 'TIMED'])
            ->where('match_date_utc', '>=', Carbon::now()->utc())
            ->whereBetween('match_date_utc', [
                $targetDate->copy()->startOfDay()->utc(),
                $targetDate->copy()->addDays(3)->endOfDay()->utc(),
            ])
            ->whereHas('officialPrediction', fn ($query) => $query
                ->where('is_official', true)
                ->where('is_abstained', false))
            ->orderBy('match_date_utc')
            ->take(12)
            ->get();

        $modelCandidates = $matches->flatMap(
            fn (FootballMatch $match): array => $match->officialPrediction
                ? $this->buildModelMarketCandidates($match, $match->officialPrediction)
                : []
        )->all();

        return $this->createModelMarketParlays($modelCandidates, $targetDate);
    }

    /**
     * Produit uniquement des sélections dérivées des marchés présents dans la matrice de scores.
     */
    private function buildModelMarketCandidates(FootballMatch $match, Prediction $prediction): array
    {
        $isAiEstimate = $prediction->prediction_origin === 'AI_ESTIMATE';
        if ($prediction->is_abstained
            || ($isAiEstimate && ! (bool) config('services.hybrid_prediction.allow_ai_in_parlays', true))
            || (! $isAiEstimate && ! in_array($prediction->confidence_tier, ['medium', 'high'], true))
            || (! $isAiEstimate && (float) $prediction->quality_score < 70)
            || ($isAiEstimate && ! $prediction->published_market)
            || (float) $prediction->home_lambda <= 0
            || (float) $prediction->away_lambda <= 0
            || (float) $prediction->truncated_residual_mass > 0.01) {
            return [];
        }

        $warnings = $prediction->warnings_json ?? [];
        if (collect($warnings)->contains(fn ($warning): bool => str_starts_with((string) $warning, 'model_not_trained')
            || str_starts_with((string) $warning, 'untrained_poisson_fallback')
            || str_starts_with((string) $warning, 'unknown_or_promoted_team:')
            || $warning === 'expected_goals_clamped')) {
            return [];
        }

        $limitedTeamHistory = collect($warnings)->contains(
            fn ($warning): bool => str_starts_with((string) $warning, 'low_sample_team:')
                || $warning === 'market_backed_prediction_with_limited_team_history'
        );
        $markets = $prediction->derived_markets_json ?? [];
        $home = $match->homeTeam->short_name;
        $away = $match->awayTeam->short_name;
        $options = [];

        $add = function (string $key, string $label, string $market, $probability) use (
            &$options,
            $match,
            $prediction,
            $limitedTeamHistory,
            $isAiEstimate
        ): void {
            if (! is_numeric($probability)) {
                return;
            }

            $probability = (float) $probability;
            if ($probability <= 0.0 || $probability >= 1.0) {
                return;
            }

            $fairOdds = round(1.0 / $probability, 2);
            $options[] = [
                'match_id' => $match->id,
                'match_label' => "{$match->homeTeam->name} vs {$match->awayTeam->name}",
                'league' => $match->league->name ?? 'Ligue',
                'match_date_utc' => $match->match_date_utc->toIso8601String(),
                'selection' => 'MODEL_'.$key,
                'selection_label' => $label,
                'market_group' => $market,
                'model_prob' => round($probability, 4),
                'model_prob_pct' => round($probability * 100, 1),
                'model_version' => $prediction->modelVersion->version_name ?? 'M3_DIXON_COLES',
                'odds' => $fairOdds,
                'odds_source' => 'Cote juste modèle — indicative',
                'odds_updated_at' => $prediction->freeze_time_utc?->toIso8601String(),
                'fair_odds' => $fairOdds,
                'ev_pct' => null,
                'price_basis' => 'model_fair',
                'confidence_tier' => $prediction->confidence_tier,
                'source' => $prediction->prediction_origin,
                'is_ai_estimate' => $isAiEstimate,
                'limited_team_history' => $limitedTeamHistory,
            ];
        };

        // Les coupons utilisent exclusivement la sélection finale validée.
        if ($prediction->published_market && $prediction->published_selection) {
            $published = collect($this->revisionService->candidatesFromResult([
                'markets' => $markets,
                'top_scores' => $prediction->top_scores_json ?? [],
            ]))->first(fn (array $candidate): bool => $candidate['market'] === $prediction->published_market
                && $candidate['selection'] === $prediction->published_selection);
            if (! $published) return [];
            $add($published['selection'], $published['label'], $published['market'], $published['probability']);
            return $options;
        }

        foreach ([1.5, 2.5, 3.5, 4.5] as $threshold) {
            $key = str_replace('.', '_', (string) $threshold);
            $line = str_replace('.', ',', (string) $threshold);
            $add("total_over_{$key}", "Plus de {$line} buts", 'Total buts', $markets['totals']["over_{$key}"] ?? null);
            $add("total_under_{$key}", "Moins de {$line} buts", 'Total buts', $markets['totals']["under_{$key}"] ?? null);
        }

        $add('btts_yes', 'Les deux équipes marquent', 'BTTS', $markets['btts']['yes'] ?? null);
        $add('btts_no', 'Au moins une équipe ne marque pas', 'BTTS', $markets['btts']['no'] ?? null);

        foreach ([
            '1X' => "{$home} ou nul",
            'X2' => "Nul ou {$away}",
            '12' => 'Pas de match nul',
        ] as $key => $label) {
            $add('double_chance_'.$key, $label, 'Double chance', $markets['double_chance'][$key] ?? null);
        }

        foreach (($markets['exact_totals'] ?? []) as $goals => $probability) {
            $add('exact_total_'.$goals, 'Total exact '.$goals.' but'.((int) $goals === 1 ? '' : 's'), 'Total exact', $probability);
        }

        foreach (['home' => $home, 'away' => $away] as $side => $team) {
            foreach ([0.5, 1.5, 2.5, 3.5] as $threshold) {
                $key = str_replace('.', '_', (string) $threshold);
                $line = str_replace('.', ',', (string) $threshold);
                $add(
                    "{$side}_over_{$key}",
                    "{$team} marque plus de {$line} but",
                    'Buts par équipe',
                    $markets['team_totals'][$side]["over_{$key}"] ?? null
                );
                $add(
                    "{$side}_under_{$key}",
                    "{$team} marque moins de {$line} but",
                    'Buts par équipe',
                    $markets['team_totals'][$side]["under_{$key}"] ?? null
                );
            }

            $add(
                "{$side}_clean_sheet",
                "{$team} ne concède pas de but",
                'Clean sheet',
                $markets['clean_sheet'][$side] ?? null
            );
        }

        foreach ([2, 3] as $margin) {
            foreach (['home' => $home, 'away' => $away] as $side => $team) {
                $key = "{$side}_by_{$margin}_plus";
                $add(
                    $key,
                    "{$team} gagne par {$margin} buts ou plus",
                    'Marge de buts',
                    $markets['goal_margin'][$key] ?? null
                );
            }
        }

        foreach ([
            'home_win_over_2_5' => "Victoire {$home} + plus de 2,5 buts",
            'away_win_over_2_5' => "Victoire {$away} + plus de 2,5 buts",
            'home_win_btts_yes' => "Victoire {$home} + les deux équipes marquent",
            'away_win_btts_yes' => "Victoire {$away} + les deux équipes marquent",
        ] as $key => $label) {
            $add($key, $label, 'Combiné', $markets['combos'][$key] ?? null);
        }

        return $options;
    }

    /**
     * Les seuils de sélection sont renforcés quand l'historique des équipes est limité.
     */
    private function createModelMarketParlays(array $candidates, Carbon $targetDate): Collection
    {
        $byMatch = collect($candidates)->groupBy('match_id');
        $created = collect();
        $tiers = [
            [
                'title' => 'Gros combiné — 10 sélections diversifiées',
                'risk' => 'GROS_COMBINE',
                'minimum_matches' => 10,
                'minimum' => 0.55,
                'limited_minimum' => 0.62,
                'maximum' => 1.0,
                'limited_maximum' => 1.0,
                'target' => 0.72,
                'limited_target' => 0.76,
            ],
            [
                'title' => 'Scénario modèle prudent (marchés de buts)',
                'risk' => 'MODERE',
                'minimum_matches' => 2,
                'minimum' => 0.72,
                'limited_minimum' => 0.78,
                'maximum' => 1.0,
                'target' => 0.85,
            ],
            [
                'title' => 'Scénario modèle équilibré (marchés variés)',
                'risk' => 'EQUILIBRE',
                'minimum_matches' => 3,
                'minimum' => 0.50,
                'limited_minimum' => 0.60,
                'maximum' => 0.72,
                'limited_maximum' => 0.78,
                'target' => 0.60,
                'limited_target' => 0.68,
            ],
            [
                'title' => 'Scénario modèle audacieux (marchés de buts)',
                'risk' => 'AUDACIEUX',
                'minimum_matches' => 3,
                'minimum' => 0.18,
                'limited_minimum' => 0.25,
                'maximum' => 0.50,
                'limited_maximum' => 0.60,
                'target' => 0.40,
                'limited_target' => 0.48,
            ],
        ];

        foreach ($tiers as $tier) {
            $selections = $byMatch->map(function (Collection $matchOptions) use ($tier): ?array {
                $limited = (bool) $matchOptions->first()['limited_team_history'];
                $minimum = $limited ? $tier['limited_minimum'] : $tier['minimum'];
                $maximum = $limited ? ($tier['limited_maximum'] ?? $tier['maximum']) : $tier['maximum'];
                $target = $limited ? ($tier['limited_target'] ?? $tier['target']) : $tier['target'];

                $eligible = $matchOptions->filter(fn (array $option): bool => $option['model_prob'] >= $minimum
                    && $option['model_prob'] < $maximum);

                return $eligible->isEmpty()
                    ? null
                    : $eligible->sortBy(fn (array $option): float => abs($option['model_prob'] - $target))->first();
            })->filter()->sortByDesc('model_prob')->values();

            if ($selections->count() < $tier['minimum_matches']) {
                continue;
            }

            $selectionLimit = $tier['risk'] === 'GROS_COMBINE' ? 10 : ($tier['risk'] === 'MODERE' ? 2 : 3);
            $selections = $this->diversifySelections($selections->all(), $selectionLimit);
            if (count($selections) < $tier['minimum_matches']) {
                continue;
            }
            if (Parlay::whereDate('date_for', $targetDate->toDateString())
                ->where('title', $tier['title'])
                ->exists()) {
                continue;
            }

            $created->push($this->createParlayRecord(
                $tier['title'],
                $targetDate,
                $tier['risk'],
                $selections,
                true
            ));
        }

        return $created;
    }

    /**
     * Réduit la corrélation d’un combiné en limitant à trois choix par
     * championnat lorsque plusieurs marchés offrent une probabilité similaire.
     */
    private function diversifySelections(array $selections, int $limit): array
    {
        $selected = [];
        $leagueCounts = [];
        foreach ($selections as $selection) {
            $league = (string) ($selection['league'] ?? 'unknown');
            if (($leagueCounts[$league] ?? 0) >= 3 && count($selections) - count($selected) > ($limit - count($selected))) {
                continue;
            }
            $selected[] = $selection;
            $leagueCounts[$league] = ($leagueCounts[$league] ?? 0) + 1;
            if (count($selected) >= $limit) break;
        }

        return $selected;
    }

    /**
     * Crée et enregistre un enregistrement Parlay avec probabilités conjointes et analyse IA.
     */
    protected function createParlayRecord(
        string $title,
        Carbon $dateFor,
        string $riskLevel,
        array $selections,
        bool $modelPriced = false
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
        $expectedValue = $modelPriced ? null : round(($combinedProb * $combinedOdds) - 1.0, 4);

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
            'expected_value_ev'       => $expectedValue === null
                ? 'non calculée : aucune cote bookmaker pour ces marchés'
                : round($expectedValue * 100, 2) . '%',
            'pricing_basis'           => $modelPriced ? 'cotes justes indicatives du modèle' : 'cotes bookmaker réelles',
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
