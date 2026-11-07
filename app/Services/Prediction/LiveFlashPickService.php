<?php

namespace App\Services\Prediction;

use App\Models\FootballMatch;
use App\Models\Prediction;
use Carbon\Carbon;

class LiveFlashPickService
{
    private const LIVE_STATUSES = ['LIVE', 'IN_PLAY', 'PAUSED'];

    public function __construct(private readonly LivePredictionEngine $liveEngine) {}

    public function build(FootballMatch $match, ?Carbon $now = null): ?array
    {
        $now ??= now()->utc();
        $prediction = $match->officialPrediction;

        if (! in_array($match->status, self::LIVE_STATUSES, true)
            || $match->home_score === null
            || $match->away_score === null
            || ! $match->match_date_utc
            || ! $match->last_synced_at
            || $match->last_synced_at->lt($now->copy()->subMinutes(3))
            || ! $prediction instanceof Prediction
            || ! $this->supportsLiveProjection($prediction)) {
            return null;
        }

        $elapsedSeconds = $now->timestamp - $match->match_date_utc->timestamp;
        $elapsedMinutes = (int) floor($elapsedSeconds / 60);
        if ($elapsedMinutes < 5 || $elapsedMinutes >= 89) {
            return null;
        }

        $projection = $this->liveEngine->project($match, $now);
        $candidates = [];

        foreach ([
            ['side' => 'home', 'name' => $match->homeTeam?->short_name, 'score' => $match->home_score, 'lambda' => $prediction->home_lambda],
            ['side' => 'away', 'name' => $match->awayTeam?->short_name, 'score' => $match->away_score, 'lambda' => $prediction->away_lambda],
        ] as $team) {
            if (! $team['name']) {
                continue;
            }

            $remainingGoalsLambda = $team['side'] === 'home'
                ? $projection['remaining_home_lambda']
                : $projection['remaining_away_lambda'];
            foreach ([0.5, 1.5, 2.5] as $line) {
                if ($team['score'] > $line) {
                    continue;
                }

                $goalsNeeded = (int) floor($line - $team['score']) + 1;
                $probability = $this->liveEngine->probabilityAtLeast($remainingGoalsLambda, $goalsNeeded);
                $minimumProbability = $this->hasLimitedTeamHistory($prediction) ? 0.72 : 0.58;
                if ($probability < $minimumProbability) {
                    continue;
                }

                $candidates[] = [
                    'prob' => $probability,
                    'label' => $team['name'].' +'.number_format($line, 1, ',', '').' buts',
                    'market' => 'Buts par équipe',
                ];
            }
        }

        $pick = collect($candidates)->sortByDesc('prob')->first();
        if (! $pick) {
            return null;
        }

        return array_merge($pick, [
            'match' => $match,
            'score' => $match->home_score.' - '.$match->away_score,
            'minute' => $elapsedMinutes,
            'source' => 'Prono flash · projection du modèle',
            'live_model' => 'in_play_bayesian_poisson_v1',
            'live_projection' => $projection,
        ]);
    }

    private function supportsLiveProjection(Prediction $prediction): bool
    {
        if (! $prediction->is_official
            || $prediction->is_abstained
            || ! in_array($prediction->confidence_tier, ['medium', 'high'], true)
            || (float) $prediction->quality_score < 70
            || (float) $prediction->home_lambda <= 0
            || (float) $prediction->away_lambda <= 0
            || (float) $prediction->home_lambda > 5
            || (float) $prediction->away_lambda > 5
            || $prediction->truncated_residual_mass === null
            || (float) $prediction->truncated_residual_mass > 0.01) {
            return false;
        }

        return ! collect($prediction->warnings_json ?? [])->contains(
            fn ($warning): bool => str_starts_with((string) $warning, 'model_not_trained')
                || str_starts_with((string) $warning, 'untrained_poisson_fallback')
                || str_starts_with((string) $warning, 'unknown_or_promoted_team:')
                || $warning === 'expected_goals_clamped'
        );
    }

    private function hasLimitedTeamHistory(Prediction $prediction): bool
    {
        return collect($prediction->warnings_json ?? [])
            ->contains(fn ($warning): bool => str_starts_with((string) $warning, 'low_sample_team:'));
    }

}
