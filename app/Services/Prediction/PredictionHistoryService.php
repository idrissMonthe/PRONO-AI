<?php

namespace App\Services\Prediction;

use App\Models\CombinationSnapshot;
use App\Models\CombinationSnapshotLeg;
use App\Models\FootballMatch;
use App\Models\ForecastRun;
use App\Models\Parlay;
use App\Models\Prediction;
use App\Models\PredictionSettlement;
use App\Models\PredictionSnapshot;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Historisation immuable des pronostics publiés.
 *
 * Règles appliquées :
 * - un snapshot est créé, jamais modifié ; une nouvelle génération produit de
 *   nouvelles lignes et l'ancienne probabilité reste celle qui a été publiée ;
 * - le règlement vit dans `prediction_settlements` et ne touche pas aux
 *   probabilités ;
 * - un match non terminé, ou un marché que les données ne permettent pas de
 *   régler, reste PENDING : aucun résultat n'est inventé.
 */
class PredictionHistoryService
{
    public const FEATURE_VERSION = 'features-v1';

    public function __construct(private readonly PredictionSettlementService $settlements)
    {
    }

    /** Nombre de snapshots conservés par génération (borne la taille des lots). */
    private const MAX_SNAPSHOTS_PER_RUN = 40;

    /**
     * Enregistre la prédiction officielle publiée d'une rencontre.
     *
     * Retourne le run créé (ou existant) pour cette génération, même si tous
     * les snapshots étaient déjà connus : la génération reste traçable.
     */
    public function recordPrediction(FootballMatch $match, Prediction $prediction): ?ForecastRun
    {
        $market = $prediction->published_market;
        $selection = $prediction->published_selection;
        $probability = $prediction->published_probability;

        if (! is_string($market) || $market === '' || ! is_string($selection) || $selection === '' || $probability === null) {
            return null;
        }

        $now = now()->utc();
        $modelVersion = (string) ($prediction->source_model ?: 'python');
        $calibrationVersion = $prediction->calibration_version ?: null;
        $metrics = (array) ($prediction->market_metrics_json ?? []);
        $limited = (array) ($metrics['limited_data'] ?? []);

        $dataSources = array_values(array_filter([
            $match->data_source ?: null,
            'model_score_matrix',
            ($metrics['published_odds_source'] ?? null) === 'bookmaker' ? 'market_odds' : null,
        ]));

        $run = ForecastRun::firstOrCreate(
            ['payload_hash' => hash('sha256', implode('|', [$match->id, $prediction->id, $modelVersion, $now->format('Y-m-d H:i')]))],
            [
                'match_id' => $match->id,
                'match_date_utc' => $match->match_date_utc,
                'competition_code' => $match->league?->code,
                'season' => $match->season?->name,
                'generated_at_utc' => $now,
                'model_version' => $modelVersion,
                'calibration_version' => $calibrationVersion,
                'feature_version' => self::FEATURE_VERSION,
                'features_as_of' => $now,
                'data_sources' => $dataSources,
                'prediction_count' => 0,
                'status' => 'published',
                'context_json' => [
                    'analysis_status' => $match->analysis_status,
                    'quality_tier' => $prediction->quality_tier,
                    'confidence_tier' => $prediction->confidence_tier,
                    'limited_data_mode' => (bool) ($limited['mode'] ?? false),
                    'warnings' => array_values((array) ($prediction->warnings_json ?? [])),
                ],
            ]
        );

        $entries = $this->publishedEntries($prediction, (string) $market, (string) $selection, (float) $probability);
        $created = 0;

        foreach (array_slice($entries, 0, self::MAX_SNAPSHOTS_PER_RUN) as $entry) {
            $hash = hash('sha256', implode('|', [
                $match->id,
                $entry['market_key'],
                $entry['selection'],
                number_format($entry['probability'], 6, '.', ''),
                $modelVersion,
            ]));

            $snapshot = PredictionSnapshot::firstOrCreate(
                ['content_hash' => $hash],
                [
                    'forecast_run_id' => $run->id,
                    'prediction_id' => $prediction->id,
                    'match_id' => $match->id,
                    'market_key' => $entry['market_key'],
                    'market_group' => $entry['market_group'],
                    'selection' => $entry['selection'],
                    'line' => $entry['line'] ?? null,
                    'category' => $entry['category'] ?? null,
                    'probability' => round($entry['probability'], 6),
                    'fair_odds' => $entry['probability'] > 0 ? round(1.0 / $entry['probability'], 3) : null,
                    'odds_at_publication' => $entry['odds'] ?? ($metrics['published_odds'] ?? null),
                    'odds_provider' => ($metrics['published_odds_source'] ?? null) === 'bookmaker'
                        ? (string) ($metrics['published_odds_bookmaker'] ?? 'market')
                        : null,
                    'odds_timestamp_utc' => $entry['odds'] !== null ? $now : null,
                    'model_version' => $modelVersion,
                    'calibration_version' => $calibrationVersion,
                    'feature_version' => self::FEATURE_VERSION,
                    'coverage_status' => $entry['coverage'] ?? ($prediction->is_abstained ? 'limited' : 'covered'),
                    'reliability' => isset($entry['reliability']) && $entry['reliability'] !== null
                        ? number_format((float) $entry['reliability'], 4, '.', '')
                        : $prediction->confidence_tier,
                    'data_sources' => $dataSources,
                    'context_json' => [
                        'fair_odds' => $entry['probability'] > 0 ? round(1.0 / $entry['probability'], 3) : null,
                        'quality_score' => $prediction->quality_score,
                        'analysis_status' => $match->analysis_status,
                        'warnings' => array_values((array) ($prediction->warnings_json ?? [])),
                    ],
                    'published_at_utc' => $now,
                ]
            );

            if ($snapshot->wasRecentlyCreated) {
                $created++;
            }
        }

        if ($created > 0) {
            $run->increment('prediction_count', $created);
        }

        return $run;
    }

    /**
     * Sélections publiées d'une génération.
     *
     * @return array<int, array<string, mixed>>
     */
    private function publishedEntries(Prediction $prediction, string $market, string $selection, float $probability): array
    {
        $markets = (array) ($prediction->derived_markets_json ?? []);
        $entries = [[
            'market_key' => $market,
            'market_group' => app(PredictionRevisionService::class)->marketFamily($market, $selection),
            'selection' => $selection,
            'probability' => $probability,
            'line' => $prediction->published_line,
            'odds' => isset($prediction->market_metrics_json['published_odds'])
                ? (float) $prediction->market_metrics_json['published_odds']
                : null,
        ]];

        // Catégorisation existante (les plus sûrs / équilibrés / audacieux),
        // reprise telle quelle sans créer de nouvelle grille. Les clés sont
        // celles du moteur Python : prudent / equilibre / audacieux.
        $categories = (array) ($markets['market_categories'] ?? []);
        foreach (['safe' => 'prudent', 'balanced' => 'equilibre', 'bold' => 'audacieux'] as $category => $key) {
            foreach (array_slice((array) ($categories[$key] ?? []), 0, 5) as $item) {
                if (! is_array($item)) {
                    continue;
                }
                $itemMarket = $item['market_id'] ?? $item['market'] ?? null;
                $itemSelection = $item['selection'] ?? null;
                $itemProbability = $item['probability'] ?? null;
                if (! is_string($itemMarket) || ! is_string($itemSelection) || ! is_numeric($itemProbability)) {
                    continue;
                }
                $entries[] = [
                    'market_key' => $itemMarket,
                    'market_group' => $item['family'] ?? app(PredictionRevisionService::class)->marketFamily($itemMarket, $itemSelection),
                    'selection' => $itemSelection,
                    'probability' => (float) $itemProbability,
                    'line' => $item['line'] ?? null,
                    'category' => $category,
                    'odds' => null, // Aucune cote historique n'accompagne ces sélections.
                    'reliability' => $item['reliability'] ?? null,
                    'coverage' => $item['coverage'] ?? null,
                    'data_sources' => is_array($item['data_sources'] ?? null) ? $item['data_sources'] : [],
                ];
            }
        }

        return $entries;
    }

    /**
     * Enregistre un combiné publié et ses sélections.
     */
    public function recordCombination(Parlay $parlay): ?CombinationSnapshot
    {
        $selections = (array) ($parlay->selections_json ?? []);
        if ($selections === []) {
            return null;
        }

        $hash = hash('sha256', implode('|', [
            $parlay->title,
            (string) $parlay->date_for,
            (string) $parlay->risk_level,
            json_encode($selections, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '',
        ]));

        $snapshot = CombinationSnapshot::firstOrCreate(
            ['content_hash' => $hash],
            [
                'parlay_id' => $parlay->id,
                'title' => (string) $parlay->title,
                'risk_level' => (string) $parlay->risk_level,
                'combined_probability' => (float) $parlay->combined_prob,
                'combined_odds' => $parlay->combined_odds !== null ? (float) $parlay->combined_odds : null,
                'expected_value' => $parlay->expected_value !== null ? (float) $parlay->expected_value : null,
                'model_version' => $parlay->model_version,
                'target_date' => $parlay->date_for,
                'legs_count' => count($selections),
                'status' => 'PENDING',
                'published_at_utc' => now()->utc(),
                'context_json' => ['ai_model_used' => $parlay->ai_model_used],
            ]
        );

        if ($snapshot->wasRecentlyCreated) {
            foreach ($selections as $selection) {
                if (! is_array($selection)) {
                    continue;
                }

                $matchId = $selection['match_id'] ?? null;
                $market = (string) ($selection['market'] ?? '');
                $pick = (string) ($selection['selection'] ?? '');
                if ($market === '' || $pick === '') {
                    continue;
                }

                $legSnapshot = $matchId ? PredictionSnapshot::query()
                    ->where('match_id', $matchId)
                    ->where('market_key', $market)
                    ->where('selection', $pick)
                    ->orderByDesc('published_at_utc')
                    ->first() : null;

                CombinationSnapshotLeg::create([
                    'combination_snapshot_id' => $snapshot->id,
                    'prediction_snapshot_id' => $legSnapshot?->id,
                    'match_id' => $matchId,
                    'market_key' => $market,
                    'selection' => $pick,
                    'probability' => isset($selection['probability']) && is_numeric($selection['probability'])
                        ? (float) $selection['probability']
                        : null,
                ]);
            }
        }

        return $snapshot;
    }

    /**
     * Règle les snapshots dont le match est terminé.
     *
     * @return array<string, int>
     */
    public function settlePending(int $limit = 500): array
    {
        $counters = ['settled' => 0, 'pending' => 0, 'unsupported' => 0];

        PredictionSnapshot::query()
            ->whereDoesntHave('settlement', fn ($query) => $query->where('outcome', '!=', 'PENDING'))
            ->with(['match.homeTeam', 'match.awayTeam', 'settlement'])
            ->orderBy('id')
            ->limit($limit)
            ->get()
            ->each(function (PredictionSnapshot $snapshot) use (&$counters): void {
                $match = $snapshot->match;
                if (! $match) {
                    return;
                }

                if ($match->status !== 'FINISHED' || $match->home_score === null || $match->away_score === null) {
                    $this->storeSettlement($snapshot, 'PENDING', null, $match, 'Résultat définitif indisponible : pronostic conservé en attente.');
                    $counters['pending']++;

                    return;
                }

                $outcome = $this->resolveOutcome($snapshot, $match);

                if ($outcome === null) {
                    $this->storeSettlement($snapshot, 'PENDING', null, $match, 'Marché non réglable avec les données disponibles.');
                    $counters['unsupported']++;

                    return;
                }

                $this->storeSettlement($snapshot, $outcome['outcome'], $outcome['actual_label'], $match, $outcome['notes']);
                $counters['settled']++;
            });

        $this->settleCombinations();

        return $counters;
    }

    private function storeSettlement(PredictionSnapshot $snapshot, string $outcome, ?string $actualLabel, FootballMatch $match, ?string $notes): void
    {
        $settled = $outcome !== 'PENDING';

        PredictionSettlement::updateOrCreate(
            ['prediction_snapshot_id' => $snapshot->id],
            [
                'outcome' => $outcome,
                'actual_label' => $actualLabel,
                'home_score' => $match->home_score,
                'away_score' => $match->away_score,
                'settled_at_utc' => $settled ? now()->utc() : null,
                'source' => $match->data_source ?: 'internal',
                'notes' => $notes,
            ]
        );
    }

    /**
     * @return array{outcome: string, actual_label: string|null, notes: string|null}|null
     */
    private function resolveOutcome(PredictionSnapshot $snapshot, FootballMatch $match): ?array
    {
        $homeGoals = (int) $match->home_score;
        $awayGoals = (int) $match->away_score;
        $home = (string) ($match->homeTeam?->short_name ?? $match->homeTeam?->name ?? 'Domicile');
        $away = (string) ($match->awayTeam?->short_name ?? $match->awayTeam?->name ?? 'Extérieur');
        $actualResult = $homeGoals === $awayGoals ? 'draw' : ($homeGoals > $awayGoals ? 'home' : 'away');
        $market = (string) $snapshot->market_key;
        $selection = (string) $snapshot->selection;

        if ($market === 'combos') {
            $hit = $this->settlements->comboHit($selection, $homeGoals, $awayGoals);

            return [
                'outcome' => $hit ? 'WON' : 'LOST',
                'actual_label' => sprintf('%d-%d', $homeGoals, $awayGoals),
                'notes' => 'Combiné interne réglé sur la matrice de score.',
            ];
        }

        if ($snapshot->line !== null && str_contains($market, 'asian_handicap')) {
            return $this->settleAsianHandicap($selection, (float) $snapshot->line, $homeGoals - $awayGoals);
        }

        $resolved = $this->settlements->selectionOutcome($market, $selection, $homeGoals, $awayGoals, $actualResult, $home, $away);

        if ($resolved === null) {
            return null;
        }

        $outcome = ! empty($resolved['push']) ? 'VOID' : ($resolved['hit'] ? 'WON' : 'LOST');

        return [
            'outcome' => $outcome,
            'actual_label' => $resolved['actual_label'] ?? null,
            'notes' => $resolved['label'] ?? null,
        ];
    }

    /**
     * Handicap asiatique, lignes entières et quarts comprises.
     *
     * @return array{outcome: string, actual_label: string|null, notes: string|null}|null
     */
    private function settleAsianHandicap(string $selection, float $line, int $margin): ?array
    {
        $normalized = strtoupper(trim($selection));
        $side = match (true) {
            str_contains($normalized, 'HOME'), $normalized === '1' => 'home',
            str_contains($normalized, 'AWAY'), $normalized === '2' => 'away',
            default => null,
        };

        if ($side === null) {
            return null;
        }

        // Handicap exprimé du point de vue de l'équipe sélectionnée.
        $adjusted = $side === 'home' ? $margin + $line : -$margin - $line;

        // Quart de ligne : demi-gain / demi-perte.
        if (abs($adjusted) === 0.25) {
            return $adjusted > 0
                ? ['outcome' => 'HALF_WON', 'actual_label' => 'Marge '.$margin, 'notes' => 'Quart de ligne gagné à moitié.']
                : ['outcome' => 'HALF_LOST', 'actual_label' => 'Marge '.$margin, 'notes' => 'Quart de ligne perdu à moitié.'];
        }

        if (abs($adjusted) < 1e-9) {
            return ['outcome' => 'VOID', 'actual_label' => 'Marge '.$margin, 'notes' => 'Handicap remboursé.'];
        }

        return [
            'outcome' => $adjusted > 0 ? 'WON' : 'LOST',
            'actual_label' => 'Marge '.$margin,
            'notes' => null,
        ];
    }

    /**
     * Règle les combinés dont toutes les sélections sont réglées.
     *
     * @return int
     */
    public function settleCombinations(): int
    {
        $settled = 0;

        CombinationSnapshot::query()
            ->where('status', 'PENDING')
            ->with('legs.snapshot.settlement')
            ->orderBy('id')
            ->limit(200)
            ->get()
            ->each(function (CombinationSnapshot $combination) use (&$settled): void {
                if ($combination->legs->isEmpty()) {
                    return;
                }

                $outcomes = $combination->legs->map(fn (CombinationSnapshotLeg $leg) => $leg->snapshot?->settlement?->outcome);
                if ($outcomes->contains(null) || $outcomes->contains('PENDING')) {
                    return; // Au moins une sélection non réglée : on attend.
                }

                $status = 'WON';
                if ($outcomes->contains('LOST') || $outcomes->contains('HALF_LOST')) {
                    $status = 'LOST';
                } elseif ($outcomes->every(fn ($outcome) => in_array($outcome, ['VOID'], true))) {
                    $status = 'VOID';
                } elseif ($outcomes->contains('VOID') || $outcomes->contains('HALF_WON')) {
                    $status = 'PARTIAL';
                }

                $combination->forceFill([
                    'status' => $status,
                    'settled_at_utc' => now()->utc(),
                ])->save();
                $settled++;
            });

        return $settled;
    }

    /**
     * Agrégats de performance historique, par marché, catégorie et version.
     *
     * @return array<string, mixed>
     */
    public function performance(): array
    {
        $rows = DB::table('prediction_snapshots as s')
            ->leftJoin('prediction_settlements as st', 'st.prediction_snapshot_id', '=', 's.id')
            ->select([
                's.market_key',
                's.market_group',
                's.category',
                's.model_version',
                's.probability',
                'st.outcome',
            ])
            ->get();

        $summarize = function (Collection $group): array {
            $decided = $group->filter(fn ($row) => in_array($row->outcome, ['WON', 'LOST'], true));
            $count = $decided->count();
            if ($count === 0) {
                return ['n' => 0, 'brier' => null, 'log_loss' => null, 'hit_rate' => null];
            }

            $brier = 0.0;
            $logLoss = 0.0;
            $hits = 0;
            foreach ($decided as $row) {
                $p = max(1e-6, min(1 - 1e-6, (float) $row->probability));
                $hit = $row->outcome === 'WON';
                $brier += ($p - ($hit ? 1.0 : 0.0)) ** 2;
                $logLoss += -log($hit ? $p : 1.0 - $p);
                $hits += $hit ? 1 : 0;
            }

            return [
                'n' => $count,
                'brier' => round($brier / $count, 4),
                'log_loss' => round($logLoss / $count, 4),
                'hit_rate' => round($hits / $count, 4),
            ];
        };

        $totals = [
            'pending' => $rows->filter(fn ($row) => $row->outcome === null || $row->outcome === 'PENDING')->count(),
            'void' => $rows->where('outcome', 'VOID')->count(),
            'half_won' => $rows->where('outcome', 'HALF_WON')->count(),
            'half_lost' => $rows->where('outcome', 'HALF_LOST')->count(),
        ];

        return [
            'overall' => $summarize($rows) + $totals,
            'snapshots' => $rows->count(),
            'by_market' => $rows->groupBy('market_group')->map(fn (Collection $group) => $summarize($group))->all(),
            'by_category' => $rows->groupBy(fn ($row) => $row->category ?? 'unspecified')->map(fn (Collection $group) => $summarize($group))->all(),
            'by_model' => $rows->groupBy(fn ($row) => $row->model_version ?? 'unknown')->map(fn (Collection $group) => $summarize($group))->all(),
        ];
    }
}
