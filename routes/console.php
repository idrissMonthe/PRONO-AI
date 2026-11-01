<?php

use App\Console\Commands\GenerateParlaysCommand;
use App\Console\Commands\SyncMatchesCommand;
use App\Console\Commands\TrainPredictionModelCommand;
use App\Console\Commands\GeneratePredictionsCommand;
use App\Console\Commands\SyncOddsCommand;
use App\Console\Commands\SyncLiveScoresCommand;
use App\Console\Commands\SettlePredictionsCommand;
use App\Console\Commands\UpdateOddsMovementCommand;
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

// Scores et statuts live : un appel par minute, côté serveur.
Schedule::command(SyncLiveScoresCommand::class)
    ->everyMinute()
    ->timezone('Africa/Douala')
    ->withoutOverlapping()
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/oracle-live.log'));

Schedule::command(SettlePredictionsCommand::class)
    ->everyTenMinutes()
    ->timezone('Africa/Douala')
    ->withoutOverlapping()
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/oracle-settlement.log'));

// Entraîne chaque composante Python (Dixon-Coles, Elo et spécialistes) sur l'historique actualisé.
Schedule::command(TrainPredictionModelCommand::class)
    ->dailyAt('23:55')
    ->timezone('Africa/Douala')
    ->withoutOverlapping()
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/oracle-training.log'));

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
