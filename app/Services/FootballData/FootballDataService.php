<?php

namespace App\Services\FootballData;

use App\Models\Bookmaker;
use App\Models\DataQuarantine;
use App\Models\FootballMatch;
use App\Models\League;
use App\Models\Market;
use App\Models\OddsSnapshot;
use App\Models\ProviderEntityMap;
use App\Models\Season;
use App\Models\Team;
use App\Models\TeamAlias;
use Carbon\Carbon;
use Exception;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
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
        $manualCompetitions = config('services.football_data.competitions', []);

        if (empty($apiKey)) {
            throw new RuntimeException('La clé football-data.org n\'est pas configurée.');
        }

        if (empty($manualCompetitions)) {
            throw new RuntimeException('Aucune compétition football-data.org n\'est configurée.');
        }

        $internationalCatalog = config('services.football_data.discover_international', false)
            ? $this->discoverInternationalCompetitions()
            : [];
        $competitions = array_values(array_unique(array_merge(
            $manualCompetitions,
            array_column($internationalCatalog, 'code')
        )));

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
                // football-data.org ne fournit pas toujours ce champ. En son
                // absence, on conserve false et on ne suppose pas que le
                // premier participant bénéficie d'un terrain à domicile.
                'is_neutral_ground' => (bool) ($providerMatch['neutralGround'] ?? false),
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
            'international_competitions' => $internationalCatalog,
            'international_friendlies_available' => collect($internationalCatalog)
                ->contains(fn (array $competition): bool => str_contains(strtolower($competition['name'] ?? ''), 'friendly')),
            'received'     => count($providerMatches),
            'imported'     => $imported,
            'rejected'     => $rejected,
            'synced_at'    => now()->toIso8601String(),
        ];
    }

    /**
     * Retourne le catalogue réellement exposé au compte par /competitions.
     * Le fournisseur filtre déjà ce catalogue selon le plan de l'utilisateur;
     * aucun code n'est inventé localement. Le résultat est mis en cache pour
     * éviter un appel à chaque synchronisation de matchs.
     */
    public function competitionCatalog(bool $refresh = false): array
    {
        $apiKey = config('services.football_data.api_key');
        if (empty($apiKey)) {
            throw new RuntimeException('La clé football-data.org n\'est pas configurée.');
        }

        // Une nouvelle clé de fournisseur ne doit pas réutiliser le catalogue
        // éventuellement mis en cache pour une autre clé.
        $cacheKey = 'football-data.org.catalog.v1.'.hash('sha256', $apiKey);
        if ($refresh) {
            Cache::forget($cacheKey);
        }

        return Cache::remember(
            $cacheKey,
            now()->addMinutes((int) config('services.football_data.catalog_cache_minutes', 1440)),
            function () use ($apiKey): array {
                try {
                    $response = Http::baseUrl(rtrim(config('services.football_data.base_url'), '/'))
                        ->timeout(20)
                        ->withHeaders(['X-Auth-Token' => $apiKey])
                        ->get('/competitions');
                } catch (ConnectionException $exception) {
                    Log::warning('football-data.org competition catalogue unavailable.', ['error' => $exception->getMessage()]);
                    return [];
                }

                if (! $response->successful()) {
                    Log::warning('football-data.org competition catalogue rejected.', ['status' => $response->status()]);
                    return [];
                }

                return collect($response->json('competitions', []))
                    ->filter(fn ($competition): bool => is_array($competition) && ! empty($competition['code']))
                    ->map(fn (array $competition): array => [
                        'code' => (string) $competition['code'],
                        'name' => (string) ($competition['name'] ?? $competition['code']),
                        'type' => (string) ($competition['type'] ?? 'UNKNOWN'),
                        'area' => (string) data_get($competition, 'area.name', ''),
                        'area_code' => (string) data_get($competition, 'area.code', ''),
                        'plan' => (string) ($competition['plan'] ?? ''),
                    ])
                    ->unique('code')
                    ->values()
                    ->all();
            }
        );
    }

    /**
     * Sélectionne uniquement les compétitions internationales présentes dans
     * le catalogue du fournisseur. Les mots-clés sont configurables et ne
     * fabriquent jamais de code de compétition.
     */
    public function discoverInternationalCompetitions(bool $refresh = false): array
    {
        $keywords = array_map(
            static fn (string $keyword): string => Str::lower(trim($keyword)),
            config('services.football_data.international_keywords', [])
        );

        return collect($this->competitionCatalog($refresh))
            ->filter(function (array $competition) use ($keywords): bool {
                $type = Str::upper($competition['type'] ?? '');
                $name = Str::lower($competition['name'] ?? '');

                return $type === 'INTERNATIONAL'
                    || collect($keywords)->contains(
                        fn (string $keyword): bool => $keyword !== '' && str_contains($name, $keyword)
                    );
            })
            ->values()
            ->all();
    }

    /**
     * Resolves a team name to an internal Team model using exact match, normalized alias, or creates.
     */
    public function resolveTeam(string $rawName, ?League $league = null): Team
    {
        $normalized = Str::squish(trim($rawName));

        $team = Team::where('name', $normalized)->first();
        $team ??= Team::whereRaw('LOWER(name) = LOWER(?)', [$normalized])->first();
        if ($team) {
            return $team;
        }

        $alias = TeamAlias::where('alias', $normalized)->with('team')->first();
        $alias ??= TeamAlias::whereRaw('LOWER(alias) = LOWER(?)', [$normalized])->with('team')->first();
        if ($alias && $alias->team) {
            return $alias->team;
        }

        // Rapprochement inter-fournisseurs : « Arsenal FC » et « Arsenal »
        // désignent la même équipe interne. La correspondance reste tracée par
        // un alias, jamais par une fusion de matchs fondée sur le seul nom.
        $normalizedKey = $this->normalizeTeamKey($normalized);
        if ($normalizedKey !== '') {
            $existingTeam = $this->findTeamByNormalizedKey($normalizedKey, $league);
            if ($existingTeam) {
                $this->rememberTeamAlias($existingTeam, $normalized, 'auto_resolver_normalized', 90.0);

                return $existingTeam;
            }
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

        if ($normalizedKey !== '' && $this->normalizedTeamIndex !== null) {
            $this->normalizedTeamIndex[$normalizedKey][] = $team->id;
        }

        return $team;
    }

    /**
     * Clé de comparaison tolérante : accents, ponctuation et suffixes de club
     * sont retirés. Elle sert uniquement à reconnaître une équipe déjà connue.
     */
    public function normalizeTeamKey(string $name): string
    {
        $value = preg_replace('/[^a-z0-9]+/', ' ', Str::lower(Str::ascii(trim($name)))) ?? '';
        $noise = [
            'fc', 'cf', 'sc', 'afc', 'ac', 'as', 'us', 'ss', 'cs', 'sk', 'bk', 'if', 'ff',
            'club', 'the', 'de', 'do', 'da', 'cd', 'ca', 'rc', 'sv', 'vfb', 'tsg', 'fsv',
        ];
        $tokens = array_filter(
            explode(' ', $value),
            fn (string $token): bool => $token !== '' && ! in_array($token, $noise, true)
        );

        return implode('', $tokens);
    }

    /** @var array<string, array<int, int>>|null */
    private ?array $normalizedTeamIndex = null;

    private function normalizedTeamIndex(): array
    {
        if ($this->normalizedTeamIndex === null) {
            $this->normalizedTeamIndex = [];
            foreach (Team::query()->get(['id', 'name']) as $team) {
                $key = $this->normalizeTeamKey($team->name);
                if ($key !== '') {
                    $this->normalizedTeamIndex[$key][] = $team->id;
                }
            }
        }

        return $this->normalizedTeamIndex;
    }

    /**
     * Rapprochement conservateur : d'abord une clé stricte restreinte au pays /
     * à la ligue, puis une correspondance partielle uniquement si elle désigne
     * une seule équipe du même périmètre.
     */
    private function findTeamByNormalizedKey(string $normalizedKey, ?League $league = null): ?Team
    {
        $index = $this->normalizedTeamIndex();
        $pool = $index[$normalizedKey] ?? [];
        if ($pool !== []) {
            return $this->pickScopedTeam($pool, $league, false);
        }

        if (strlen($normalizedKey) < 8) {
            return null;
        }

        $candidates = [];
        foreach ($index as $existingKey => $ids) {
            if (strlen($existingKey) < 8) {
                continue;
            }

            // « Real Madrid » ne doit pas absorber « Real Madrid Castilla » :
            // la proximité de longueur évite les inclusions trop partielles.
            $shortest = min(strlen($existingKey), strlen($normalizedKey));
            $longest = max(strlen($existingKey), strlen($normalizedKey));
            if ($longest === 0 || ($shortest / $longest) < 0.8) {
                continue;
            }

            if (str_contains($existingKey, $normalizedKey) || str_contains($normalizedKey, $existingKey)) {
                array_push($candidates, ...$ids);
            }
        }

        $candidates = array_values(array_unique($candidates));
        if ($candidates === []) {
            return null;
        }

        $team = $this->pickScopedTeam($candidates, $league, true);

        if ($team) {
            Log::info('Team fuzzy match applied.', ['key' => $normalizedKey, 'team' => $team->name]);
        } else {
            Log::info('Team fuzzy match ignored (ambiguous or out of scope).', [
                'key' => $normalizedKey,
                'candidates' => count($candidates),
            ]);
        }

        return $team;
    }

    private function pickScopedTeam(array $ids, ?League $league, bool $requireUnique): ?Team
    {
        $scoped = $this->scopedTeamIds($ids, $league);

        if (count($scoped) === 1) {
            return Team::find($scoped[0]);
        }

        if (! $requireUnique && $league === null && count($ids) === 1) {
            return Team::find($ids[0]);
        }

        return null;
    }

    private function scopedTeamIds(array $ids, ?League $league): array
    {
        if ($league === null) {
            return array_values($ids);
        }

        $teams = Team::whereIn('id', $ids)->get(['id', 'league_id', 'country']);
        $sameLeague = $teams->where('league_id', $league->id)->pluck('id')->values()->all();
        if ($sameLeague !== []) {
            return $sameLeague;
        }

        return $teams
            ->filter(fn (Team $team): bool => strcasecmp((string) $team->country, (string) $league->country) === 0)
            ->pluck('id')
            ->values()
            ->all();
    }

    private function rememberTeamAlias(Team $team, string $alias, string $source, float $confidence): void
    {
        $existing = TeamAlias::where('alias', $alias)->where('source', $source)->first();

        if ($existing) {
            if ((int) $existing->team_id !== (int) $team->id) {
                Log::warning('Team alias conflict ignored.', [
                    'alias' => $alias,
                    'existing_team' => $existing->team_id,
                    'candidate_team' => $team->id,
                ]);
            }

            return;
        }

        TeamAlias::create([
            'alias' => $alias,
            'source' => $source,
            'team_id' => $team->id,
            'confidence' => $confidence,
            'is_validated' => false,
        ]);
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

        if ($match->external_id && in_array($match->data_source, ['football-data.org', 'api-football'], true)) {
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
            'source' => $match->external_id && in_array($match->data_source, ['football-data.org', 'api-football'], true) ? 10 : 0,
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
     * Recherche la rencontre logique déjà connue. Le rapprochement
     * inter-fournisseurs exige la même compétition, les deux mêmes équipes
     * internes et un coup d'envoi à ±6 h : un nom d'équipe similaire ne suffit
     * jamais à fusionner deux rencontres.
     */
    private function locateExistingMatch(
        ?string $externalId,
        League $league,
        Team $homeTeam,
        Team $awayTeam,
        Carbon $matchDate
    ): ?FootballMatch {
        if ($externalId) {
            $match = FootballMatch::where('external_id', $externalId)->first();
            if ($match) {
                return $match;
            }
        }

        $match = FootballMatch::where([
            'league_id' => $league->id,
            'home_team_id' => $homeTeam->id,
            'away_team_id' => $awayTeam->id,
            'match_date_utc' => $matchDate,
        ])->orderByRaw('CASE WHEN external_id IS NULL THEN 1 ELSE 0 END')->first();

        if ($match) {
            return $match;
        }

        $match = FootballMatch::where('league_id', $league->id)
            ->where('home_team_id', $homeTeam->id)
            ->where('away_team_id', $awayTeam->id)
            ->whereBetween('match_date_utc', [
                $matchDate->copy()->subHours(6),
                $matchDate->copy()->addHours(6),
            ])
            ->orderByRaw('CASE WHEN external_id IS NULL THEN 1 ELSE 0 END')
            ->first();

        if ($match) {
            return $match;
        }

        // Repli inter-ligue borné : mêmes équipes internes, coup d'envoi à
        // ±3 h et compétition réellement équivalente. Un simple nom d'équipe
        // ne suffit jamais à fusionner deux rencontres.
        return FootballMatch::where('home_team_id', $homeTeam->id)
            ->where('away_team_id', $awayTeam->id)
            ->whereBetween('match_date_utc', [
                $matchDate->copy()->subHours(3),
                $matchDate->copy()->addHours(3),
            ])
            ->orderByRaw('CASE WHEN external_id IS NULL THEN 1 ELSE 0 END')
            ->get()
            ->first(fn (FootballMatch $candidate): bool => $this->leaguesAreEquivalent((int) $candidate->league_id, $league));
    }

    private function leaguesAreEquivalent(int $existingLeagueId, League $league): bool
    {
        if ($existingLeagueId === (int) $league->id) {
            return true;
        }

        $existing = League::find($existingLeagueId);
        if (! $existing) {
            return false;
        }

        if ($this->canonicalLeagueCode((string) $existing->code) === $this->canonicalLeagueCode((string) $league->code)) {
            return true;
        }

        $left = preg_replace('/[^a-z0-9]+/', '', Str::lower(Str::ascii((string) $existing->name))) ?? '';
        $right = preg_replace('/[^a-z0-9]+/', '', Str::lower(Str::ascii((string) $league->name))) ?? '';

        return $left !== '' && $left === $right;
    }

    private function canonicalLeagueCode(string $code): string
    {
        $map = (array) config('services.api_football.league_map', []);

        if (preg_match('/^AF(\d+)$/i', $code, $matches)) {
            return strtoupper((string) ($map[(int) $matches[1]] ?? $code));
        }

        return strtoupper($code);
    }

    private function recordProviderMapping(FootballMatch $match, string $source, ?string $externalId): void
    {
        try {
            ProviderEntityMap::record('match', $source, $externalId, $match->id, [
                'league_id' => $match->league_id,
                'season_id' => $match->season_id,
            ]);
        } catch (Exception $exception) {
            Log::warning('Could not persist provider entity mapping.', ['error' => $exception->getMessage()]);
        }
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

            // Déduplication : identifiant fournisseur, clé composite, puis
            // rapprochement inter-fournisseurs borné (voir locateExistingMatch).
            $match = $this->locateExistingMatch($externalId, $league, $homeTeam, $awayTeam, $matchDate);

            if ($match) {
                // La provenance d'origine est conservée : un second fournisseur
                // enrichit la rencontre sans la réécrire ni changer son
                // identifiant externe historique.
                $updateData['data_source'] = $match->data_source ?: $source;
                $match->fill($updateData);
                $match->save();
            } else {
                $match = FootballMatch::create($updateData + ['external_id' => $externalId]);
            }

            $this->recordProviderMapping($match, $source, $externalId);

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
