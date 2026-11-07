<?php

namespace App\Providers;

use App\Services\AIExplanation\GeminiExplanationService;
use App\Services\FootballData\FootballDataService;
use App\Services\Integrity\HashChainService;
use App\Services\Odds\OddsService;
use App\Services\Parlay\ParlayService;
use App\Services\Prediction\PredictionClient;
use App\Services\Prediction\PredictionRevisionService;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * Bind all core Oracle Foot services as singletons so their constructors
     * are only called once per request and config() values are resolved once.
     */
    public function register(): void
    {
        // Statistical engine client (FastAPI bridge)
        $this->app->singleton(PredictionClient::class, function ($app) {
            return new PredictionClient();
        });

        // Odds devigging + EV calculation (depends on PredictionClient)
        $this->app->singleton(OddsService::class, function ($app) {
            return new OddsService($app->make(PredictionClient::class));
        });

        // Google Gemini AI explanation service
        $this->app->singleton(GeminiExplanationService::class, function ($app) {
            return new GeminiExplanationService();
        });

        // Combinés & parlays probabilistes
        $this->app->singleton(ParlayService::class, function ($app) {
            return new ParlayService(
                $app->make(GeminiExplanationService::class),
                $app->make(PredictionRevisionService::class)
            );
        });

        // SHA-256 hash-chain integrity service
        $this->app->singleton(HashChainService::class, function ($app) {
            return new HashChainService();
        });

        // Football data ingestion & entity resolution
        $this->app->singleton(FootballDataService::class, function ($app) {
            return new FootballDataService();
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
