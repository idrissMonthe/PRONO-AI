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
    /** @var array<string, int|null> */
    private array $marketIdCache = [];

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
                    $q->where('is_official', true)
                        ->when(
                            ! (bool) config('services.prediction.allow_limited_data', true),
                            fn ($sub) => $sub->where('is_abstained', false)
                        );
                })
                ->orderBy('match_date_utc', 'asc')
                ->take(12)
                ->get();
        }

        // Si toujours moins de 2 matchs (ex: trêve internationale ou pause), étendre aux 10 prochains jours
        if ($matches->count() < 2) {
            $windowEndUtc = $targetDate->copy()->addDays(10)->endOfDay()->utc();

            $matches = FootballMatch::with(['homeTeam', 'awayTeam', 'league', 'officialPrediction.modelVersion', 'oddsSnapshots.bookmaker'])
                ->whereIn('status', ['SCHEDULED', 'TIMED'])
                ->where('match_date_utc', '>=', $nowUtc)
                ->where('match_date_utc', '<=', $windowEndUtc)
                ->whereHas('officialPrediction', function ($q) {
                    $q->where('is_official', true)
                        ->when(
                            ! (bool) config('services.prediction.allow_limited_data', true),
                            fn ($sub) => $sub->where('is_abstained', false)
                        );
                })
                ->orderBy('match_date_utc', 'asc')
                ->take(16)
                ->get();
        }

        // 2. Extraire les sélections fiables de tous les marchés (1N2, Totaux Over/Under, Double Chance, BTTS)
        // en associant systématiquement les VRAIES COTES BOOKMAKERS de The Odds API aux probabilités du modèle Python.
        $candidates = [];

        foreach ($matches as $match) {
            $pred = $match->officialPrediction;
            if (! $pred) {
                continue;
            }

            $derived = $pred->derived_markets_json ?? [];

            // Les cotes sont lues PAR MARCHÉ : « HOME » existe aussi bien en
            // 1N2 qu'en handicap, et mélanger les deux produisait des prix
            // incohérents avec la probabilité affichée.
            $h2hOdds = $this->oddsFor($match, '1N2');
            $homeOdds = $h2hOdds->firstWhere('selection', 'HOME');
            $drawOdds = $h2hOdds->firstWhere('selection', 'DRAW');
            $awayOdds = $h2hOdds->firstWhere('selection', 'AWAY');

            $pHome = (float) ($derived['1N2']['home'] ?? $pred->prob_home);
            $pDraw = (float) ($derived['1N2']['draw'] ?? $pred->prob_draw);
            $pAway = (float) ($derived['1N2']['away'] ?? $pred->prob_away);

            $homeDisplay = $this->displayTeamName($match->homeTeam->name ?? $match->homeTeam->short_name);
            $awayDisplay = $this->displayTeamName($match->awayTeam->name ?? $match->awayTeam->short_name);
            $matchLabel = "{$homeDisplay} vs {$awayDisplay}";

            // A. Marché 1N2 (Résultat du Match avec cotes The Odds API)
            if ($homeOdds && (float)$homeOdds->decimal_odds > 1.15 && $pHome >= 0.35
                && $this->modelAgreesWithMarket($pHome, (float) $homeOdds->decimal_odds)) {
                $o = (float) $homeOdds->decimal_odds;
                $candidates[] = [
                    'match_id'        => $match->id,
                    'match_label'     => $matchLabel,
                    'league'          => $match->league->name ?? 'Ligue',
                    'match_date_utc'  => $match->match_date_utc->toIso8601String(),
                    'selection'       => 'HOME',
                    'selection_label' => "Victoire {$homeDisplay}",
                    'market_group'    => '1N2',
                    'model_prob'      => round($pHome, 4),
                    'model_prob_pct'  => round($pHome * 100, 1),
                    'model_version'   => $pred->modelVersion->version_name ?? 'M3_MARKET_SPECIALISTS_v6',
                    'odds'            => round($o, 2),
                    'odds_source'     => $homeOdds->bookmaker->name ?? 'Pinnacle',
                    'odds_updated_at' => $homeOdds->snapshot_time_utc?->toIso8601String() ?? now()->toIso8601String(),
                    'fair_odds'       => round(1.0 / max(0.01, $pHome), 2),
                    'ev_pct'          => round((($pHome * $o) - 1.0) * 100, 2),
                    'price_basis'     => 'bookmaker',
                ];
            }
            if ($awayOdds && (float)$awayOdds->decimal_odds > 1.15 && $pAway >= 0.35
                && $this->modelAgreesWithMarket($pAway, (float) $awayOdds->decimal_odds)) {
                $o = (float) $awayOdds->decimal_odds;
                $candidates[] = [
                    'match_id'        => $match->id,
                    'match_label'     => $matchLabel,
                    'league'          => $match->league->name ?? 'Ligue',
                    'match_date_utc'  => $match->match_date_utc->toIso8601String(),
                    'selection'       => 'AWAY',
                    'selection_label' => "Victoire {$awayDisplay}",
                    'market_group'    => '1N2',
                    'model_prob'      => round($pAway, 4),
                    'model_prob_pct'  => round($pAway * 100, 1),
                    'model_version'   => $pred->modelVersion->version_name ?? 'M3_MARKET_SPECIALISTS_v6',
                    'odds'            => round($o, 2),
                    'odds_source'     => $awayOdds->bookmaker->name ?? 'Pinnacle',
                    'odds_updated_at' => $awayOdds->snapshot_time_utc?->toIso8601String() ?? now()->toIso8601String(),
                    'fair_odds'       => round(1.0 / max(0.01, $pAway), 2),
                    'ev_pct'          => round((($pAway * $o) - 1.0) * 100, 2),
                    'price_basis'     => 'bookmaker',
                ];
            }

            // B. Marché Totaux de Buts (Over / Under avec vraies cotes bookmakers The Odds API)
            $totalsOdds = $this->oddsFor($match, 'totals')
                ->filter(fn ($odd) => in_array($odd->selection, ['OVER', 'UNDER'], true) && $odd->line !== null);
            foreach ($totalsOdds as $tOdd) {
                $line = (float) $tOdd->line;
                if ($line <= 0.0) continue;
                $key = ($tOdd->selection === 'OVER' ? 'over_' : 'under_') . str_replace('.', '_', (string) $line);
                $p = (float) ($derived['totals'][$key] ?? 0);
                if ($p === 0.0) {
                    $floorKey = ($tOdd->selection === 'OVER' ? 'over_' : 'under_') . str_replace('.', '_', (string) floor($line) . '_5');
                    $p = (float) ($derived['totals'][$floorKey] ?? 0);
                }
                if ($p >= 0.40 && (float)$tOdd->decimal_odds > 1.15
                    && $this->modelAgreesWithMarket($p, (float) $tOdd->decimal_odds)) {
                    $o = (float) $tOdd->decimal_odds;
                    $prefix = $tOdd->selection === 'OVER' ? '+' : '-';
                    $candidates[] = [
                        'match_id'        => $match->id,
                        'match_label'     => $matchLabel,
                        'league'          => $match->league->name ?? 'Ligue',
                        'match_date_utc'  => $match->match_date_utc->toIso8601String(),
                        'selection'       => $tOdd->selection . '_' . $line,
                        'selection_label' => "{$prefix}{$line} Buts",
                        'market_group'    => 'Total buts',
                        'model_prob'      => round($p, 4),
                        'model_prob_pct'  => round($p * 100, 1),
                        'model_version'   => $pred->modelVersion->version_name ?? 'M3_MARKET_SPECIALISTS_v6',
                        'odds'            => round($o, 2),
                        'odds_source'     => $tOdd->bookmaker->name ?? 'Pinnacle',
                        'odds_updated_at' => $tOdd->snapshot_time_utc?->toIso8601String() ?? now()->toIso8601String(),
                        'fair_odds'       => round(1.0 / max(0.01, $p), 2),
                        'ev_pct'          => round((($p * $o) - 1.0) * 100, 2),
                        'price_basis'     => 'bookmaker',
                    ];
                }
            }

            // B2. Handicap asiatique (cotes bookmakers réelles)
            foreach ($this->oddsFor($match, 'asian_handicap') as $hOdd) {
                if (! in_array($hOdd->selection, ['HOME', 'AWAY'], true) || $hOdd->line === null) {
                    continue;
                }
                $rawLine = (float) $hOdd->line;
                $homeLine = $hOdd->selection === 'HOME' ? $rawLine : -$rawLine;
                $entry = $derived['asian_handicap'][$this->handicapKey($homeLine)] ?? null;
                if (! $entry) {
                    continue;
                }
                $side = $hOdd->selection === 'HOME' ? 'home' : 'away';
                $p = (float) ($entry[$side]['win'] ?? 0) + 0.5 * (float) ($entry[$side]['half_win'] ?? 0);
                $o = (float) $hOdd->decimal_odds;
                // On ne retient que les handicaps lisibles : ni quasi-certitude,
                // ni scénario improbable déguisé en cote attractive.
                if ($p < 0.45 || $p > 0.80 || $o < 1.25 || $o > 3.20
                    || ! $this->modelAgreesWithMarket($p, $o)) {
                    continue;
                }
                $teamLabel = $hOdd->selection === 'HOME' ? $homeDisplay : $awayDisplay;
                $lineLabel = ($rawLine > 0 ? '+' : '') . rtrim(rtrim(number_format($rawLine, 2, ',', ''), '0'), ',');
                $candidates[] = [
                    'match_id'        => $match->id,
                    'match_label'     => $matchLabel,
                    'league'          => $match->league->name ?? 'Ligue',
                    'match_date_utc'  => $match->match_date_utc->toIso8601String(),
                    'selection'       => 'AH_' . $hOdd->selection . '_' . $rawLine,
                    'selection_label' => "{$teamLabel} handicap {$lineLabel}",
                    'market_group'    => 'Handicap',
                    'model_prob'      => round($p, 4),
                    'model_prob_pct'  => round($p * 100, 1),
                    'model_version'   => $pred->modelVersion->version_name ?? 'M3_MARKET_SPECIALISTS_v6',
                    'odds'            => round($o, 2),
                    'odds_source'     => $hOdd->bookmaker->name ?? 'Pinnacle',
                    'odds_updated_at' => $hOdd->snapshot_time_utc?->toIso8601String() ?? now()->toIso8601String(),
                    'fair_odds'       => round(1.0 / max(0.01, $p), 2),
                    'ev_pct'          => round((($p * $o) - 1.0) * 100, 2),
                    'price_basis'     => 'bookmaker',
                ];
            }

            // C. Marché Double Chance (Cotes bookmakers dérivées des cotes réelles The Odds API)
            if ($homeOdds && $drawOdds && $awayOdds) {
                $oH = (float) $homeOdds->decimal_odds;
                $oD = (float) $drawOdds->decimal_odds;
                $oA = (float) $awayOdds->decimal_odds;

                // Formule bookmaker standard pour cote combinée double chance
                $odds1X = round(1.0 / ((1.0 / $oH) + (1.0 / $oD)), 2);
                $oddsX2 = round(1.0 / ((1.0 / $oD) + (1.0 / $oA)), 2);

                $prob1X = (float) ($derived['double_chance']['1X'] ?? ($pHome + $pDraw));
                $probX2 = (float) ($derived['double_chance']['X2'] ?? ($pDraw + $pAway));

                if ($prob1X >= 0.55 && $odds1X >= 1.15 && $this->modelAgreesWithMarket($prob1X, $odds1X)) {
                    $candidates[] = [
                        'match_id'        => $match->id,
                        'match_label'     => $matchLabel,
                        'league'          => $match->league->name ?? 'Ligue',
                        'match_date_utc'  => $match->match_date_utc->toIso8601String(),
                        'selection'       => 'DC_1X',
                        'selection_label' => "{$homeDisplay} ou Nul (1X)",
                        'market_group'    => 'Double chance',
                        'model_prob'      => round($prob1X, 4),
                        'model_prob_pct'  => round($prob1X * 100, 1),
                        'model_version'   => $pred->modelVersion->version_name ?? 'M3_MARKET_SPECIALISTS_v6',
                        'odds'            => $odds1X,
                        'odds_source'     => $homeOdds->bookmaker->name ?? 'Pinnacle',
                        'odds_updated_at' => $homeOdds->snapshot_time_utc?->toIso8601String() ?? now()->toIso8601String(),
                        'fair_odds'       => round(1.0 / max(0.01, $prob1X), 2),
                        'ev_pct'          => round((($prob1X * $odds1X) - 1.0) * 100, 2),
                        'price_basis'     => 'bookmaker',
                    ];
                }
                if ($probX2 >= 0.55 && $oddsX2 >= 1.15 && $this->modelAgreesWithMarket($probX2, $oddsX2)) {
                    $candidates[] = [
                        'match_id'        => $match->id,
                        'match_label'     => $matchLabel,
                        'league'          => $match->league->name ?? 'Ligue',
                        'match_date_utc'  => $match->match_date_utc->toIso8601String(),
                        'selection'       => 'DC_X2',
                        'selection_label' => "Nul ou {$awayDisplay} (X2)",
                        'market_group'    => 'Double chance',
                        'model_prob'      => round($probX2, 4),
                        'model_prob_pct'  => round($probX2 * 100, 1),
                        'model_version'   => $pred->modelVersion->version_name ?? 'M3_MARKET_SPECIALISTS_v6',
                        'odds'            => $oddsX2,
                        'odds_source'     => $awayOdds->bookmaker->name ?? 'Pinnacle',
                        'odds_updated_at' => $awayOdds->snapshot_time_utc?->toIso8601String() ?? now()->toIso8601String(),
                        'fair_odds'       => round(1.0 / max(0.01, $probX2), 2),
                        'ev_pct'          => round((($probX2 * $oddsX2) - 1.0) * 100, 2),
                        'price_basis'     => 'bookmaker',
                    ];
                }
            }

            // D. Marché BTTS si cote disponible
            $bttsOdds = $this->oddsFor($match, 'btts')
                ->filter(fn ($odd) => in_array($odd->selection, ['YES', 'NO'], true));
            foreach ($bttsOdds as $bOdd) {
                $key = strtolower($bOdd->selection) === 'yes' ? 'yes' : 'no';
                $p = (float) ($derived['btts'][$key] ?? 0);
                if ($p >= 0.40 && (float)$bOdd->decimal_odds > 1.15 && $this->modelAgreesWithMarket($p, (float) $bOdd->decimal_odds)) {
                    $o = (float) $bOdd->decimal_odds;
                    $label = $key === 'yes' ? 'Les 2 équipes marquent' : 'Au moins une équipe ne marque pas';
                    $candidates[] = [
                        'match_id'        => $match->id,
                        'match_label'     => $matchLabel,
                        'league'          => $match->league->name ?? 'Ligue',
                        'match_date_utc'  => $match->match_date_utc->toIso8601String(),
                        'selection'       => 'BTTS_' . strtoupper($key),
                        'selection_label' => $label,
                        'market_group'    => 'BTTS',
                        'model_prob'      => round($p, 4),
                        'model_prob_pct'  => round($p * 100, 1),
                        'model_version'   => $pred->modelVersion->version_name ?? 'M3_MARKET_SPECIALISTS_v6',
                        'odds'            => round($o, 2),
                        'odds_source'     => $bOdd->bookmaker->name ?? 'Pinnacle',
                        'odds_updated_at' => $bOdd->snapshot_time_utc?->toIso8601String() ?? now()->toIso8601String(),
                        'fair_odds'       => round(1.0 / max(0.01, $p), 2),
                        'ev_pct'          => round((($p * $o) - 1.0) * 100, 2),
                        'price_basis'     => 'bookmaker',
                    ];
                }
            }
        }

        if (count($candidates) < 2) {
            Log::info('ParlayService: moins de 2 candidats valides pour la date', [
                'target_date' => $targetDate->toDateString(),
                'candidates'  => count($candidates),
            ]);
        }

        $createdParlays = collect();

        // Helper pour garantir qu'aucune sélection ne provient du même match
        $pickDistinctMatches = function (array $pool, int $count, callable $sortFn, int $maxPerMarket = 2): array {
            $sorted = collect($pool)->sort($sortFn)->values();
            $chosen = [];
            $usedMatches = [];
            $usedGroups = [];
            foreach ($sorted as $cand) {
                if (in_array($cand['match_id'], $usedMatches, true)) {
                    continue;
                }
                $group = $cand['market_group'] ?? 'autre';
                if (($usedGroups[$group] ?? 0) >= $maxPerMarket) {
                    continue;
                }
                $chosen[] = $cand;
                $usedMatches[] = $cand['match_id'];
                $usedGroups[$group] = ($usedGroups[$group] ?? 0) + 1;
                if (count($chosen) >= $count) {
                    break;
                }
            }
            return $chosen;
        };

        // 1. COMBINÉ SÉCURITÉ & CONFIANCE (MODERE, 2 sélections à très forte probabilité >= 60%)
        $moderatePool = collect($candidates)->where('model_prob', '>=', 0.60)->all();
        if (count($moderatePool) < 2) {
            $moderatePool = collect($candidates)->where('model_prob', '>=', 0.50)->all();
        }
        $moderateCandidates = $pickDistinctMatches($moderatePool, 2, fn ($a, $b) => $b['model_prob'] <=> $a['model_prob']);

        if (count($moderateCandidates) >= 2) {
            $createdParlays->push($this->createParlayRecord(
                'Combiné Sécurité & Confiance (2 Sélections)',
                $targetDate,
                'MODERE',
                $moderateCandidates
            ));
        }

        // 2. COMBINÉ ÉQUILIBRÉ VALEUR (EQUILIBRE, 3 sélections avec forte espérance mathématique +EV)
        $balancedPool = collect($candidates)
            ->where('model_prob', '>=', 0.40)
            ->where('odds', '>=', 1.40)
            ->where('odds', '<=', 3.50)
            ->all();
        if (count($balancedPool) < 3) {
            $balancedPool = $candidates;
        }
        $balancedCandidates = $pickDistinctMatches($balancedPool, 3, fn ($a, $b) => ($b['ev_pct'] * 0.6 + $b['model_prob_pct'] * 0.4) <=> ($a['ev_pct'] * 0.6 + $a['model_prob_pct'] * 0.4));

        if (count($balancedCandidates) >= 3) {
            $createdParlays->push($this->createParlayRecord(
                'Combiné Équilibré Valeur (3 Sélections)',
                $targetDate,
                'EQUILIBRE',
                $balancedCandidates
            ));
        }

        // 3. COMBINÉ AUDACIEUX RENDEMENT (AUDACIEUX, 3 sélections à cote dynamique)
        // Une sélection audacieuse reste un scénario plausible : probabilité
        // encadrée et écart modèle/marché borné pour écarter les cotes
        // incohérentes (données mal appariées) ou les quasi-impossibles.
        $audaciousPool = collect($candidates)
            ->where('model_prob', '>=', 0.28)
            ->where('model_prob', '<=', 0.55)
            ->where('odds', '>=', 1.70)
            ->where('odds', '<=', 4.50)
            ->where('ev_pct', '>=', -10)
            ->where('ev_pct', '<=', 35)
            ->all();
        if (count($audaciousPool) < 3) {
            $audaciousPool = collect($candidates)
                ->where('model_prob', '>=', 0.25)
                ->where('model_prob', '<=', 0.55)
                ->where('odds', '>=', 1.60)
                ->where('odds', '<=', 4.50)
                ->all();
        }
        $audaciousCandidates = $pickDistinctMatches($audaciousPool, 3, fn ($a, $b) => ($b['odds'] * 0.4 + $b['ev_pct'] * 0.6) <=> ($a['odds'] * 0.4 + $a['ev_pct'] * 0.6));

        if (count($audaciousCandidates) >= 3) {
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

        if ($matches->count() < 2) {
            $matches = FootballMatch::with(['homeTeam', 'awayTeam', 'league', 'officialPrediction.modelVersion'])
                ->whereIn('status', ['SCHEDULED', 'TIMED'])
                ->where('match_date_utc', '>=', Carbon::now()->utc())
                ->whereBetween('match_date_utc', [
                    $targetDate->copy()->startOfDay()->utc(),
                    $targetDate->copy()->addDays(10)->endOfDay()->utc(),
                ])
                ->whereHas('officialPrediction', fn ($query) => $query
                    ->where('is_official', true)
                    ->when(
                        ! (bool) config('services.prediction.allow_limited_data', true),
                        fn ($sub) => $sub->where('is_abstained', false)
                    ))
                ->orderBy('match_date_utc')
                ->take(16)
                ->get();
        }

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
        $allowLimited = (bool) config('services.prediction.allow_limited_data', true);
        $allowedTiers = $allowLimited ? ['low', 'medium', 'high'] : ['medium', 'high'];
        $minQuality   = $allowLimited ? 30 : 70;

        if ($prediction->is_abstained
            || ($isAiEstimate && ! (bool) config('services.hybrid_prediction.allow_ai_in_parlays', true))
            || (! $isAiEstimate && ! in_array($prediction->confidence_tier, $allowedTiers, true))
            || (! $isAiEstimate && (float) $prediction->quality_score < $minQuality)
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
        $home = $this->displayTeamName($match->homeTeam->name ?? $match->homeTeam->short_name);
        $away = $this->displayTeamName($match->awayTeam->name ?? $match->awayTeam->short_name);
        $options = [];

        $add = function (string $key, string $label, string $market, $probability) use (
            &$options,
            $match,
            $home,
            $away,
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
                'match_label' => "{$home} vs {$away}",
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

            $label = $published['label'];
            if ($published['market'] === '1N2') {
                $label = match ($published['selection']) {
                    'home' => "Victoire {$home}",
                    'away' => "Victoire {$away}",
                    'draw' => 'Match Nul',
                    default => $published['label'],
                };
            } elseif ($published['market'] === 'double_chance') {
                $label = match ($published['selection']) {
                    '1X' => "{$home} ou Nul",
                    'X2' => "Nul ou {$away}",
                    '12' => "Pas de nul",
                    default => $published['label'],
                };
            }

            $add($published['selection'], $label, $published['market'], $published['probability']);
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

    /**
     * Écarte les sélections où le modèle et le marché sont en contradiction
     * flagrante (ex. 76 % face à une cote de 2,44 → 41 % implicite) : un tel
     * écart signale une donnée de match mal appariée, pas une valeur exploitable.
     */
    private function modelAgreesWithMarket(float $modelProbability, float $decimalOdds): bool
    {
        if ($decimalOdds <= 1.0) {
            return false;
        }

        $implied = 1.0 / $decimalOdds;

        return abs($modelProbability - $implied) <= 0.22;
    }

    /**
     * Retourne les cotes d'un match pour un marché donné, la source la plus
     * fiable (The Odds API) et la plus récente d'abord.
     */
    private function oddsFor(FootballMatch $match, string $code): Collection
    {
        $marketId = $this->marketId($code);
        if ($marketId === null) {
            return collect();
        }

        return ($match->oddsSnapshots ?? collect())
            ->where('market_id', $marketId)
            ->sortByDesc(fn ($odd) => ($odd->source === 'the-odds-api' ? 1e10 : 0) + ($odd->snapshot_time_utc?->timestamp ?? 0))
            ->values();
    }

    private function marketId(string $code): ?int
    {
        if (! array_key_exists($code, $this->marketIdCache)) {
            $this->marketIdCache[$code] = \App\Models\Market::where('code', $code)->value('id');
        }

        return $this->marketIdCache[$code];
    }

    /**
     * Convertit une ligne de handicap côté domicile en clé du modèle
     * (`-0.5` → `m0_5`, `0` → `0_0`, `+0.5` → `0_5`).
     */
    private function handicapKey(float $line): string
    {
        $body = str_replace('.', '_', (string) abs($line));
        if (! str_contains($body, '_')) {
            $body .= '_0';
        }

        return $line < 0 ? 'm' . $body : $body;
    }

    /**
     * Nettoie et formate les noms d'équipes sans couper les mots.
     */
    public function displayTeamName(?string $name): string
    {
        if (! $name) return '';
        // Supprime les acronymes ou suffixes génériques qui polluent sans couper les mots
        $cleaned = preg_replace('/\b(FC|CF|SSC|AC|US|SV\s*07|1\.\s*FSV|1\.\s*FC)\b/i', '', $name);
        $cleaned = trim(preg_replace('/\s+/', ' ', $cleaned));
        return $cleaned ?: $name;
    }
}
