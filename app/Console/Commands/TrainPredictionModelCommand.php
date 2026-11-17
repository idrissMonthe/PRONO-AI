<?php

namespace App\Console\Commands;

use App\Models\FootballMatch;
use App\Models\TeamMatchStat;
use App\Services\Prediction\PredictionClient;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Throwable;

class TrainPredictionModelCommand extends Command
{
    protected $signature = 'oracle:train-model {--limit=5000 : Nombre maximal de matchs historiques}';
    protected $description = 'Entraîne le modèle Dixon-Coles sur les matchs terminés disponibles.';

    public function handle(PredictionClient $predictionClient): int
    {
        $matches = FootballMatch::with(['homeTeam', 'awayTeam', 'league:id,code'])
            ->where('status', 'FINISHED')
            ->whereNotNull('home_score')
            ->whereNotNull('away_score')
            ->orderByDesc('match_date_utc')
            ->limit(max(5, min(50000, (int) $this->option('limit'))))
            ->get();

        // Une seule requête pour toutes les statistiques : les champs xG
        // restent nuls quand aucune source ne les a mesurés.
        $stats = TeamMatchStat::query()
            ->whereIn('match_id', $matches->pluck('id')->all())
            ->get()
            ->keyBy(fn (TeamMatchStat $stat): string => $stat->match_id.':'.$stat->team_id);

        $rows = $matches->map(function (FootballMatch $match) use ($stats): array {
            $home = $stats->get($match->id.':'.$match->home_team_id);
            $away = $stats->get($match->id.':'.$match->away_team_id);

            return [
                'home_team' => $match->homeTeam->name,
                'away_team' => $match->awayTeam->name,
                'home_goals' => $match->home_score,
                'away_goals' => $match->away_score,
                'days_ago' => max(0, (int) Carbon::parse($match->match_date_utc)->diffInDays(now())),
                'is_neutral' => (bool) $match->is_neutral_ground,
                'competition' => $match->league?->code,
                'home_xg' => $home?->xg === null ? null : (float) $home->xg,
                'away_xg' => $away?->xg === null ? null : (float) $away->xg,
                'home_shots' => $home?->shots,
                'away_shots' => $away?->shots,
                'home_shots_on_target' => $home?->shots_on_target,
                'away_shots_on_target' => $away?->shots_on_target,
            ];
        })->values()->all();

        $matches = $rows;

        $withXg = collect($matches)->filter(fn (array $row): bool => $row['home_xg'] !== null && $row['away_xg'] !== null)->count();
        $this->line("Matchs avec xG mesuré : {$withXg} / ".count($matches).'.');

        if (count($matches) < 5) {
            $this->error('Au moins 5 matchs terminés sont nécessaires pour entraîner le modèle.');
            return self::FAILURE;
        }

        try {
            $result = $predictionClient->train($matches);
            $modelLabels = [
                'dixon_coles' => 'Dixon-Coles',
                'elo' => 'Elo',
                'market_specialists' => 'Spécialistes de marchés',
            ];
            foreach ($modelLabels as $key => $label) {
                $model = $result['models'][$key] ?? null;
                if (! is_array($model) || ! ($model['trained'] ?? false)) {
                    $this->error("L’entraînement du modèle {$label} n’a pas abouti.");
                    return self::FAILURE;
                }

                if ($key === 'dixon_coles') {
                    $this->line("{$label} : entraînement réussi ({$model['item_count']} équipes modélisées sur {$model['observed_count']} observées).");
                    continue;
                }

                $unit = $key === 'market_specialists' ? 'matchs' : 'équipes';
                $this->line("{$label} : entraînement réussi ({$model['item_count']} {$unit}).");
            }

            $this->info("Modèles entraînés sur {$result['match_count']} matchs et {$result['team_count']} équipes observées.");
            $this->line("Hash d’entraînement : {$result['training_hash']}");
            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error('Échec de l’entraînement : '.$e->getMessage());
            return self::FAILURE;
        }
    }
}
