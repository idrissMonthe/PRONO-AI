<?php

use App\Http\Controllers\FootballDataApiController;
use App\Http\Controllers\GeminiController;
use App\Http\Controllers\IntegrityApiController;
use App\Http\Controllers\OddsApiController;
use App\Http\Controllers\PredictionApiController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes for ORACLE FOOT (Monoposte Personnel)
|--------------------------------------------------------------------------
|
| Endpoints REST pour le service d'explications et d'assistance IA Gemini.
|
*/

Route::prefix('gemini')->name('api.gemini.')->group(function () {
    Route::get('/status', [GeminiController::class, 'status'])->name('status');
    Route::post('/test', [GeminiController::class, 'testConnection'])->name('test');
    Route::post('/explain', [GeminiController::class, 'explain'])->name('explain');
    Route::post('/ask', [GeminiController::class, 'ask'])->name('ask');
    Route::get('/history', [GeminiController::class, 'history'])->name('history');
});

Route::prefix('predictions')->name('api.predictions.')->group(function () {
    Route::get('/health', [PredictionApiController::class, 'health'])->name('health');
    Route::post('/', [PredictionApiController::class, 'predict'])->name('predict');
    Route::post('/evaluate', [PredictionApiController::class, 'evaluate'])->name('evaluate');
});

Route::prefix('odds')->name('api.odds.')->group(function () {
    Route::post('/devig', [OddsApiController::class, 'devig'])->name('devig');
    Route::post('/value', [OddsApiController::class, 'evaluateValue'])->name('value');
    Route::post('/clv', [OddsApiController::class, 'calculateClv'])->name('clv');
    Route::post('/kelly', [OddsApiController::class, 'calculateKellyStake'])->name('kelly');
});

Route::prefix('football-data')->name('api.football-data.')->group(function () {
    Route::post('/quality', [FootballDataApiController::class, 'quality'])->name('quality');
    Route::post('/ingest', [FootballDataApiController::class, 'ingest'])->name('ingest');
});

Route::get('/integrity/ledger', [IntegrityApiController::class, 'verifyLedger'])
    ->name('api.integrity.ledger');
