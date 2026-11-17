<?php

namespace App\Console\Commands;

use App\Models\FootballMatch;
use App\Models\FeatureSnapshot;
use App\Models\Market;
use App\Models\ModelVersion;
use App\Models\Prediction;
use App\Services\Integrity\HashChainService;
use App\Services\FootballData\FootballDataService;
use App\Services\Odds\OddsService;
use App\Services\Prediction\PredictionClient;
use App\Services\Prediction\PredictionRevisionService;
use App\Models\PredictionRevision;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class GeneratePredictionsCommand extends Command
{
    protected $signature = 'oracle:generate-predictions {--limit=250 : Nombre maximal de matchs à analyser} {--force : Force la régénération de toutes les prédictions sans vérification du cache}';
    protected $description = 'Génère les prédictions officielles des prochains matchs.';

    public function handle(PredictionClient $client, HashChainService $chain, OddsService $oddsService, FootballDataService $footballDataService, PredictionRevisionService $revisionService): int
    {
        $model = ModelVersion::firstOrCreate(
            ['version_name' => 'M3_MARKET_SPECIALISTS_v6'],
            ['family' => 'hybrid', 'algorithm' => 'Dixon-Coles + marché calibré', 'status' => 'champion']
        );

        // Les deux fournisseurs alimentent le même pipeline : une rencontre
        // découverte via API-Football doit être prédite comme les autres.
        $matches = FootballMatch::with(['homeTeam', 'awayTeam', 'league', 'oddsSnapshots.market', 'playerAvailabilities', 'officialPrediction.featureSnapshot'])
            ->whereNotNull('external_id')
            ->whereIn('data_source', ['football-data.org', 'api-football'])
            ->whereIn('status', ['SCHEDULED', 'TIMED'])
            ->where('match_date_utc', '>=', now()->utc())
            ->orderBy('match_date_utc')
            ->limit(max(1, min(5000, (int) $this->option('limit'))))
            ->get();

        $marketId = Market::where('code', '1N2')->value('id');
        $generated = 0;
        foreach ($matches as $match) {
            try {
                // La qualité doit refléter la fraîcheur des cotes et des
                // données à l'instant de la prédiction, jamais une valeur figée.
                $quality = $footballDataService->refreshQualityScore($match->fresh());
                $latestOddsSet = $marketId
                    ? $oddsService->latestComplete1N2Set($match->oddsSnapshots, (int) $marketId)
                    : null;
                $marketProbabilities = null;
                if ($latestOddsSet) {
                    $marketProbabilities = $oddsService->proportionalProbabilities(
                        collect(['HOME', 'DRAW', 'AWAY'])
                            ->map(fn ($selection) => (float) $latestOddsSet[$selection]->decimal_odds)
                            ->all()
                    );
                }

                $absencePenalty = ['home' => 0.0, 'away' => 0.0];
                foreach ($match->playerAvailabilities as $availability) {
                    $weight = $availability->status === 'confirmed_absent' ? 0.05 : ($availability->status === 'doubtful' ? 0.025 : 0.0);
                    if ($availability->team_id === $match->home_team_id) {
                        $absencePenalty['home'] += $weight;
                    } elseif ($availability->team_id === $match->away_team_id) {
                        $absencePenalty['away'] += $weight;
                    }
                }
                $absencePenalty['home'] = min(0.30, $absencePenalty['home']);
                $absencePenalty['away'] = min(0.30, $absencePenalty['away']);

                $restDays = function (int $teamId) use ($match): float {
                    $lastMatch = FootballMatch::where('status', 'FINISHED')
                        ->where('match_date_utc', '<', $match->match_date_utc)
                        ->where(function ($query) use ($teamId) {
                            $query->where('home_team_id', $teamId)->orWhere('away_team_id', $teamId);
                        })
                        ->max('match_date_utc');
                    return $lastMatch ? max(0.0, Carbon::parse($lastMatch)->diffInHours($match->match_date_utc) / 24) : 7.0;
                };

                $homeRestDays = $restDays($match->home_team_id);
                $awayRestDays = $restDays($match->away_team_id);

                $recentForm = FootballMatch::with(['homeTeam:id,name', 'awayTeam:id,name'])
                    ->where('status', 'FINISHED')
                    ->where('match_date_utc', '<', $match->match_date_utc)
                    ->where(function ($query) use ($match) {
                        $query->where('home_team_id', $match->home_team_id)
                            ->orWhere('away_team_id', $match->home_team_id)
                            ->orWhere('home_team_id', $match->away_team_id)
                            ->orWhere('away_team_id', $match->away_team_id);
                    })
                    ->orderByDesc('match_date_utc')
                    ->take(10)
                    ->get()
                    ->map(fn (FootballMatch $recent): array => [
                        'date_utc' => $recent->match_date_utc?->toIso8601String(),
                        'home_team' => $recent->homeTeam?->name,
                        'away_team' => $recent->awayTeam?->name,
                        'score' => [$recent->home_score, $recent->away_score],
                    ])->values()->all();

                $leagueBaselines ??= [];
                $leagueId = (int) $match->league_id;
                if (! array_key_exists($leagueId, $leagueBaselines)) {
                    $leagueBaselines[$leagueId] = FootballMatch::query()
                        ->where('league_id', $leagueId)
                        ->where('status', 'FINISHED')
                        ->whereNotNull('home_score')
                        ->whereNotNull('away_score')
                        ->avg(DB::raw('home_score + away_score'));
                }
                $leagueName = strtolower((string) ($match->league?->name ?? ''));
                $leagueCode = strtoupper((string) ($match->league?->code ?? ''));
                $isInternational = in_array($leagueCode, ['WC', 'EC', 'CLI', 'NL', 'UNL', 'AFCON', 'CAN', 'INT', 'FIFA'], true)
                    || str_contains($leagueName, 'nation')
                    || str_contains($leagueName, 'friendly')
                    || str_contains($leagueName, 'amical')
                    || str_contains($leagueName, 'qualif')
                    || str_contains($leagueName, 'coupe d\'afrique')
                    || str_contains($leagueName, 'africa cup');

                $result = $client->predict([
                    'match_id' => (string) $match->id,
                    'home_team' => $match->homeTeam->name,
                    'away_team' => $match->awayTeam->name,
                    'is_neutral_ground' => (bool) $match->is_neutral_ground,
                    'quality_score' => (float) $quality['score'],
                    'market_probabilities' => $marketProbabilities,
                    'absence_penalty_home' => $absencePenalty['home'],
                    'absence_penalty_away' => $absencePenalty['away'],
                    'rest_days_home' => $homeRestDays,
                    'rest_days_away' => $awayRestDays,
                    'is_international' => $isInternational,
                    'competition_goals_per_match' => is_numeric($leagueBaselines[$leagueId] ?? null) && (float) $leagueBaselines[$leagueId] >= 0.4
                        ? round((float) $leagueBaselines[$leagueId], 3)
                        : null,
                    // Référence xG de la compétition ; le service se rabat sur
                    // la référence globale si elle est trop peu échantillonnée.
                    'competition' => $match->league?->code,
                ]);

                // Sélections plutôt qu'identifiants de marché : ces ids sont
                // propres au catalogue du fournisseur (15/18/21/…).
                $totalsMarketId = \App\Models\Market::where('code', 'totals')->value('id');
                $bttsMarketId = \App\Models\Market::where('code', 'btts')->value('id');
                $teamTotalsMarketId = \App\Models\Market::where('code', 'team_totals')->value('id');
                $doubleChanceMarketId = \App\Models\Market::where('code', 'double_chance')->value('id');

                $totalsOdds = $match->oddsSnapshots
                    ->whereIn('selection', ['OVER', 'UNDER'])
                    ->whereNotNull('line')
                    ->when($totalsMarketId !== null, fn ($collection) => $collection->where('market_id', $totalsMarketId))
                    ->groupBy(fn ($o) => (string) (float) $o->line)
                    ->map(fn ($group) => [
                        'over' => (float) ($group->firstWhere('selection', 'OVER')?->decimal_odds ?? null),
                        'under' => (float) ($group->firstWhere('selection', 'UNDER')?->decimal_odds ?? null),
                    ])->all();

                $bttsOdds = $match->oddsSnapshots
                    ->whereIn('selection', ['YES', 'NO'])
                    ->when($bttsMarketId !== null, fn ($collection) => $collection->where('market_id', $bttsMarketId))
                    ->mapWithKeys(fn ($o) => [strtolower($o->selection) => (float) $o->decimal_odds])
                    ->all();

                $teamTotalsOdds = $match->oddsSnapshots
                    ->whereNotNull('line')
                    ->when($teamTotalsMarketId !== null, fn ($collection) => $collection->where('market_id', $teamTotalsMarketId))
                    ->mapWithKeys(fn ($o) => [strtolower((string) $o->selection).'@'.(float) $o->line => (float) $o->decimal_odds])
                    ->all();

                $doubleChanceOdds = $match->oddsSnapshots
                    ->whereIn('selection', ['1X', 'X2', '12'])
                    ->when($doubleChanceMarketId !== null, fn ($collection) => $collection->where('market_id', $doubleChanceMarketId))
                    ->mapWithKeys(fn ($o) => [(string) $o->selection => (float) $o->decimal_odds])
                    ->all();

                $handicapOdds = $match->oddsSnapshots
                    ->filter(fn ($odd) => ($odd->market?->code) === 'asian_handicap')
                    ->map(fn ($odd) => [
                        'selection' => (string) $odd->selection,
                        'line' => (float) $odd->line,
                        'odds' => (float) $odd->decimal_odds,
                    ])->values()->all();

                $oddsContext = [
                    'match_id' => (string) $match->id,
                    'home_team' => $match->homeTeam->name,
                    'away_team' => $match->awayTeam->name,
                    'handicap' => $handicapOdds,
                    '1N2' => $latestOddsSet ? [
                        'home' => (float) ($latestOddsSet['HOME']->decimal_odds ?? 0),
                        'draw' => (float) ($latestOddsSet['DRAW']->decimal_odds ?? 0),
                        'away' => (float) ($latestOddsSet['AWAY']->decimal_odds ?? 0),
                    ] : null,
                    'totals' => $totalsOdds,
                    'btts' => $bttsOdds,
                    'team_totals' => $teamTotalsOdds,
                    'double_chance' => $doubleChanceOdds,
                ];

                $probabilities = $result['probabilities'] ?? null;
                $review = $revisionService->review($result, [
                    'match_id' => (string) $match->id,
                    'home_team' => $match->homeTeam->name,
                    'away_team' => $match->awayTeam->name,
                    'quality_score' => (float) $quality['score'],
                    'quality_breakdown' => $quality['breakdown'] ?? [],
                    'market_probabilities' => $marketProbabilities,
                    'absence_penalty' => $absencePenalty,
                    'rest_days' => ['home' => $homeRestDays, 'away' => $awayRestDays],
                    'missing_data' => $quality['missing'] ?? [],
                    'recent_form' => $recentForm,
                    'odds_context' => $oddsContext,
                    'odds_snapshot' => $latestOddsSet ? $latestOddsSet->map(fn ($odd) => [
                        'selection' => $odd->selection,
                        'decimal_odds' => (float) $odd->decimal_odds,
                        'source' => $odd->source,
                        'snapshot_time_utc' => $odd->snapshot_time_utc?->toIso8601String(),
                    ])->values()->all() : [],
                    'initial_candidate' => null,
                    'candidates' => $revisionService->candidatesFromResult($result, $oddsContext),
                ]);
                $review['initial_candidate'] = $review['initial_candidate'] ?? $review['final_candidate'];
                $snapshotVariables = [
                    'as_of_utc' => now()->utc()->toIso8601String(),
                    'quality' => $quality,
                    'market_probabilities' => $marketProbabilities,
                    'model_probabilities' => is_array($probabilities) ? [
                        'home' => $probabilities['home']['p'],
                        'draw' => $probabilities['draw']['p'],
                        'away' => $probabilities['away']['p'],
                    ] : null,
                    'expected_goals' => $result['expected_goals'] ?? [],
                    'absence_penalty' => $absencePenalty,
                    'rest_days' => ['home' => $homeRestDays, 'away' => $awayRestDays],
                    'recent_form' => $recentForm,
                    'warnings' => $result['warnings'] ?? [],
                    'revision_policy' => PredictionRevisionService::POLICY_VERSION,
                ];
                $fingerprintVariables = $snapshotVariables;
                unset($fingerprintVariables['as_of_utc']);
                $snapshotHash = hash('sha256', json_encode($fingerprintVariables, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                if (! $this->option('force') && $match->officialPrediction?->featureSnapshot?->snapshot_hash === $snapshotHash) {
                    continue;
                }

                $latestPredictionId = $match->officialPrediction?->id;
                $orderedProbabilities = collect($snapshotVariables['model_probabilities'] ?? [])->sortDesc()->values();
                $probabilityMargin = (float) $orderedProbabilities->get(0, 0) - (float) $orderedProbabilities->get(1, 0);
                if (! is_array($probabilities) || ! isset($probabilities['home']['p'], $probabilities['draw']['p'], $probabilities['away']['p'])) {
                    $match->forceFill([
                        'analysis_status' => 'LIMITED_DATA',
                        'analysis_source' => $review['provider'] ?? 'python_unavailable',
                        'analysis_reason' => $review['reasoning'] ?? 'Le modèle Python ne peut pas calculer ce match.',
                        'analysis_missing_json' => array_values(array_unique(array_merge($quality['missing'] ?? [], $review['missing_data'] ?? [], $result['warnings'] ?? []))),
                        'analysis_updated_at' => now()->utc(),
                    ])->saveQuietly();
                    continue;
                }

                $prediction = DB::transaction(function () use ($match, $model, $result, $review, $chain, $quality, $marketProbabilities, $snapshotVariables, $snapshotHash, $latestPredictionId, $probabilityMargin, $revisionService) {
                    $probabilities = $result['probabilities'];
                    $initialPrediction = Prediction::create([
                        'match_id' => $match->id,
                        'model_version_id' => $model->id,
                        'calibration_version' => $result['model']['calibration_version'] ?? null,
                        'is_official' => false,
                        'prediction_stage' => 'initial',
                        'prediction_origin' => 'PYTHON_ESTIMATE',
                        'source_model' => $result['model']['version'] ?? 'python',
                        'missing_data_json' => $quality['missing'] ?? [],
                        'uncertainty_tier' => $result['confidence_tier'] ?? 'low',
                        'freeze_time_utc' => now()->utc(),
                        'supersedes_id' => $latestPredictionId,
                        'content_hash' => str_repeat('0', 64),
                        'home_lambda' => $result['expected_goals']['home'],
                        'away_lambda' => $result['expected_goals']['away'],
                        'truncated_residual_mass' => $result['matrix']['truncated_mass'] ?? 0,
                        'prob_home' => $probabilities['home']['p'],
                        'prob_draw' => $probabilities['draw']['p'],
                        'prob_away' => $probabilities['away']['p'],
                        'intervals_json' => [
                            'ci80' => ['home' => $probabilities['home']['ci80'], 'draw' => $probabilities['draw']['ci80'], 'away' => $probabilities['away']['ci80']],
                            'ci95' => ['home' => $probabilities['home']['ci95'], 'draw' => $probabilities['draw']['ci95'], 'away' => $probabilities['away']['ci95']],
                            'method' => $result['uncertainty_method'] ?? 'unavailable',
                            'valid_replicates' => (int) ($result['uncertainty_replicates'] ?? 0),
                        ],
                        'derived_markets_json' => $result['markets'] ?? [],
                        'top_scores_json' => $result['top_scores'] ?? [],
                        'quality_score' => $result['quality']['score'] ?? 0,
                        'quality_tier' => $result['quality']['tier'] ?? 'insufficient',
                        'confidence_tier' => $result['confidence_tier'] ?? 'low',
                        'is_abstained' => (bool) ($result['abstain'] ?? true),
                        'warnings_json' => $result['warnings'] ?? [],
                        'market_metrics_json' => [
                            'market_probabilities' => $marketProbabilities,
                            'model_probabilities' => $snapshotVariables['model_probabilities'],
                            'max_probability' => max($snapshotVariables['model_probabilities']),
                            'probability_margin' => $probabilityMargin,
                        ],
                        'execution_time_ms' => $result['duration_ms'] ?? null,
                    ]);
                    FeatureSnapshot::create([
                        'prediction_id' => $initialPrediction->id,
                        'as_of' => now()->utc(),
                        'variables' => $snapshotVariables,
                        'snapshot_hash' => $snapshotHash,
                        'missing_indicators' => $quality['missing'] ?? [],
                    ]);
                    $pythonCandidate = $review['python_candidate'] ?? $review['initial_candidate'] ?? null;
                    $alternative = $review['alternative'] ?? null;
                    $finalCandidate = is_array($pythonCandidate) ? $pythonCandidate : null;
                    $decision = $review['gemini_status'] ?? PredictionRevisionService::STATUS_FAILED;
                    $finalOrigin = 'PYTHON_ESTIMATE';
                    $finalIsAbstained = ! is_array($finalCandidate);
                    $storedReview = $revisionService->storedReview($review);
                    // Une seule prédiction officielle par rencontre : les
                    // précédentes restent en base comme historique, mais sont
                    // démises avant l'enregistrement de la nouvelle.
                    Prediction::where('match_id', $match->id)
                        ->where('is_official', true)
                        ->update(['is_official' => false]);

                    $finalPrediction = $initialPrediction->replicate();
                    $finalPrediction->fill([
                        'is_official' => true,
                        'prediction_stage' => 'final',
                        'prediction_origin' => $finalOrigin,
                        'source_model' => $result['model']['version'] ?? 'python',
                        'ai_estimate_json' => ($alternative['origin'] ?? null) === 'AI_ESTIMATE' ? $alternative : null,
                        'missing_data_json' => array_values(array_unique(array_merge($quality['missing'] ?? [], $review['missing_data'] ?? []))),
                        'uncertainty_tier' => $review['confidence_in_revision'] ?? ($result['confidence_tier'] ?? 'low'),
                        'supersedes_id' => $initialPrediction->id,
                        'content_hash' => str_repeat('0', 64),
                        'prev_hash' => null,
                        'published_market' => $finalCandidate['market'] ?? null,
                        'published_selection' => $finalCandidate['selection'] ?? null,
                        'published_line' => $finalCandidate['line'] ?? null,
                        'published_probability' => $finalCandidate['probability'] ?? null,
                        'review_decision' => $decision,
                        'review_confidence' => $review['confidence_in_revision'] ?? 'low',
                        'reviewed_at_utc' => now()->utc(),
                        'is_abstained' => $finalIsAbstained,
                        'warnings_json' => array_values(array_unique(array_merge($result['warnings'] ?? [], ['revision_'.$decision]))),
                        'market_metrics_json' => array_merge($initialPrediction->market_metrics_json ?? [], [
                            'revision' => ['decision' => $decision, 'provider' => $review['provider'] ?? 'local_revision_rules'],
                            'gemini_review' => $storedReview,
                            'published_odds' => isset($finalCandidate['odds']) ? (float) $finalCandidate['odds'] : null,
                            // Sans provenance bookmaker, le prix ne doit jamais
                            // être présenté comme une cote de marché.
                            'published_odds_source' => ($finalCandidate['odds_source'] ?? null) === 'bookmaker' ? 'bookmaker' : null,
                            // Traçabilité du mode données limitées, sans nouvelle migration.
                            'limited_data' => [
                                'mode' => (bool) ($result['limited_data_mode'] ?? false),
                                'abstain_reason' => $result['abstain_reason'] ?? null,
                                'quality_tier' => $result['quality']['tier'] ?? null,
                            ],
                        ]),
                    ]);
                    $finalPrediction->save();
                    FeatureSnapshot::create([
                        'prediction_id' => $finalPrediction->id,
                        'as_of' => now()->utc(),
                        'variables' => array_merge($snapshotVariables, ['revision' => $review]),
                        'snapshot_hash' => $snapshotHash,
                        'missing_indicators' => $quality['missing'] ?? [],
                    ]);
                    $chain->chainPrediction($initialPrediction);
                    $chain->chainPrediction($finalPrediction);
                    PredictionRevision::create([
                        'initial_prediction_id' => $initialPrediction->id,
                        'final_prediction_id' => $finalPrediction->id,
                        'decision' => $decision,
                        'provider' => $review['provider'] ?? 'local_revision_rules',
                        'ai_model' => $review['model'] ?? PredictionRevisionService::POLICY_VERSION,
                        'initial_candidate' => $review['initial_candidate'] ?? null,
                        'ai_recommendation' => $alternative,
                        'recalculated_candidate' => ($alternative['origin'] ?? null) === 'PYTHON' ? $alternative : null,
                        'final_candidate' => $finalCandidate,
                        'reasoning' => $review['reasoning'] ?? null,
                        'supporting_factors' => $review['supporting_factors'] ?? [],
                        'risk_factors' => $review['risk_factors'] ?? [],
                        'validation' => [
                            'accepted' => $decision === PredictionRevisionService::STATUS_VALIDATED,
                            'gemini_status' => $decision,
                            'python_preserved' => is_array($pythonCandidate),
                            'alternative_origin' => $alternative['origin'] ?? null,
                            'published_selection' => $storedReview['published_selection'] ?? null,
                            'policy' => PredictionRevisionService::POLICY_VERSION,
                        ],
                        'reviewed_at_utc' => now()->utc(),
                    ]);
                    return $finalPrediction;
                });
                $geminiStatus = $review['gemini_status'] ?? PredictionRevisionService::STATUS_FAILED;
                $analysisStatus = $prediction->is_abstained
                    ? 'LIMITED_DATA'
                    : ($geminiStatus === PredictionRevisionService::STATUS_VALIDATED
                        ? 'HYBRID_READY'
                        : 'PYTHON_READY');
                $match->forceFill([
                    'analysis_status' => $analysisStatus,
                    'analysis_source' => $prediction->prediction_origin,
                    'analysis_reason' => $review['reasoning'] ?? null,
                    'analysis_missing_json' => $prediction->missing_data_json ?? [],
                    'analysis_updated_at' => now()->utc(),
                ])->saveQuietly();
                $generated++;
                $this->line("✓ {$match->homeTeam->short_name} – {$match->awayTeam->short_name} ({$prediction->confidence_tier})");
            } catch (Throwable $e) {
                $match->forceFill([
                    'analysis_status' => 'NO_ANALYSIS',
                    'analysis_source' => 'system_error',
                    'analysis_reason' => 'Analyse indisponible temporairement : '.$e->getMessage(),
                    'analysis_missing_json' => ['analysis_exception'],
                    'analysis_updated_at' => now()->utc(),
                ])->saveQuietly();
                $this->warn("Échec match #{$match->id} : {$e->getMessage()}");
            }
        }

        $this->info("{$generated} prédiction(s) officielle(s) générée(s).");
        return self::SUCCESS;
    }

}
