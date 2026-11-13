<?php

use App\Console\Commands\GenerateParlaysCommand;
use App\Console\Commands\SyncMatchesCommand;
use App\Console\Commands\TrainPredictionModelCommand;
use App\Console\Commands\GeneratePredictionsCommand;
use App\Console\Commands\SyncOddsCommand;
use App\Console\Commands\SyncLiveScoresCommand;
use App\Console\Commands\SyncApiFootballCommand;
use App\Console\Commands\SettlePredictionsCommand;
use App\Console\Commands\MonitorOracleCommand;
use App\Console\Commands\EvaluatePredictionPerformanceCommand;
use App\Console\Commands\UpdateOddsMovementCommand;
use App\Console\Commands\RecalculateQualityCommand;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// ─────────────────────────────────────────────────────────────────────────────
// ORACLE FOOT — Tâches planifiées
// Fuseau horaire : Africa/Douala (UTC+1)
// ─────────────────────────────────────────────────────────────────────────────

// Synchronisation quotidienne des matchs football-data.org
// 3 fois par jour pour capturer les mises à jour de statuts et d'horaires
Schedule::command(SyncMatchesCommand::class)
    ->dailyAt('06:00')
    ->timezone('Africa/Douala')
    ->withoutOverlapping()
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/oracle-sync.log'));

Schedule::command(SyncOddsCommand::class)
    ->everyThirtyMinutes()
    ->timezone('Africa/Douala')
    ->withoutOverlapping()
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/oracle-odds.log'));

Schedule::command(UpdateOddsMovementCommand::class)
    ->everyTenMinutes()
    ->timezone('Africa/Douala')
    ->withoutOverlapping()
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/oracle-odds-movement.log'));

// Fait décroître la qualité lorsque les sources ou les cotes deviennent anciennes.
Schedule::command(RecalculateQualityCommand::class)
    ->hourly()
    ->timezone('Africa/Douala')
    ->withoutOverlapping()
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/oracle-quality.log'));

// Scores et statuts live : un appel par minute, côté serveur.
Schedule::command(SyncLiveScoresCommand::class)
    ->everyMinute()
    ->timezone('Africa/Douala')
    // Verrou court : un conteneur tué ne bloque pas le live pendant 24 h.
    ->withoutOverlapping(5)
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/oracle-live.log'));

// API-Football : une requête live globale toutes les 30 minutes pour rester
// sous le quota gratuit de 100 requêtes/jour.
Schedule::command(SyncApiFootballCommand::class, ['--live' => true])
    ->everyThirtyMinutes()
    ->timezone('Africa/Douala')
    ->withoutOverlapping(20)
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/oracle-api-football-live.log'));

// Fixtures complémentaires (CAN, compétitions internationales, championnats
// non couverts par football-data.org) : synchronisation quotidienne séparée
// du flux live.
Schedule::command(SyncApiFootballCommand::class)
    ->dailyAt('06:30')
    ->timezone('Africa/Douala')
    ->withoutOverlapping()
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/oracle-api-football.log'));

Schedule::command(SettlePredictionsCommand::class)
    ->everyTenMinutes()
    ->timezone('Africa/Douala')
    ->withoutOverlapping(15)
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/oracle-settlement.log'));

Schedule::command(MonitorOracleCommand::class)
    ->everyTenMinutes()
    ->timezone('Africa/Douala')
    ->withoutOverlapping()
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/oracle-monitor.log'));

// Entraîne chaque composante Python (Dixon-Coles, Elo et spécialistes) sur l'historique actualisé.
Schedule::command(TrainPredictionModelCommand::class)
    ->dailyAt('23:55')
    ->timezone('Africa/Douala')
    ->withoutOverlapping()
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/oracle-training.log'));

Schedule::command(EvaluatePredictionPerformanceCommand::class)
    ->dailyAt('00:10')
    ->timezone('Africa/Douala')
    ->withoutOverlapping()
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/oracle-performance.log'));

Schedule::command(GeneratePredictionsCommand::class)
    ->everyFifteenMinutes()
    ->timezone('Africa/Douala')
    ->withoutOverlapping()
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/oracle-predictions.log'));

Schedule::command(SyncMatchesCommand::class)
    ->dailyAt('12:00')
    ->timezone('Africa/Douala')
    ->withoutOverlapping()
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/oracle-sync.log'));

Schedule::command(SyncMatchesCommand::class)
    ->dailyAt('18:00')
    ->timezone('Africa/Douala')
    ->withoutOverlapping()
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/oracle-sync.log'));

// Génération quotidienne des combinés (après la sync du matin)
Schedule::command(GenerateParlaysCommand::class)
    ->dailyAt('07:00')
    ->timezone('Africa/Douala')
    ->withoutOverlapping()
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/oracle-parlays.log'));

// ─────────────────────────────────────────────────────────────────────────────
// Windows Task Scheduler (développement local)
// Dans PowerShell Admin, exécuter :
//
//   $trigger = New-ScheduledTaskTrigger -RepetitionInterval (New-TimeSpan -Minutes 1) -Once -At (Get-Date)
//   $action  = New-ScheduledTaskAction -Execute "php" -Argument "c:\Users\Idriss Monthe\Desktop\PronoIA\artisan schedule:run" -WorkingDirectory "c:\Users\Idriss Monthe\Desktop\PronoIA"
//   Register-ScheduledTask -Action $action -Trigger $trigger -TaskName "OracleFootScheduler" -RunLevel Highest
//
// Pour production Linux/Mac, ajouter dans crontab :
//   * * * * * cd /path/to/project && php artisan schedule:run >> /dev/null 2>&1
// ─────────────────────────────────────────────────────────────────────────────
