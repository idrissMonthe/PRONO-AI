<?php

use App\Console\Commands\GenerateParlaysCommand;
use App\Console\Commands\SyncMatchesCommand;
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
