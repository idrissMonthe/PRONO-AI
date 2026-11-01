<?php

namespace App\Console\Commands;

use App\Models\FootballMatch;
use App\Models\ModelVersion;
use App\Models\Prediction;
use App\Services\Integrity\HashChainService;
use App\Services\Prediction\PredictionClient;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class GeneratePredictionsCommand extends Command
{
    protected $signature = 'oracle:generate-predictions {--limit=100 : Nombre maximal de matchs à analyser}';
    protected $description = 'Génère les prédictions officielles des prochains matchs.';

    public function handle(PredictionClient $client, HashChainService $chain): int
    {
        $model = ModelVersion::firstOrCreate(
            ['version_name' => 'M3_MARKET_SPECIALISTS_v1'],
            ['family' => 'hybrid', 'algorithm' => 'Dixon-Coles + marché calibré', 'status' => 'champion']
        );

        $matches = FootballMatch::with(['homeTeam', 'awayTeam', 'oddsSnapshots', 'playerAvailabilities'])
            ->whereNotNull('external_id')
            ->where('data_source', 'football-data.org')
            ->whereIn('status', ['SCHEDULED', 'TIMED'])
            ->where('match_date_utc', '>=', now()->utc())
            ->whereDoesntHave('predictions', fn ($q) => $q->where('is_official', true)->where('model_version_id', $model->id))
            ->orderBy('match_date_utc')
            ->limit(max(1, min(5000, (int) $this->option('limit'))))
            ->get();

        $generated = 0;
        foreach ($matches as $match) {
            try {
                $activeOdds = $match->oddsSnapshots
                    ->where('is_closing', false)
                    ->sortByDesc(fn ($odd) => ($odd->source === 'the-odds-api' ? 10000000000 : 0) + ($odd->snapshot_time_utc?->timestamp ?? 0));
                $oddsBySelection = $activeOdds->keyBy('selection');
                $marketProbabilities = null;
                if ($oddsBySelection->has(['HOME', 'DRAW', 'AWAY'])) {
                    $inverse = collect(['HOME', 'DRAW', 'AWAY'])
                        ->map(fn ($selection) => 1 / max(1.01, (float) $oddsBySelection[$selection]->decimal_odds));
                    $sum = $inverse->sum();
                    $marketProbabilities = $inverse->map(fn ($value) => round($value / $sum, 6))->values()->all();
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

                $result = $client->predict([
                    'match_id' => (string) $match->id,
                    'home_team' => $match->homeTeam->name,
                    'away_team' => $match->awayTeam->name,
                    'is_neutral_ground' => (bool) $match->is_neutral_ground,
                    'quality_score' => $marketProbabilities !== null ? max(95.0, (float) ($match->quality_score ?? 0)) : (float) ($match->quality_score ?? 0),
                    'market_probabilities' => $marketProbabilities,
                    'absence_penalty_home' => $absencePenalty['home'],
                    'absence_penalty_away' => $absencePenalty['away'],
                    'rest_days_home' => $restDays($match->home_team_id),
                    'rest_days_away' => $restDays($match->away_team_id),
                ]);

                $prediction = DB::transaction(function () use ($match, $model, $result, $chain) {
                    $probabilities = $result['probabilities'];
                    $prediction = Prediction::create([
                        'match_id' => $match->id,
                        'model_version_id' => $model->id,
                        'calibration_version' => $result['model']['calibration_version'] ?? null,
                        'is_official' => true,
                        'freeze_time_utc' => now()->utc(),
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
                        ],
                        'derived_markets_json' => $result['markets'] ?? [],
                        'top_scores_json' => $result['top_scores'] ?? [],
                        'quality_score' => $result['quality']['score'] ?? 0,
                        'quality_tier' => $result['quality']['tier'] ?? 'insufficient',
                        'confidence_tier' => $result['confidence_tier'] ?? 'low',
                        'is_abstained' => (bool) ($result['abstain'] ?? true),
                        'warnings_json' => $result['warnings'] ?? [],
                        'execution_time_ms' => $result['duration_ms'] ?? null,
                    ]);
                    $chain->chainPrediction($prediction);
                    return $prediction;
                });
                $generated++;
                $this->line("✓ {$match->homeTeam->short_name} – {$match->awayTeam->short_name} ({$prediction->confidence_tier})");
            } catch (Throwable $e) {
                $this->warn("Échec match #{$match->id} : {$e->getMessage()}");
            }
        }

        $this->info("{$generated} prédiction(s) officielle(s) générée(s).");
        return self::SUCCESS;
    }
}
