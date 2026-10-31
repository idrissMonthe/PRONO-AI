<?php

namespace Database\Seeders;

use App\Models\Bookmaker;
use App\Models\FootballMatch;
use App\Models\League;
use App\Models\Market;
use App\Models\ModelVersion;
use App\Models\OddsSnapshot;
use App\Models\PaperBet;
use App\Models\Prediction;
use App\Models\Season;
use App\Models\Team;
use App\Models\TeamAlias;
use App\Models\TeamMatchStat;
use App\Models\TeamRating;
use App\Services\Integrity\HashChainService;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

class SampleMatchesSeeder extends Seeder
{
    public function run(): void
    {
        $hashService = new HashChainService();

        // 1. Create European Leagues
        $leaguesData = [
            ['name' => 'Premier League', 'code' => 'PL', 'country' => 'Angleterre'],
            ['name' => 'La Liga', 'code' => 'PD', 'country' => 'Espagne'],
            ['name' => 'Ligue 1', 'code' => 'FL1', 'country' => 'France'],
            ['name' => 'Serie A', 'code' => 'SA', 'country' => 'Italie'],
            ['name' => 'Bundesliga', 'code' => 'BL1', 'country' => 'Allemagne'],
        ];

        $leagues = [];
        foreach ($leaguesData as $ld) {
            $l = League::firstOrCreate(['code' => $ld['code']], [
                'name' => $ld['name'],
                'country' => $ld['country'],
                'tier' => '1',
                'is_active' => true,
            ]);
            $leagues[$ld['code']] = $l;

            Season::firstOrCreate(['league_id' => $l->id, 'name' => '2025-2026'], [
                'year_start' => 2025,
                'year_end' => 2026,
                'is_current' => true,
            ]);
        }

        // 2. Create Teams
        $teamsData = [
            'PL' => [
                ['name' => 'Manchester City', 'code' => 'MCI', 'elo' => 1950, 'att' => 1.45, 'def' => 0.85],
                ['name' => 'Arsenal FC', 'code' => 'ARS', 'elo' => 1910, 'att' => 1.38, 'def' => 0.82],
                ['name' => 'Liverpool FC', 'code' => 'LIV', 'elo' => 1920, 'att' => 1.42, 'def' => 0.88],
                ['name' => 'Chelsea FC', 'code' => 'CHE', 'elo' => 1780, 'att' => 1.15, 'def' => 1.05],
                ['name' => 'Aston Villa', 'code' => 'AVL', 'elo' => 1760, 'att' => 1.12, 'def' => 1.08],
                ['name' => 'Tottenham Hotspur', 'code' => 'TOT', 'elo' => 1770, 'att' => 1.20, 'def' => 1.12],
            ],
            'PD' => [
                ['name' => 'Real Madrid', 'code' => 'RMA', 'elo' => 1960, 'att' => 1.48, 'def' => 0.80],
                ['name' => 'FC Barcelone', 'code' => 'FCB', 'elo' => 1930, 'att' => 1.44, 'def' => 0.86],
                ['name' => 'Atlético de Madrid', 'code' => 'ATM', 'elo' => 1840, 'att' => 1.18, 'def' => 0.82],
            ],
            'FL1' => [
                ['name' => 'Paris Saint-Germain', 'code' => 'PSG', 'elo' => 1890, 'att' => 1.50, 'def' => 0.90],
                ['name' => 'Olympique de Marseille', 'code' => 'OM', 'elo' => 1740, 'att' => 1.10, 'def' => 1.05],
                ['name' => 'AS Monaco', 'code' => 'ASM', 'elo' => 1750, 'att' => 1.15, 'def' => 1.02],
            ],
        ];

        $teamModels = [];
        foreach ($teamsData as $lCode => $tList) {
            $league = $leagues[$lCode];
            foreach ($tList as $t) {
                $team = Team::firstOrCreate(['name' => $t['name']], [
                    'league_id' => $league->id,
                    'short_name' => $t['code'],
                    'code' => $t['code'],
                    'country' => $league->country,
                ]);
                $teamModels[$t['name']] = $team;

                TeamAlias::firstOrCreate(['alias' => $t['name'], 'source' => 'official'], [
                    'team_id' => $team->id,
                    'confidence' => 100.0,
                    'is_validated' => true,
                ]);

                TeamRating::firstOrCreate(['team_id' => $team->id, 'as_of_date' => now()->toDateString()], [
                    'elo_rating' => $t['elo'],
                    'attack_strength' => $t['att'],
                    'defense_strength' => $t['def'],
                ]);
            }
        }

        $pinnacle = Bookmaker::where('code', 'pinnacle')->first();
        $b365 = Bookmaker::where('code', 'bet365')->first();
        $market1N2 = Market::where('code', '1N2')->first();
        $marketOver = Market::where('code', 'totals')->first();
        $marketBTTS = Market::where('code', 'btts')->first();
        $m3Model = ModelVersion::where('version_name', 'M3_DIXON_COLES')->first();

        // 3. Create Matches (Past Finished & Upcoming Scheduled)
        $matchesData = [
            // Finished matches
            [
                'league' => 'PL',
                'home' => 'Arsenal FC',
                'away' => 'Chelsea FC',
                'date' => Carbon::now()->subDays(3)->setTime(16, 30),
                'status' => 'FINISHED',
                'home_score' => 2,
                'away_score' => 1,
                'ht_home' => 1,
                'ht_away' => 0,
                'p_home' => 0.54, 'p_draw' => 0.25, 'p_away' => 0.21,
                'lh' => 1.75, 'la' => 0.95,
                'odds' => [1.82, 3.85, 4.40],
            ],
            [
                'league' => 'PL',
                'home' => 'Manchester City',
                'away' => 'Liverpool FC',
                'date' => Carbon::now()->subDays(2)->setTime(17, 30),
                'status' => 'FINISHED',
                'home_score' => 1,
                'away_score' => 1,
                'ht_home' => 0,
                'ht_away' => 1,
                'p_home' => 0.48, 'p_draw' => 0.26, 'p_away' => 0.26,
                'lh' => 1.65, 'la' => 1.25,
                'odds' => [2.05, 3.70, 3.50],
            ],
            [
                'league' => 'PD',
                'home' => 'FC Barcelone',
                'away' => 'Atlético de Madrid',
                'date' => Carbon::now()->subDay()->setTime(21, 00),
                'status' => 'FINISHED',
                'home_score' => 3,
                'away_score' => 1,
                'ht_home' => 2,
                'ht_away' => 1,
                'p_home' => 0.58, 'p_draw' => 0.23, 'p_away' => 0.19,
                'lh' => 1.95, 'la' => 0.85,
                'odds' => [1.70, 4.00, 4.80],
            ],

            // Upcoming matches (To Watch & Highlights)
            [
                'league' => 'PL',
                'home' => 'Liverpool FC',
                'away' => 'Arsenal FC',
                'date' => Carbon::now()->addHours(26)->setTime(17, 30),
                'status' => 'SCHEDULED',
                'home_score' => null,
                'away_score' => null,
                'ht_home' => null,
                'ht_away' => null,
                'p_home' => 0.46, 'p_draw' => 0.27, 'p_away' => 0.27,
                'lh' => 1.58, 'la' => 1.22,
                'odds' => [2.15, 3.55, 3.40], // Modèle voit 2.17 vs Marché 2.15
            ],
            [
                'league' => 'PD',
                'home' => 'Real Madrid',
                'away' => 'FC Barcelone',
                'date' => Carbon::now()->addDays(2)->setTime(20, 00),
                'status' => 'SCHEDULED',
                'home_score' => null,
                'away_score' => null,
                'ht_home' => null,
                'ht_away' => null,
                'p_home' => 0.51, 'p_draw' => 0.24, 'p_away' => 0.25,
                'lh' => 1.88, 'la' => 1.30,
                'odds' => [2.25, 3.75, 3.10], // Écart de valeur sur Home ! Modèle 51% (cote juste 1.96 vs marché 2.25)
            ],
            [
                'league' => 'FL1',
                'home' => 'Olympique de Marseille',
                'away' => 'Paris Saint-Germain',
                'date' => Carbon::now()->addDays(3)->setTime(20, 45),
                'status' => 'SCHEDULED',
                'home_score' => null,
                'away_score' => null,
                'ht_home' => null,
                'ht_away' => null,
                'p_home' => 0.26, 'p_draw' => 0.25, 'p_away' => 0.49,
                'lh' => 1.15, 'la' => 1.82,
                'odds' => [3.80, 3.70, 1.95],
            ],
            [
                'league' => 'PL',
                'home' => 'Aston Villa',
                'away' => 'Manchester City',
                'date' => Carbon::now()->addDays(4)->setTime(15, 00),
                'status' => 'SCHEDULED',
                'home_score' => null,
                'away_score' => null,
                'ht_home' => null,
                'ht_away' => null,
                'p_home' => 0.24, 'p_draw' => 0.26, 'p_away' => 0.50,
                'lh' => 1.05, 'la' => 1.85,
                'odds' => [4.20, 3.90, 1.80],
            ],
        ];

        foreach ($matchesData as $md) {
            $league = $leagues[$md['league']];
            $season = Season::where('league_id', $league->id)->first();
            $homeTeam = $teamModels[$md['home']];
            $awayTeam = $teamModels[$md['away']];

            $match = FootballMatch::firstOrCreate([
                'league_id' => $league->id,
                'season_id' => $season->id,
                'home_team_id' => $homeTeam->id,
                'away_team_id' => $awayTeam->id,
                'match_date_utc' => $md['date'],
            ], [
                'external_id' => 'demo_' . md5($md['home'] . $md['away'] . $md['date']->toDateString()),
                'data_source' => 'seeder_demo',
                'status' => $md['status'],
                'home_score' => $md['home_score'],
                'away_score' => $md['away_score'],
                'ht_home_score' => $md['ht_home'],
                'ht_away_score' => $md['ht_away'],
                'quality_score' => 92.5,
                'quality_tier' => 'usable',
            ]);

            // Create Odds Snapshots
            if ($pinnacle && $market1N2) {
                OddsSnapshot::create([
                    'match_id' => $match->id,
                    'bookmaker_id' => $pinnacle->id,
                    'market_id' => $market1N2->id,
                    'selection' => 'HOME',
                    'decimal_odds' => $md['odds'][0],
                    'snapshot_time_utc' => $md['date']->copy()->subHours(4),
                    'is_closing' => $md['status'] === 'FINISHED',
                    'source' => 'historical_import',
                ]);
                OddsSnapshot::create([
                    'match_id' => $match->id,
                    'bookmaker_id' => $pinnacle->id,
                    'market_id' => $market1N2->id,
                    'selection' => 'DRAW',
                    'decimal_odds' => $md['odds'][1],
                    'snapshot_time_utc' => $md['date']->copy()->subHours(4),
                    'is_closing' => $md['status'] === 'FINISHED',
                    'source' => 'historical_import',
                ]);
                OddsSnapshot::create([
                    'match_id' => $match->id,
                    'bookmaker_id' => $pinnacle->id,
                    'market_id' => $market1N2->id,
                    'selection' => 'AWAY',
                    'decimal_odds' => $md['odds'][2],
                    'snapshot_time_utc' => $md['date']->copy()->subHours(4),
                    'is_closing' => $md['status'] === 'FINISHED',
                    'source' => 'historical_import',
                ]);
            }

            // Create Official Prediction
            $pH = $md['p_home'];
            $pD = $md['p_draw'];
            $pA = $md['p_away'];

            $pred = Prediction::create([
                'match_id' => $match->id,
                'model_version_id' => $m3Model->id,
                'calibration_version' => 'calib_dixon_coles_v2',
                'is_official' => true,
                'freeze_time_utc' => $md['date']->copy()->subHour(),
                'content_hash' => '',
                'home_lambda' => $md['lh'],
                'away_lambda' => $md['la'],
                'truncated_residual_mass' => 0.00000045,
                'prob_home' => $pH,
                'prob_draw' => $pD,
                'prob_away' => $pA,
                'intervals_json' => [
                    'ci80' => [
                        'home' => [round($pH - 0.04, 3), round($pH + 0.04, 3)],
                        'draw' => [round($pD - 0.03, 3), round($pD + 0.03, 3)],
                        'away' => [round($pA - 0.04, 3), round($pA + 0.04, 3)],
                    ],
                    'ci95' => [
                        'home' => [round($pH - 0.07, 3), round($pH + 0.07, 3)],
                        'draw' => [round($pD - 0.05, 3), round($pD + 0.05, 3)],
                        'away' => [round($pA - 0.07, 3), round($pA + 0.07, 3)],
                    ],
                ],
                'derived_markets_json' => [
                    'double_chance' => ['1X' => round($pH + $pD, 3), 'X2' => round($pD + $pA, 3), '12' => round($pH + $pA, 3)],
                    'totals' => ['over_2_5' => 0.54, 'under_2_5' => 0.46],
                    'btts' => ['yes' => 0.55, 'no' => 0.45],
                ],
                'top_scores_json' => [
                    ['score' => '1-1', 'p' => 0.124],
                    ['score' => '2-1', 'p' => 0.115],
                    ['score' => '1-0', 'p' => 0.108],
                    ['score' => '2-0', 'p' => 0.089],
                    ['score' => '1-2', 'p' => 0.082],
                ],
                'quality_score' => 92.5,
                'quality_tier' => 'usable',
                'confidence_tier' => 'high',
                'is_abstained' => false,
                'execution_time_ms' => 18,
            ]);

            // Cryptographically chain prediction
            $hashService->chainPrediction($pred);

            // Record virtual bet on high value match (Real Madrid vs Barca)
            if ($md['home'] === 'Real Madrid' && $md['away'] === 'FC Barcelone') {
                PaperBet::create([
                    'prediction_id' => $pred->id,
                    'match_id' => $match->id,
                    'market' => '1N2',
                    'selection' => 'HOME',
                    'placed_odds' => 2.25,
                    'closing_odds' => 2.10,
                    'staking_rule' => 'flat_1u',
                    'stake_units' => 1.0,
                    'status' => 'PENDING',
                    'clv' => 0.0690, // ln(2.25 / 2.10)
                    'placed_at_utc' => now()->utc(),
                ]);
            }
        }
    }
}

