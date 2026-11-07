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
    /**
     * Synchronise les matchs depuis football-data.org.
     * Utilise external_id pour la déduplication fiable (pas de doublons par noms d'équipes).
     */
    public function syncFromFootballDataOrg(?string $dateFrom = null, ?string $dateTo = null): array
    {
        $apiKey      = config('services.football_data.api_key');
        $competitions = config('services.football_data.competitions', []);

        if (empty($apiKey)) {
            throw new RuntimeException('La clé football-data.org n\'est pas configurée.');
        }

        if (empty($competitions)) {
            throw new RuntimeException('Aucune compétition football-data.org n\'est configurée.');
        }

        // Par défaut : J-3 à J+7 par rapport à la date courante en Africa/Douala
        $now       = Carbon::now('Africa/Douala');
        $dateFrom  = $dateFrom ?? $now->copy()->subDays(3)->toDateString();
        $dateTo    = $dateTo   ?? $now->copy()->addDays(7)->toDateString();

        try {
            $response = Http::baseUrl(rtrim(config('services.football_data.base_url'), '/'))
                ->timeout(20)
                ->withHeaders(['X-Auth-Token' => $apiKey])
                ->get('/matches', [
                    'competitions' => implode(',', $competitions),
                    'dateFrom'     => $dateFrom,
                    'dateTo'       => $dateTo,
                ]);
        } catch (ConnectionException $exception) {
            Log::warning('football-data.org connection failed.', ['error' => $exception->getMessage()]);
            throw new RuntimeException('Impossible de joindre football-data.org.');
        }

        if (! $response->successful()) {
            Log::warning('football-data.org rejected match synchronization.', [
                'status' => $response->status(),
                'body'   => $response->body(),
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
            $competition  = $providerMatch['competition'] ?? [];
            $season       = $providerMatch['season'] ?? [];
            $seasonStart  = isset($season['startDate']) ? Carbon::parse($season['startDate'])->year : $now->year;
            $seasonEnd    = isset($season['endDate'])   ? Carbon::parse($season['endDate'])->year   : $seasonStart + 1;

            $record = [
                'external_id'   => (string) ($providerMatch['id'] ?? ''),
                'home_team'     => $providerMatch['homeTeam']['name'] ?? null,
                'away_team'     => $providerMatch['awayTeam']['name'] ?? null,
                'date'          => $providerMatch['utcDate'] ?? null,
                'league_code'   => $competition['code'] ?? (string) ($competition['id'] ?? 'FD'),
                'league_name'   => $competition['name'] ?? 'Compétition football-data.org',
                'country'       => $competition['area']['name'] ?? 'Europe',
                'season'        => $seasonStart.'-'.$seasonEnd,
                'status'        => $providerMatch['status'] ?? 'SCHEDULED',
                'home_score'    => $providerMatch['score']['fullTime']['home'] ?? null,
                'away_score'    => $providerMatch['score']['fullTime']['away'] ?? null,
                'ht_home_score' => $providerMatch['score']['halfTime']['home'] ?? null,
                'ht_away_score' => $providerMatch['score']['halfTime']['away'] ?? null,
                'round'         => isset($providerMatch['matchday']) ? (string) $providerMatch['matchday'] : null,
            ];

            if ($this->ingestMatchRecord($record, 'football-data.org')) {
                $imported++;
            } else {
                $rejected++;
            }
        }

        Log::info('football-data.org sync completed.', [
            'received' => count($providerMatches),
            'imported' => $imported,
            'rejected' => $rejected,
            'date_from' => $dateFrom,
            'date_to'   => $dateTo,
        ]);

        return [
            'provider'     => 'football-data.org',
            'date_from'    => $dateFrom,
            'date_to'      => $dateTo,
            'competitions' => $competitions,
            'received'     => count($providerMatches),
            'imported'     => $imported,
            'rejected'     => $rejected,
            'synced_at'    => now()->toIso8601String(),
        ];
    }

    /**
     * Resolves a team name to an internal Team model using exact match, normalized alias, or creates.
     */
    public function resolveTeam(string $rawName, ?League $league = null): Team
    {
        $normalized = trim($rawName);

        $team = Team::where('name', $normalized)->first();
        if ($team) {
            return $team;
        }

        $alias = TeamAlias::where('alias', $normalized)->with('team')->first();
        if ($alias && $alias->team) {
            return $alias->team;
        }

        $team = Team::create([
            'league_id'  => $league?->id,
            'name'       => $normalized,
            'short_name' => Str::substr($normalized, 0, 15),
            'country'    => $league?->country ?? 'Europe',
        ]);

        TeamAlias::create([
            'team_id'      => $team->id,
            'alias'        => $normalized,
            'source'       => 'auto_resolver',
            'confidence'   => 100.0,
            'is_validated' => true,
        ]);

        return $team;
    }

    /**
     * Calculates data quality score (0 to 100).
     */
    public function calculateQualityScore(array $matchData, array $oddsData = []): array
    {
        $score   = 100;
        $missing = [];

        if (empty($matchData['home_team']) || empty($matchData['away_team'])) {
            $score  -= 40;
            $missing[] = 'teams_unidentified';
        }

        if (empty($matchData['match_date_utc']) && empty($matchData['date'])) {
            $score  -= 30;
            $missing[] = 'date_missing';
        }

        if (empty($oddsData) || count($oddsData) < 3) {
            $score  -= 15;
            $missing[] = 'market_odds_partial';
        } else {
            $invSum = 0;
            foreach ($oddsData as $o) {
                if ($o <= 1.0) {
                    $score  -= 25;
                    $missing[] = 'invalid_odds_below_one';
                    break;
                }
                $invSum += (1.0 / $o);
            }
            if ($invSum < 1.01 || $invSum > 1.25) {
                $score  -= 10;
                $missing[] = 'overround_outside_normal_range';
            }
        }

        $hasPartialOdds = in_array('market_odds_partial', $missing, true);
        $tier = $score >= 80 && ! $hasPartialOdds ? 'usable' : ($score >= 50 ? 'degraded' : 'insufficient');

        return [
            'score'   => max(0, $score),
            'tier'    => $tier,
            'missing' => $missing,
        ];
    }

    /**
     * Recalcule la qualité à partir des données réellement persistées.
     * Le score ne doit pas rester figé à la valeur d'import initiale.
     */
    public function refreshQualityScore(FootballMatch $match): array
    {
        $score = 0;
        $missing = [];

        if ($match->home_team_id && $match->away_team_id) {
            $score += 12;
        } else {
            $missing[] = 'teams_unidentified';
        }

        if ($match->match_date_utc) {
            $score += 8;
        } else {
            $missing[] = 'date_missing';
        }

        if ($match->league_id) {
            $score += 5;
        } else {
            $missing[] = 'league_missing';
        }

        if ($match->external_id && $match->data_source === 'football-data.org') {
            $score += 10;
        } elseif (in_array($match->data_source, ['football-data.org', 'the-odds-api'], true)) {
            $score += 6;
        } else {
            $missing[] = 'source_unverified';
        }

        if (in_array($match->status, ['SCHEDULED', 'TIMED', 'LIVE', 'IN_PLAY', 'PAUSED', 'FINISHED', 'AWARDED'], true)) {
            $score += 3;
        } else {
            $missing[] = 'status_uncertain';
        }

        if ($match->last_synced_at) {
            $syncAgeHours = Carbon::parse($match->last_synced_at)->diffInMinutes(now()->utc()) / 60;
            $score += $syncAgeHours <= 2 ? 10 : ($syncAgeHours <= 12 ? 7 : ($syncAgeHours <= 48 ? 3 : 0));
            if ($syncAgeHours > 48) {
                $missing[] = 'fixture_data_stale';
            }
        } else {
            $missing[] = 'fixture_data_never_synced';
        }

        $marketId = Market::where('code', '1N2')->value('id');
        $oddsPoints = 0;
        $oddsFreshnessPoints = 0;
        $odds = $marketId
            ? $match->oddsSnapshots()
                ->where('market_id', $marketId)
                ->where('decimal_odds', '>', 1)
                ->whereIn('selection', ['HOME', 'DRAW', 'AWAY'])
                ->get()
            : collect();

        $latestBySelection = $odds
            ->sortByDesc('snapshot_time_utc')
            ->groupBy('selection')
            ->map(fn ($snapshots) => $snapshots->first());
        $completeMarket = $latestBySelection->count() === 3;
        if ($completeMarket) {
            $oddsPoints = 15;
            $score += $oddsPoints;
            $oldestOdds = $latestBySelection->min(fn ($odd) => $odd->snapshot_time_utc?->timestamp ?? 0);
            $oddsAgeHours = $oldestOdds ? max(0, now()->utc()->timestamp - $oldestOdds) / 3600 : INF;
            $oddsFreshnessPoints = $oddsAgeHours <= 2 ? 8 : ($oddsAgeHours <= 12 ? 5 : ($oddsAgeHours <= 48 ? 2 : 0));
            $score += $oddsFreshnessPoints;
            if ($oddsAgeHours > 48) {
                $missing[] = 'market_odds_stale';
            }

            $inverseSum = $latestBySelection->sum(fn ($odd) => 1 / max(1.01, (float) $odd->decimal_odds));
            if ($inverseSum >= 1.01 && $inverseSum <= 1.25) {
                $score += 4;
            } else {
                $missing[] = 'market_odds_incoherent';
            }

            if ($latestBySelection->contains(fn ($odd) => $odd->source === 'the-odds-api')) {
                $score += 3;
            }
        } else {
            $missing[] = 'market_odds_incomplete';
        }

        $homeHistory = FootballMatch::where('status', 'FINISHED')
            ->where('match_date_utc', '<', $match->match_date_utc)
            ->where(fn ($query) => $query->where('home_team_id', $match->home_team_id)->orWhere('away_team_id', $match->home_team_id))
            ->count();
        $awayHistory = FootballMatch::where('status', 'FINISHED')
            ->where('match_date_utc', '<', $match->match_date_utc)
            ->where(fn ($query) => $query->where('home_team_id', $match->away_team_id)->orWhere('away_team_id', $match->away_team_id))
            ->count();
        $historyCoverage = min(1.0, min($homeHistory, $awayHistory) / 8);
        $score += (int) round(15 * $historyCoverage);
        if ($historyCoverage < 1.0) {
            $missing[] = 'team_history_limited';
        }

        $availabilityCount = $match->playerAvailabilities()->count();
        if ($availabilityCount > 0) {
            $score += min(5, 2 + $availabilityCount);
        } else {
            $missing[] = 'availability_not_sourced';
        }

        $score = max(0, min(100, $score));
        $tier = $score >= 82 ? 'usable' : ($score >= 58 ? 'degraded' : 'insufficient');

        $breakdown = [
            'teams' => $match->home_team_id && $match->away_team_id ? 12 : 0,
            'date' => $match->match_date_utc ? 8 : 0,
            'competition' => $match->league_id ? 5 : 0,
            'source' => $match->external_id && $match->data_source === 'football-data.org' ? 10 : 0,
            'status' => in_array($match->status, ['SCHEDULED', 'TIMED', 'LIVE', 'IN_PLAY', 'PAUSED', 'FINISHED', 'AWARDED'], true) ? 3 : 0,
            'fixture_freshness' => $match->last_synced_at ? ($syncAgeHours <= 2 ? 10 : ($syncAgeHours <= 12 ? 7 : ($syncAgeHours <= 48 ? 3 : 0))) : 0,
            'odds_completeness' => $oddsPoints,
            'odds_freshness' => $oddsFreshnessPoints,
            'team_history' => (int) round(15 * $historyCoverage),
            'availability' => $availabilityCount > 0 ? min(5, 2 + $availabilityCount) : 0,
            'missing' => $missing,
            'total' => $score,
        ];

        $match->forceFill([
            'quality_score' => $score,
            'quality_tier' => $tier,
            'quality_breakdown' => $breakdown,
        ])->saveQuietly();

        return ['score' => $score, 'tier' => $tier, 'missing' => $missing, 'breakdown' => $breakdown];
    }

    /**
     * Ingests a match record safely with validation and quarantine.
     * Utilise external_id pour la déduplication quand disponible (prioritaire sur la clé composite).
     */
    public function ingestMatchRecord(array $rawRecord, string $source = 'csv_import'): ?FootballMatch
    {
        try {
            if (empty($rawRecord['home_team']) || empty($rawRecord['away_team']) || empty($rawRecord['date'])) {
                DataQuarantine::create([
                    'source'             => $source,
                    'entity_type'        => 'match',
                    'raw_hash'           => hash('sha256', json_encode($rawRecord)),
                    'rejection_reason'   => 'Missing essential fields (home_team, away_team or date)',
                    'raw_payload'        => $rawRecord,
                    'quarantined_at_utc' => now()->utc(),
                ]);

                return null;
            }

            $league = League::firstOrCreate(
                ['code' => $rawRecord['league_code'] ?? 'E0'],
                [
                    'name'    => $rawRecord['league_name'] ?? 'Premier League',
                    'country' => $rawRecord['country'] ?? 'England',
                    'tier'    => '1',
                ]
            );

            $season = Season::firstOrCreate(
                ['league_id' => $league->id, 'name' => $rawRecord['season'] ?? '2025-2026'],
                [
                    'year_start' => 2025,
                    'year_end'   => 2026,
                    'is_current' => true,
                ]
            );

            $homeTeam   = $this->resolveTeam($rawRecord['home_team'], $league);
            $awayTeam   = $this->resolveTeam($rawRecord['away_team'], $league);
            $matchDate  = Carbon::parse($rawRecord['date'])->utc();
            $quality    = $this->calculateQualityScore($rawRecord, $rawRecord['odds'] ?? []);
            $externalId = ! empty($rawRecord['external_id']) ? (string) $rawRecord['external_id'] : null;

            $updateData = [
                'data_source'       => $source,
                'last_synced_at'    => now()->utc(),
                'league_id'         => $league->id,
                'season_id'         => $season->id,
                'home_team_id'      => $homeTeam->id,
                'away_team_id'      => $awayTeam->id,
                'match_date_utc'    => $matchDate,
                'status'            => $rawRecord['status'] ?? (isset($rawRecord['home_score']) ? 'FINISHED' : 'SCHEDULED'),
                'home_score'        => $rawRecord['home_score'] ?? null,
                'away_score'        => $rawRecord['away_score'] ?? null,
                'ht_home_score'     => $rawRecord['ht_home_score'] ?? null,
                'ht_away_score'     => $rawRecord['ht_away_score'] ?? null,
                'is_neutral_ground' => $rawRecord['is_neutral_ground'] ?? false,
                'round'             => $rawRecord['round'] ?? null,
                'quality_score'     => $quality['score'],
                'quality_tier'      => $quality['tier'],
            ];

            // Déduplication par external_id (prioritaire) ou par clé composite
            $match = $externalId
                ? FootballMatch::where('external_id', $externalId)->first()
                : null;

            if (! $match) {
                $match = FootballMatch::where([
                    'league_id' => $league->id,
                    'home_team_id' => $homeTeam->id,
                    'away_team_id' => $awayTeam->id,
                    'match_date_utc' => $matchDate,
                ])->orderByRaw('CASE WHEN external_id IS NULL THEN 1 ELSE 0 END')->first();
            }

            if ($match) {
                $match->fill($updateData);
                $match->external_id = $externalId ?: $match->external_id;
                $match->save();
            } else {
                $match = FootballMatch::create($updateData + ['external_id' => $externalId]);
            }

            // Ingest odds if present
            if (! empty($rawRecord['odds']) && count($rawRecord['odds']) === 3) {
                $pinnacle  = Bookmaker::where('code', 'pinnacle')->first() ?? Bookmaker::first();
                $market1N2 = Market::where('code', '1N2')->first();

                if ($pinnacle && $market1N2) {
                    foreach (['HOME' => 0, 'DRAW' => 1, 'AWAY' => 2] as $sel => $idx) {
                        OddsSnapshot::create([
                            'match_id'          => $match->id,
                            'bookmaker_id'      => $pinnacle->id,
                            'market_id'         => $market1N2->id,
                            'selection'         => $sel,
                            'decimal_odds'      => $rawRecord['odds'][$idx],
                            'snapshot_time_utc' => $matchDate->copy()->subHours(2),
                            'is_closing'        => true,
                            'source'            => $source,
                        ]);
                    }
                }
            }

            // Le score initial est calculé avant l'écriture des cotes. On le
            // recalcule donc sur l'état effectivement persisté du match.
            $this->refreshQualityScore($match->fresh());

            return $match->fresh();
        } catch (Exception $e) {
            Log::error('Match record ingestion exception: '.$e->getMessage());

            try {
                DataQuarantine::create([
                    'source'             => $source,
                    'entity_type'        => 'match',
                    'raw_hash'           => hash('sha256', json_encode($rawRecord)),
                    'rejection_reason'   => 'import_persistence_error:' . $e::class,
                    'raw_payload'        => $rawRecord,
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
