<?php

namespace App\Services\FootballData;

use App\Models\Bookmaker;
use App\Models\DataQuarantine;
use App\Models\FootballMatch;
use App\Models\League;
use App\Models\Market;
use App\Models\OddsSnapshot;
use App\Models\Season;
use App\Models\Team;
use App\Models\TeamAlias;
use Carbon\Carbon;
use Exception;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

class FootballDataService
{
    public function syncFromFootballDataOrg(): array
    {
        $apiKey = config('services.football_data.api_key');
        $competitions = config('services.football_data.competitions', []);

        if (empty($apiKey)) {
            throw new RuntimeException('La clé football-data.org n’est pas configurée.');
        }

        if (empty($competitions)) {
            throw new RuntimeException('Aucune compétition football-data.org n’est configurée.');
        }

        $dateFrom = now()->subDays(3)->toDateString();
        $dateTo = now()->addDays(7)->toDateString();

        try {
            $response = Http::baseUrl(rtrim(config('services.football_data.base_url'), '/'))
                ->timeout(15)
                ->withHeaders(['X-Auth-Token' => $apiKey])
                ->get('/matches', [
                    'competitions' => implode(',', $competitions),
                    'dateFrom' => $dateFrom,
                    'dateTo' => $dateTo,
                ]);
        } catch (ConnectionException $exception) {
            Log::warning('football-data.org connection failed.');
            throw new RuntimeException('Impossible de joindre football-data.org.');
        }

        if (! $response->successful()) {
            Log::warning('football-data.org rejected match synchronization.', [
                'status' => $response->status(),
            ]);
            throw new RuntimeException('football-data.org a refusé la synchronisation (HTTP '.$response->status().').');
        }

        $providerMatches = $response->json('matches');
        if (! is_array($providerMatches)) {
            throw new RuntimeException('La réponse football-data.org ne contient pas de liste de matchs valide.');
        }

        $imported = 0;
        $rejected = 0;

        foreach ($providerMatches as $providerMatch) {
            $competition = $providerMatch['competition'] ?? [];
            $season = $providerMatch['season'] ?? [];
            $seasonStart = isset($season['startDate']) ? Carbon::parse($season['startDate'])->year : now()->year;
            $seasonEnd = isset($season['endDate']) ? Carbon::parse($season['endDate'])->year : $seasonStart + 1;

            $record = [
                'home_team' => $providerMatch['homeTeam']['name'] ?? null,
                'away_team' => $providerMatch['awayTeam']['name'] ?? null,
                'date' => $providerMatch['utcDate'] ?? null,
                'league_code' => $competition['code'] ?? (string) ($competition['id'] ?? 'FD'),
                'league_name' => $competition['name'] ?? 'Compétition football-data.org',
                'country' => $competition['area']['name'] ?? 'Europe',
                'season' => $seasonStart.'-'.$seasonEnd,
                'status' => $providerMatch['status'] ?? 'SCHEDULED',
                'home_score' => $providerMatch['score']['fullTime']['home'] ?? null,
                'away_score' => $providerMatch['score']['fullTime']['away'] ?? null,
                'ht_home_score' => $providerMatch['score']['halfTime']['home'] ?? null,
                'ht_away_score' => $providerMatch['score']['halfTime']['away'] ?? null,
                'round' => isset($providerMatch['matchday']) ? (string) $providerMatch['matchday'] : null,
            ];

            if ($this->ingestMatchRecord($record, 'football-data.org')) {
                $imported++;
            } else {
                $rejected++;
            }
        }

        return [
            'provider' => 'football-data.org',
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'competitions' => $competitions,
            'received' => count($providerMatches),
            'imported' => $imported,
            'rejected' => $rejected,
        ];
    }

    /**
     * Resolves a team name to an internal Team model using exact match, normalized alias, or creates alias.
     */
    public function resolveTeam(string $rawName, ?League $league = null): Team
    {
        $normalized = trim($rawName);

        // 1. Direct team name match
        $team = Team::where('name', $normalized)->first();
        if ($team) {
            return $team;
        }

        // 2. Alias match
        $alias = TeamAlias::where('alias', $normalized)->with('team')->first();
        if ($alias && $alias->team) {
            return $alias->team;
        }

        // 3. Fallback: create team and register alias
        $team = Team::create([
            'league_id' => $league?->id,
            'name' => $normalized,
            'short_name' => Str::substr($normalized, 0, 15),
            'country' => $league?->country ?? 'Europe',
        ]);

        TeamAlias::create([
            'team_id' => $team->id,
            'alias' => $normalized,
            'source' => 'auto_resolver',
            'confidence' => 100.0,
            'is_validated' => true,
        ]);

        return $team;
    }

    /**
     * Calculates data quality score (0 to 100) per CDC Section 13.5.
     */
    public function calculateQualityScore(array $matchData, array $oddsData = []): array
    {
        $score = 100;
        $missing = [];

        if (empty($matchData['home_team']) || empty($matchData['away_team'])) {
            $score -= 40;
            $missing[] = 'teams_unidentified';
        }

        if (empty($matchData['match_date_utc']) && empty($matchData['date'])) {
            $score -= 30;
            $missing[] = 'date_missing';
        }

        if (empty($oddsData) || count($oddsData) < 3) {
            $score -= 15;
            $missing[] = 'market_odds_partial';
        } else {
            // Check overround
            $invSum = 0;
            foreach ($oddsData as $o) {
                if ($o <= 1.0) {
                    $score -= 25;
                    $missing[] = 'invalid_odds_below_one';
                    break;
                }
                $invSum += (1.0 / $o);
            }
            if ($invSum < 1.01 || $invSum > 1.25) {
                $score -= 10;
                $missing[] = 'overround_outside_normal_range';
            }
        }

        $hasPartialOdds = in_array('market_odds_partial', $missing, true);
        $tier = $score >= 80 && !$hasPartialOdds ? 'usable' : ($score >= 50 ? 'degraded' : 'insufficient');

        return [
            'score' => max(0, $score),
            'tier' => $tier,
            'missing' => $missing,
        ];
    }

    /**
     * Ingests a match record safely with validation and quarantine (CDC 8.12).
     */
    public function ingestMatchRecord(array $rawRecord, string $source = 'csv_import'): ?FootballMatch
    {
        try {
            // Validation
            if (empty($rawRecord['home_team']) || empty($rawRecord['away_team']) || empty($rawRecord['date'])) {
                DataQuarantine::create([
                    'source' => $source,
                    'entity_type' => 'match',
                    'raw_hash' => hash('sha256', json_encode($rawRecord)),
                    'rejection_reason' => 'Missing essential fields (home_team, away_team or date)',
                    'raw_payload' => $rawRecord,
                    'quarantined_at_utc' => now()->utc(),
                ]);

                return null;
            }

            $league = League::firstOrCreate(
                ['code' => $rawRecord['league_code'] ?? 'E0'],
                [
                    'name' => $rawRecord['league_name'] ?? 'Premier League',
                    'country' => $rawRecord['country'] ?? 'England',
                    'tier' => '1',
                ]
            );

            $season = Season::firstOrCreate(
                ['league_id' => $league->id, 'name' => $rawRecord['season'] ?? '2025-2026'],
                [
                    'year_start' => 2025,
                    'year_end' => 2026,
                    'is_current' => true,
                ]
            );

            $homeTeam = $this->resolveTeam($rawRecord['home_team'], $league);
            $awayTeam = $this->resolveTeam($rawRecord['away_team'], $league);

            $matchDate = Carbon::parse($rawRecord['date'])->utc();

            $quality = $this->calculateQualityScore($rawRecord, $rawRecord['odds'] ?? []);

            $match = FootballMatch::updateOrCreate(
                [
                    'league_id' => $league->id,
                    'home_team_id' => $homeTeam->id,
                    'away_team_id' => $awayTeam->id,
                    'match_date_utc' => $matchDate,
                ],
                [
                    'season_id' => $season->id,
                    'status' => $rawRecord['status'] ?? (isset($rawRecord['home_score']) ? 'FINISHED' : 'SCHEDULED'),
                    'home_score' => $rawRecord['home_score'] ?? null,
                    'away_score' => $rawRecord['away_score'] ?? null,
                    'ht_home_score' => $rawRecord['ht_home_score'] ?? null,
                    'ht_away_score' => $rawRecord['ht_away_score'] ?? null,
                    'is_neutral_ground' => $rawRecord['is_neutral_ground'] ?? false,
                    'round' => $rawRecord['round'] ?? null,
                    'quality_score' => $quality['score'],
                    'quality_tier' => $quality['tier'],
                ]
            );

            // Ingest odds if present
            if (! empty($rawRecord['odds']) && count($rawRecord['odds']) === 3) {
                $pinnacle = Bookmaker::where('code', 'pinnacle')->first() ?? Bookmaker::first();
                $market1N2 = Market::where('code', '1N2')->first();

                if ($pinnacle && $market1N2) {
                    $selections = [
                        ['sel' => 'HOME', 'odds' => $rawRecord['odds'][0]],
                        ['sel' => 'DRAW', 'odds' => $rawRecord['odds'][1]],
                        ['sel' => 'AWAY', 'odds' => $rawRecord['odds'][2]],
                    ];

                    foreach ($selections as $s) {
                        OddsSnapshot::create([
                            'match_id' => $match->id,
                            'bookmaker_id' => $pinnacle->id,
                            'market_id' => $market1N2->id,
                            'selection' => $s['sel'],
                            'decimal_odds' => $s['odds'],
                            'snapshot_time_utc' => $matchDate->copy()->subHours(2),
                            'is_closing' => true,
                            'source' => $source,
                        ]);
                    }
                }
            }

            return $match;
        } catch (Exception $e) {
            Log::error('Match record ingestion exception: '.$e->getMessage());

            try {
                DataQuarantine::create([
                    'source' => $source,
                    'entity_type' => 'match',
                    'raw_hash' => hash('sha256', json_encode($rawRecord)),
                    'rejection_reason' => 'import_persistence_error:' . $e::class,
                    'raw_payload' => $rawRecord,
                    'quarantined_at_utc' => now()->utc(),
                ]);
            } catch (Exception $quarantineException) {
                Log::error('Could not quarantine rejected match record.', [
                    'exception' => $quarantineException::class,
                ]);
            }

            return null;
        }
    }
}
