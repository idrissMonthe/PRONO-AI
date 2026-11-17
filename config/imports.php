<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Sources de données football additionnelles
    |--------------------------------------------------------------------------
    | Chaque source est indépendante et désactivable. Aucune ne remplace
    | football-data.org ni API-Football : elles alimentent le même stockage
    | central et ne réécrivent jamais la provenance d'un match existant.
    */

    'openfootball' => [
        'enabled' => filter_var(env('OPENFOOTBALL_ENABLED', true), FILTER_VALIDATE_BOOL),
        'base_url' => env('OPENFOOTBALL_BASE_URL', 'https://raw.githubusercontent.com/openfootball/football.json/master'),
        'timeout' => (int) env('OPENFOOTBALL_TIMEOUT', 20),

        // Saisons disponibles dans le dépôt (dossiers). La saison demandée doit
        // exister : aucun dossier n'est inventé.
        'seasons' => ['2025-26'],

        /*
        | Fichier du dépôt => code de compétition interne. Les codes déjà
        | utilisés par football-data.org (PL, PD, BL1, SA, FL1) sont réutilisés
        | afin que la déduplication inter-fournisseurs fonctionne. Les autres
        | reçoivent un code préfixé pour ne jamais collisionner avec les ids
        | API-Football.
        */
        'competitions' => [
            'en.1.json' => ['code' => 'PL', 'name' => 'Premier League', 'country' => 'England'],
            'es.1.json' => ['code' => 'PD', 'name' => 'La Liga', 'country' => 'Spain'],
            'de.1.json' => ['code' => 'BL1', 'name' => 'Bundesliga', 'country' => 'Germany'],
            'it.1.json' => ['code' => 'SA', 'name' => 'Serie A', 'country' => 'Italy'],
            'fr.1.json' => ['code' => 'FL1', 'name' => 'Ligue 1', 'country' => 'France'],
            'nl.1.json' => ['code' => 'OF-NL1', 'name' => 'Eredivisie', 'country' => 'Netherlands'],
            'pt.1.json' => ['code' => 'OF-PT1', 'name' => 'Primeira Liga', 'country' => 'Portugal'],
            'be.1.json' => ['code' => 'OF-BE1', 'name' => 'Pro League', 'country' => 'Belgium'],
            'tr.1.json' => ['code' => 'OF-TR1', 'name' => 'Süper Lig', 'country' => 'Turkey'],
            'gr.1.json' => ['code' => 'OF-GR1', 'name' => 'Super League', 'country' => 'Greece'],
        ],
    ],

    'openfootball_world' => [
        'enabled' => filter_var(env('OPENFOOTBALL_WORLD_ENABLED', true), FILTER_VALIDATE_BOOL),
        'base_url' => env('OPENFOOTBALL_WORLD_BASE_URL', 'https://raw.githubusercontent.com/openfootball/world/master'),
        'timeout' => (int) env('OPENFOOTBALL_WORLD_TIMEOUT', 20),

        /*
        | Fichiers Football.TXT vérifiés dans le dépôt. Ajouter une entrée
        | revient à ajouter une compétition ; aucun chemin n'est deviné.
        */
        'leagues' => [
            'senegal-2025-26' => [
                'path' => 'africa/senegal/2025-26_sn1.txt',
                'code' => 'SN1',
                'name' => 'Ligue 1 Sénégal',
                'country' => 'Senegal',
                'season' => '2025-2026',
            ],
            'senegal-2024-25' => [
                'path' => 'africa/senegal/2024-25_sn1.txt',
                'code' => 'SN1',
                'name' => 'Ligue 1 Sénégal',
                'country' => 'Senegal',
                'season' => '2024-2025',
            ],
        ],
    ],

    'statsbomb' => [
        'enabled' => filter_var(env('STATSBOMB_ENABLED', true), FILTER_VALIDATE_BOOL),
        'base_url' => env('STATSBOMB_BASE_URL', 'https://raw.githubusercontent.com/statsbomb/open-data/master/data'),
        'timeout' => (int) env('STATSBOMB_TIMEOUT', 60),
        // Un fichier d'événements pèse plusieurs mégaoctets : une coupure
        // réseau ne doit pas annuler tout l'import.
        'retry_attempts' => (int) env('STATSBOMB_RETRY_ATTEMPTS', 3),
        'retry_pause_ms' => (int) env('STATSBOMB_RETRY_PAUSE_MS', 1500),
        'import_stats' => filter_var(env('STATSBOMB_IMPORT_STATS', true), FILTER_VALIDATE_BOOL),
        'stats_limit' => (int) env('STATSBOMB_STATS_LIMIT', 25),

        /*
        | Couples compétition/saison réellement présents dans competitions.json.
        | Aucune saison n'est supposée disponible : un couple absent renvoie une
        | erreur explicite au lieu d'un import vide silencieux.
        */
        'seasons' => [
            ['competition_id' => 2, 'season_id' => 27, 'code' => 'PL', 'name' => 'Premier League', 'country' => 'England', 'season' => '2015-2016'],
        ],
    ],
];
