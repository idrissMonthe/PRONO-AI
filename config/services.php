<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'gemini' => [
        'api_key' => env('GEMINI_API_KEY'),
        'model' => env('GEMINI_MODEL', 'gemini-2.5-flash'),
    ],

    'hybrid_prediction' => [
        'mode' => env('PREDICTION_HYBRID_MODE', 'ASSISTED'),
        'allow_ai_in_parlays' => env('PREDICTION_ALLOW_AI_IN_PARLAYS', true),
    ],

    'prediction' => [
        // Phase de test : une donnée limitée dégrade la confiance mais ne
        // bloque plus la production d'une prédiction.
        'allow_limited_data' => filter_var(
            env('PREDICTION_ALLOW_LIMITED_DATA_PREDICTIONS', true),
            FILTER_VALIDATE_BOOL
        ),
    ],

    'football_data' => [
        'api_key' => env('FOOTBALL_DATA_API_KEY'),
        'base_url' => env('FOOTBALL_DATA_BASE_URL', 'https://api.football-data.org/v4'),
        'discover_international' => filter_var(env('FOOTBALL_DATA_DISCOVER_INTERNATIONAL', false), FILTER_VALIDATE_BOOL),
        'catalog_cache_minutes' => max(5, (int) env('FOOTBALL_DATA_CATALOG_CACHE_MINUTES', 1440)),
        'international_keywords' => array_values(array_filter(array_map(
            'trim',
            explode(',', env('FOOTBALL_DATA_INTERNATIONAL_KEYWORDS', 'world cup,euro,uefa nations league,copa america,africa cup of nations,african cup of nations,afcon,world cup qualifiers,euro qualifiers,africa cup qualifiers,international friendly'))
        ))),
        'competitions' => array_values(array_filter(array_map(
            'trim',
            explode(',', env('FOOTBALL_DATA_COMPETITIONS', 'PL,PD,BL1,SA,FL1,CL'))
        ))),
    ],

    'api_football' => [
        'api_key' => env('API_FOOTBALL_KEY'),
        'base_url' => env('API_FOOTBALL_BASE_URL', 'https://v3.football.api-sports.io'),
        'competitions' => array_values(array_filter(array_map(
            'intval',
            explode(',', env('API_FOOTBALL_COMPETITIONS', '6'))
        ))),
        'seasons' => array_values(array_filter(array_map(
            'intval',
            explode(',', (string) env('API_FOOTBALL_SEASONS', ''))
        ))),
        // Découverte du catalogue réellement accessible à la clé. Les
        // compétitions configurées restent toujours prioritaires.
        'discover' => filter_var(env('API_FOOTBALL_DISCOVER', true), FILTER_VALIDATE_BOOL),
        'catalog_cache_minutes' => max(5, (int) env('API_FOOTBALL_CATALOG_CACHE_MINUTES', 1440)),
        'max_competitions_per_sync' => max(1, (int) env('API_FOOTBALL_MAX_COMPETITIONS', 12)),
        'max_pages_per_sync' => max(1, (int) env('API_FOOTBALL_MAX_PAGES', 2)),
        'days_ahead' => max(1, (int) env('API_FOOTBALL_DAYS_AHEAD', 14)),
        'daily_call_limit' => max(1, (int) env('API_FOOTBALL_DAILY_CALL_LIMIT', 100)),
        'timeout' => max(5, (int) env('API_FOOTBALL_TIMEOUT', 20)),
        'countries' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('API_FOOTBALL_COUNTRIES', ''))
        ))),
        'types' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('API_FOOTBALL_TYPES', 'league,cup'))
        ))),
        'exclude_competitions' => array_values(array_filter(array_map(
            'intval',
            explode(',', (string) env('API_FOOTBALL_EXCLUDE_COMPETITIONS', ''))
        ))),
        // Compétitions déjà connues de football-data.org : elles gardent le
        // même code interne pour que les deux fournisseurs alimentent la même
        // ligne `leagues` (et donc les mêmes matchs).
        'league_map' => [
            39 => 'PL',
            140 => 'PD',
            78 => 'BL1',
            135 => 'SA',
            61 => 'FL1',
            2 => 'CL',
            3 => 'EL',
            848 => 'UECL',
            // Compétitions internationales également exposées par
            // football-data.org : le code partagé évite deux lignes `leagues`
            // (et donc deux matchs) pour une même rencontre.
            1 => 'WC',
            4 => 'EC',
            13 => 'CLI',
        ],
    ],

    'the_odds_api' => [
        'api_key' => env('THE_ODDS_API_KEY'),
        'base_url' => env('THE_ODDS_API_BASE_URL', 'https://api.the-odds-api.com/v4'),
        'regions' => env('THE_ODDS_API_REGIONS', 'eu'),
        'markets' => array_values(array_filter(array_map(
            'trim',
            explode(',', env('THE_ODDS_API_MARKETS', 'h2h,totals,spreads'))
        ))),
        'bookmakers' => array_values(array_filter(array_map(
            'trim',
            explode(',', env('THE_ODDS_API_BOOKMAKERS', 'pinnacle,bet365,unibet'))
        ))),
        'sports' => [
            'PL' => 'soccer_epl',
            'PD' => 'soccer_spain_la_liga',
            'BL1' => 'soccer_germany_bundesliga',
            'SA' => 'soccer_italy_serie_a',
            'FL1' => 'soccer_france_ligue_one',
            'CL' => 'soccer_uefa_champs_league',
        ],
    ],

    'ai_service' => [
        'url' => env('AI_SERVICE_URL', 'http://127.0.0.1:8001'),
        'secret' => env('AI_SERVICE_SECRET'),
    ],

];
