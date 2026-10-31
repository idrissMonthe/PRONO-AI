<?php

namespace Database\Seeders;

use App\Models\AppSetting;
use App\Models\AuditEvent;
use App\Models\Bookmaker;
use App\Models\Market;
use App\Models\ModelVersion;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Initial App Settings (CDC 16 & 29.5)
        $defaultSettings = [
            'display_odds_format' => ['value' => 'decimal', 'desc' => 'Format d\'affichage des cotes (decimal, fractional, american)'],
            'active_model_family' => ['value' => 'pure', 'desc' => 'Famille de modèle active (pure ou hybrid)'],
            'time_decay_half_life_days' => ['value' => 365, 'desc' => 'Demi-vie de la pondération temporelle exponentielle (en jours)'],
            'credibility_interval_levels' => ['value' => [80, 95], 'desc' => 'Niveaux d\'intervalles de crédibilité'],
            'freeze_time_minutes_before_kickoff' => ['value' => 60, 'desc' => 'Instant de gel avant coup d\'envoi en minutes'],
            'data_quality_min_score' => ['value' => 60, 'desc' => 'Score de qualité minimal pour autoriser un calcul non dégradé'],
            'odds_freshness_max_hours' => ['value' => 6, 'desc' => 'Fraîcheur maximale acceptée des cotes'],
            'min_edge_alert_pct' => ['value' => 3.0, 'desc' => 'Écart minimal en points de pourcentage pour alerte modèle/marché'],
            'devig_method' => ['value' => 'shin', 'desc' => 'Méthode par défaut de retrait de la marge (shin, power, proportional)'],
            'kelly_simulator_enabled' => ['value' => false, 'desc' => 'Simulateur pédagogique de critère de Kelly (désactivé par défaut)'],
            'analysis_only_mode' => ['value' => false, 'desc' => 'Mode analyse seule masquant cotes et paris virtuels (CDC 28.4)'],
            'llm_provider' => ['value' => 'gemini', 'desc' => 'Fournisseur d\'explications IA (gemini ou deterministic_template)'],
        ];

        foreach ($defaultSettings as $key => $data) {
            AppSetting::set($key, $data['value'], $data['desc']);
        }

        // 2. Reference Bookmakers (CDC 25.9)
        $bookmakers = [
            ['name' => 'Pinnacle', 'code' => 'pinnacle', 'type' => 'low_margin'],
            ['name' => 'Bet365', 'code' => 'bet365', 'type' => 'high_margin'],
            ['name' => 'Bet-at-home', 'code' => 'betathome', 'type' => 'high_margin'],
            ['name' => 'Betfair Exchange', 'code' => 'betfair_ex', 'type' => 'exchange'],
            ['name' => 'Consensus Moyen Marché', 'code' => 'market_avg', 'type' => 'high_margin'],
            ['name' => 'Cote Maximale Marché', 'code' => 'market_max', 'type' => 'low_margin'],
        ];

        foreach ($bookmakers as $bm) {
            Bookmaker::firstOrCreate(['code' => $bm['code']], $bm);
        }

        // 3. Markets (CDC 25.2)
        $markets = [
            ['code' => '1N2', 'name' => 'Résultat du Match (1N2)', 'category' => 'main'],
            ['code' => 'double_chance', 'name' => 'Double Chance (1N, N2, 12)', 'category' => 'derived'],
            ['code' => 'dnb', 'name' => 'Draw No Bet (Remboursé si nul)', 'category' => 'derived'],
            ['code' => 'totals', 'name' => 'Total de Buts (Plus / Moins)', 'category' => 'goals'],
            ['code' => 'btts', 'name' => 'Les Deux Équipes Marquent (BTTS)', 'category' => 'goals'],
            ['code' => 'exact_score', 'name' => 'Score Exact', 'category' => 'score'],
            ['code' => 'asian_handicap', 'name' => 'Handicap Asiatique', 'category' => 'handicap'],
        ];

        foreach ($markets as $m) {
            Market::firstOrCreate(['code' => $m['code']], $m);
        }

        // 4. Model Hierarchy Register (CDC 7.9)
        $models = [
            [
                'version_name' => 'M0_BASELINE_FREQ',
                'family' => 'pure',
                'algorithm' => 'Empirical League Frequencies',
                'status' => 'champion', // Plancher initial
                'decision_reason' => 'Plancher statistique absolu de référence basé sur les fréquences historiques par ligue.',
                'decided_at' => now(),
            ],
            [
                'version_name' => 'M1_ELO_HOMEADV',
                'family' => 'pure',
                'algorithm' => 'Elo Rating System with Home Advantage and Goal Margin',
                'status' => 'candidate',
                'decision_reason' => 'Référence dynamique de force d\'équipe.',
                'decided_at' => now(),
            ],
            [
                'version_name' => 'M2_POISSON_IND',
                'family' => 'pure',
                'algorithm' => 'Independent Poisson Log-Linear Model',
                'status' => 'candidate',
                'decision_reason' => 'Modèle initial de calcul des buts attendus.',
                'decided_at' => now(),
            ],
            [
                'version_name' => 'M3_DIXON_COLES',
                'family' => 'pure',
                'algorithm' => 'Dixon-Coles with Tau Low-Score Correction and Time Decay',
                'status' => 'candidate',
                'decision_reason' => 'Modèle statistique de référence recommandé.',
                'decided_at' => now(),
            ],
            [
                'version_name' => 'M7_MARKET_DEVIG',
                'family' => 'hybrid',
                'algorithm' => 'Market Closing Odds Margin Removal (Shin Method)',
                'status' => 'candidate',
                'decision_reason' => 'Estimateur de marché de référence sans marge.',
                'decided_at' => now(),
            ],
        ];

        foreach ($models as $mod) {
            ModelVersion::firstOrCreate(['version_name' => $mod['version_name']], $mod);
        }

        AuditEvent::log('SYSTEM_INITIALIZATION', [
            'status' => 'complete',
            'version' => '2.0.0',
            'database' => config('database.default'),
        ]);
    }
}
