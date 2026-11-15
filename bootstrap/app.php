<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Derrière le proxy NGINX/Passenger de DOM Cloud (et tout reverse proxy
        // local), les en-têtes X-Forwarded-* portent le vrai schéma et l'IP.
        $middleware->trustProxies(at: '*');
        $middleware->alias([
            'api.token' => \App\Http\Middleware\EnsureApiToken::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
