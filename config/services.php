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

    'football_data' => [
        'api_key' => env('FOOTBALL_DATA_API_KEY'),
        'base_url' => env('FOOTBALL_DATA_BASE_URL', 'https://api.football-data.org/v4'),
        'competitions' => array_values(array_filter(array_map(
            'trim',
            explode(',', env('FOOTBALL_DATA_COMPETITIONS', 'PL,PD,BL1,SA,FL1'))
        ))),
    ],

    'the_odds_api' => [
        'api_key' => env('THE_ODDS_API_KEY'),
        'base_url' => 'https://api.the-odds-api.com/v4',
    ],

    'ai_service' => [
        'url' => env('AI_SERVICE_URL', 'http://127.0.0.1:8001'),
        'secret' => env('AI_SERVICE_SECRET'),
    ],

];
