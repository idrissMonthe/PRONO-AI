<?php

namespace App\Console\Commands;

use App\Models\FootballMatch;
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
        $matches = FootballMatch::with(['homeTeam', 'awayTeam'])
            ->where('status', 'FINISHED')
            ->whereNotNull('home_score')
            ->whereNotNull('away_score')
            ->orderByDesc('match_date_utc')
            ->limit(max(5, min(50000, (int) $this->option('limit'))))
            ->get()
            ->map(fn (FootballMatch $match) => [
                'home_team' => $match->homeTeam->name,
                'away_team' => $match->awayTeam->name,
                'home_goals' => $match->home_score,
                'away_goals' => $match->away_score,
                'days_ago' => max(0, Carbon::parse($match->match_date_utc)->diffInDays(now())),
                'is_neutral' => (bool) $match->is_neutral_ground,
            ])->values()->all();

        if (count($matches) < 5) {
            $this->error('Au moins 5 matchs terminés sont nécessaires pour entraîner le modèle.');
            return self::FAILURE;
        }

        try {
            $result = $predictionClient->train($matches);
            $this->info("Modèle entraîné sur {$result['match_count']} matchs et {$result['team_count']} équipes.");
            $this->line("Hash d’entraînement : {$result['training_hash']}");
            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error('Échec de l’entraînement : '.$e->getMessage());
            return self::FAILURE;
        }
    }
}
