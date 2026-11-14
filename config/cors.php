<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS)
    |--------------------------------------------------------------------------
    |
    | Seule l'application elle-même peut appeler l'API depuis un navigateur.
    | Un joker `*` permettrait à n'importe quel site visité de déclencher des
    | synchronisations ou de consommer le crédit Gemini.
    |
    */

    'paths' => ['api/*'],

    'allowed_methods' => ['GET', 'POST'],

    'allowed_origins' => array_values(array_filter([
        rtrim((string) env('APP_URL', 'http://localhost:8000'), '/'),
    ])),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['Content-Type', 'X-API-Token', 'X-Requested-With'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];
