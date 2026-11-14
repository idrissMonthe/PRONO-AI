<?php

use App\Http\Controllers\FootballDataApiController;
use App\Http\Controllers\GeminiController;
use App\Http\Controllers\IntegrityApiController;
use App\Http\Controllers\MatchController;
use App\Http\Controllers\OddsApiController;
use App\Http\Controllers\PredictionApiController;
use App\Http\Middleware\EnsureApiToken;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes for ORACLE FOOT (Monoposte Personnel)
|--------------------------------------------------------------------------
|
| Les lectures restent libres (scores en direct, santé, historique).
| Toutes les écritures exigent l'en-tête X-API-Token.
|
*/

// Lectures publiques
Route::prefix('gemini')->name('api.gemini.')->group(function () {
    Route::get('/status', [GeminiController::class, 'status'])->name('status');
    Route::get('/history', [GeminiController::class, 'history'])->name('history');
});

Route::get('/predictions/health', [PredictionApiController::class, 'health'])->name('api.predictions.health');
Route::get('/integrity/ledger', [IntegrityApiController::class, 'verifyLedger'])->name('api.integrity.ledger');
Route::get('/matches/live', [MatchController::class, 'liveScores'])->name('api.matches.live');

// Écritures protégées par jeton
Route::middleware([EnsureApiToken::class, 'throttle:writes'])->group(function () {
    Route::prefix('gemini')->name('api.gemini.')->group(function () {
        Route::post('/test', [GeminiController::class, 'testConnection'])->name('test');
        Route::post('/explain', [GeminiController::class, 'explain'])->name('explain');
        Route::post('/ask', [GeminiController::class, 'ask'])->name('ask');
    });

    Route::prefix('predictions')->name('api.predictions.')->group(function () {
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
});
