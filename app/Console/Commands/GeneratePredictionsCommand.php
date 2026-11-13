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
    protected $signature = 'oracle:generate-predictions {--limit=250 : Nombre maximal de matchs à analyser}';
    protected $description = 'Génère les prédictions officielles des prochains matchs.';

    public function handle(PredictionClient $client, HashChainService $chain, OddsService $oddsService, FootballDataService $footballDataService, PredictionRevisionService $revisionService): int
    {
        $model = ModelVersion::firstOrCreate(
            ['version_name' => 'M3_MARKET_SPECIALISTS_v6'],
            ['family' => 'hybrid', 'algorithm' => 'Dixon-Coles + marché calibré', 'status' => 'champion']
        );

        // Les deux fournisseurs alimentent le même pipeline : une rencontre
        // découverte via API-Football doit être prédite comme les autres.
        $matches = FootballMatch::with(['homeTeam', 'awayTeam', 'oddsSnapshots', 'playerAvailabilities', 'officialPrediction.featureSnapshot'])
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
                ]);

                $probabilities = $result['probabilities'] ?? null;
                $review = $revisionService->review($result, [
                    'home_team' => $match->homeTeam->name,
                    'away_team' => $match->awayTeam->name,
                    'quality_score' => (float) $quality['score'],
                    'quality_breakdown' => $quality['breakdown'] ?? [],
                    'market_probabilities' => $marketProbabilities,
                    'absence_penalty' => $absencePenalty,
                    'rest_days' => ['home' => $homeRestDays, 'away' => $awayRestDays],
                    'missing_data' => $quality['missing'] ?? [],
                    'recent_form' => $recentForm,
                    'odds_snapshot' => $latestOddsSet ? $latestOddsSet->map(fn ($odd) => [
                        'selection' => $odd->selection,
                        'decimal_odds' => (float) $odd->decimal_odds,
                        'source' => $odd->source,
                        'snapshot_time_utc' => $odd->snapshot_time_utc?->toIso8601String(),
                    ])->values()->all() : [],
                    'initial_candidate' => null,
                    'candidates' => $revisionService->candidatesFromResult($result),
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
                if ($match->officialPrediction?->featureSnapshot?->snapshot_hash === $snapshotHash) {
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

                $prediction = DB::transaction(function () use ($match, $model, $result, $review, $chain, $quality, $marketProbabilities, $snapshotVariables, $snapshotHash, $latestPredictionId, $probabilityMargin) {
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
                    $finalCandidate = $review['final_candidate'] ?? null;
                    $decision = $review['decision'] ?? 'insufficient_data';
                    $finalOrigin = $review['prediction_origin'] ?? 'PYTHON_ESTIMATE';
                    $aiAccepted = $finalOrigin === 'AI_ESTIMATE'
                        && in_array(strtoupper((string) config('services.hybrid_prediction.mode', 'ASSISTED')), ['ASSISTED', 'ACTIVE'], true);
                    $finalIsAbstained = ((bool) ($result['abstain'] ?? true) && ! $aiAccepted)
                        || in_array($decision, ['reject', 'insufficient_data'], true)
                        || ! is_array($finalCandidate);
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
                        'source_model' => $review['source_model'] ?? $result['model']['version'] ?? 'python',
                        'ai_estimate_json' => $review['ai_estimate'] ?? null,
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
                        'ai_recommendation' => $review['ai_response'] ?? $review['final_candidate'] ?? null,
                        'recalculated_candidate' => $review['final_candidate'] ?? null,
                        'final_candidate' => $finalCandidate,
                        'reasoning' => $review['reasoning'] ?? null,
                        'supporting_factors' => $review['supporting_factors'] ?? [],
                        'risk_factors' => $review['risk_factors'] ?? [],
                        'validation' => ['accepted' => ! $finalIsAbstained, 'policy' => PredictionRevisionService::POLICY_VERSION],
                        'reviewed_at_utc' => now()->utc(),
                    ]);
                    return $finalPrediction;
                });
                $analysisStatus = $prediction->is_abstained
                    ? 'LIMITED_DATA'
                    : ($prediction->prediction_origin === 'AI_ESTIMATE'
                        ? 'AI_ASSISTED'
                        : (($review['provider'] ?? '') === 'google_gemini' ? 'HYBRID_READY' : 'PYTHON_READY'));
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
