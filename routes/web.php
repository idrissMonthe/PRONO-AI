<?php

use App\Http\Controllers\ComparatorController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DataManagementController;
use App\Http\Controllers\GeminiController;
use App\Http\Controllers\LaboratoryController;
use App\Http\Controllers\MatchController;
use App\Http\Controllers\OddsMarketController;
use App\Http\Controllers\SettingsController;
use Illuminate\Support\Facades\Route;

// 1. Dashboard (Vue d'ensemble)
Route::get('/', [DashboardController::class, 'index'])->name('dashboard');

// 2. Matches & Exploration
Route::prefix('matches')->name('matches.')->group(function () {
    Route::get('/', [MatchController::class, 'index'])->name('index');
    Route::get('/{id}', [MatchController::class, 'show'])->name('show');
    Route::post('/{id}/ask', [MatchController::class, 'askAssistant'])->name('ask');
    Route::post('/{id}/whatif', [MatchController::class, 'simulateWhatIf'])->name('whatif');
});

// 3. Team Comparator
Route::prefix('comparator')->name('comparator.')->group(function () {
    Route::get('/', [ComparatorController::class, 'index'])->name('index');
});

// 4. Odds & Markets (v2.0)
Route::prefix('odds')->name('odds.')->group(function () {
    Route::get('/', [OddsMarketController::class, 'index'])->name('index');
    Route::post('/paper-bet', [OddsMarketController::class, 'recordPaperBet'])->name('paper-bet');
});

// 5. Evaluation Laboratory & Model Registry
Route::prefix('laboratory')->name('laboratory.')->group(function () {
    Route::get('/', [LaboratoryController::class, 'index'])->name('index');
    Route::post('/run-backtest', [LaboratoryController::class, 'runBacktest'])->name('run-backtest');
    Route::post('/promote', [LaboratoryController::class, 'promoteModel'])->name('promote');
});

// 6. Data Management & Entity Resolution
Route::prefix('data')->name('data.')->group(function () {
    Route::get('/', [DataManagementController::class, 'index'])->name('index');
    Route::post('/add-alias', [DataManagementController::class, 'addAlias'])->name('add-alias');
    Route::post('/sync-api', [DataManagementController::class, 'syncApi'])->name('sync-api');
});

// 7. Settings & Diagnostic Center
Route::prefix('settings')->name('settings.')->group(function () {
    Route::get('/', [SettingsController::class, 'index'])->name('index');
    Route::post('/update', [SettingsController::class, 'update'])->name('update');
    Route::post('/verify-ledger', [SettingsController::class, 'verifyLedger'])->name('verify-ledger');
});

// 8. Assistant IA Gemini (Appels Web & Diagnostics)
Route::prefix('gemini')->name('gemini.')->group(function () {
    Route::get('/status', [GeminiController::class, 'status'])->name('status');
    Route::post('/test', [GeminiController::class, 'testConnection'])->name('test');
    Route::post('/explain', [GeminiController::class, 'explain'])->name('explain');
    Route::post('/ask', [GeminiController::class, 'ask'])->name('ask');
    Route::get('/history', [GeminiController::class, 'history'])->name('history');
});
