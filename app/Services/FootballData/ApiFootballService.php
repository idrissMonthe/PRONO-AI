<?php

namespace App\Services\FootballData;

use App\Models\ApiCallLog;
use App\Models\ProviderEntityMap;
use App\Models\ProviderQuota;
use Carbon\Carbon;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class ApiFootballService
{
    private const SOURCE = 'api-football';
    private const PROVIDER = 'api-football';

    public function __construct(
        private readonly FootballDataService $footballDataService,
    ) {
    }

    /**
     * Synchronise les fixtures des compétitions configurées, puis des
     * compétitions complémentaires réellement exposées par le fournisseur.
     * Une compétition en erreur n'interrompt jamais les autres.
     */
    public function syncFixtures(?string $dateFrom = null, ?string $dateTo = null): array
    {
        $this->ensureConfigured();
        $now = Carbon::now('Africa/Douala');
        $dateFrom ??= $now->copy()->subDays(3)->toDateString();
        $dateTo ??= $now->copy()->addDays((int) config('services.api_football.days_ahead', 14))->toDateString();

        $plan = $this->competitionPlan();

        // Rotation des compétitions découvertes : sans elle, un quota atteint
        // en cours de route affamerait toujours les mêmes compétitions.
        $configured = array_values(array_filter($plan, fn (array $competition): bool => ($competition['origin'] ?? '') === 'configured'));
        $discovered = array_values(array_filter($plan, fn (array $competition): bool => ($competition['origin'] ?? '') !== 'configured'));
        if (count($discovered) > 1) {
            $cursor = (int) Cache::get('api-football.rotation.cursor', 0);
            $offset = $cursor % count($discovered);
            $discovered = array_merge(array_slice($discovered, $offset), array_slice($discovered, 0, $offset));
            Cache::put('api-football.rotation.cursor', $cursor + 1, now()->addDays(7));
        }
        $plan = array_merge($configured, $discovered);

        $stats = $this->emptyStats('fixtures', $dateFrom, $dateTo);
        $stats['competitions'] = array_column($plan, 'id');

        foreach ($plan as $competition) {
            if (! $this->quotaAvailable()) {
                $stats['quota_exhausted'] = true;
                Log::warning('API-Football quota exhausted during fixture sync.', [
                    'skipped_competition' => $competition['id'],
                ]);
                break;
            }

            $seasons = $this->seasonsForCompetition($competition);
            if ($seasons === []) {
                Log::info('API-Football : aucune saison demandée disponible pour cette compétition.', [
                    'league' => $competition['id'],
                    'wanted' => $this->seasonsFor(),
                    'available' => $competition['seasons'] ?? [],
                ]);

                continue;
            }

            foreach ($seasons as $season) {
                if ($this->seasonUnavailable((int) $competition['id'], $season)) {
                    continue;
                }

                if (! $this->quotaAvailable()) {
                    $stats['quota_exhausted'] = true;
                    break 2;
                }

                try {
                    $fixtures = $this->allPages('/fixtures', [
                        'league' => $competition['id'],
                        'season' => $season,
                        'from' => $dateFrom,
                        'to' => $dateTo,
                    ]);
                } catch (RuntimeException $exception) {
                    if ($this->isSeasonAccessError($exception->getMessage())) {
                        // Évite de rejouer chaque jour une saison refusée par le
                        // plan, et donc de gaspiller le quota.
                        Cache::put(
                            $this->seasonUnavailableKey((int) $competition['id'], $season),
                            true,
                            now()->addHours(12)
                        );
                    }

                    $stats['failed']++;
                    $stats['errors'][$competition['id'].':'.$season] = $exception->getMessage();
                    Log::warning('API-Football competition sync failed.', [
                        'league' => $competition['id'],
                        'season' => $season,
                        'error' => $exception->getMessage(),
                    ]);
                    continue;
                }

                $stats['succeeded']++;

                foreach ($fixtures as $fixture) {
                    $stats['received']++;
                    if ($this->ingestFixture($fixture) !== null) {
                        $stats['imported']++;
                    } else {
                        $stats['rejected']++;
                    }
                }
            }
        }

        $stats['synced_at'] = now()->toIso8601String();

        return $stats;
    }

    /**
     * Une seule requête live globale, puis filtrage local sur les compétitions
     * du plan de couverture. C'est le mode compatible avec le plan gratuit.
     */
    public function syncLive(): array
    {
        $this->ensureConfigured();
        $configured = array_map('intval', array_column($this->competitionPlan(), 'id'));
        $stats = $this->emptyStats('live', null, null);
        $stats['competitions'] = $configured;

        if (! $this->quotaAvailable()) {
            $stats['quota_exhausted'] = true;

            return $stats;
        }

        $fixtures = $this->request('/fixtures', ['live' => 'all'])['items'];

        foreach ($fixtures as $fixture) {
            $leagueId = (int) data_get($fixture, 'league.id', 0);
            if ($configured !== [] && ! in_array($leagueId, $configured, true)) {
                continue;
            }

            $stats['received']++;
            if ($this->ingestFixture($fixture) !== null) {
                $stats['imported']++;
            } else {
                $stats['rejected']++;
            }
        }

        $stats['synced_at'] = now()->toIso8601String();

        return $stats;
    }

    /**
     * Catalogue réellement accessible avec la clé configurée. Mis en cache
     * pour éviter une requête supplémentaire à chaque synchronisation.
     */
    public function catalog(bool $refresh = false): array
    {
        if (! (bool) config('services.api_football.discover', true)) {
            return [];
        }

        // La clé inclut les saisons : un changement de saison ne réutilise pas
        // un catalogue périmé.
        $cacheKey = 'api-football.catalog.v2.'.hash('sha256', (string) config('services.api_football.api_key').'|'.implode(',', $this->seasonsFor()));
        if ($refresh) {
            Cache::forget($cacheKey);
        }

        $cached = Cache::get($cacheKey);
        if (is_array($cached) && array_key_exists('items', $cached)) {
            return $cached['items'];
        }

        $catalog = (function (): array {
                $catalog = [];

                foreach ($this->seasonsFor() as $season) {
                    if (! $this->quotaAvailable()) {
                        break;
                    }

                    try {
                        $rows = $this->allPages('/leagues', ['season' => $season], 1);
                    } catch (RuntimeException $exception) {
                        Log::warning('API-Football catalogue unavailable.', ['error' => $exception->getMessage()]);
                        continue;
                    }

                    foreach ($rows as $row) {
                        $id = (int) data_get($row, 'league.id', 0);
                        if ($id <= 0) {
                            continue;
                        }

                        $catalog[$id] = [
                            'id' => $id,
                            'name' => (string) data_get($row, 'league.name', ''),
                            'type' => strtolower((string) data_get($row, 'league.type', '')),
                            'country' => (string) data_get($row, 'country.name', ''),
                            'current' => (bool) data_get($row, 'current', false),
                            'seasons' => array_map('intval', (array) data_get($row, 'seasons', [])),
                        ];
                    }
                }

                return $this->filterCatalog(array_values($catalog));
        })();

        // Un catalogue vide (plan restreint, incident) ne bloque pas la
        // découverte pendant 24 h.
        Cache::put(
            $cacheKey,
            ['items' => $catalog],
            $catalog === [] ? now()->addMinutes(15) : now()->addMinutes((int) config('services.api_football.catalog_cache_minutes', 1440))
        );

        return $catalog;
    }

    /**
     * Plan de couverture : compétitions configurées d'abord, puis découvertes.
     */
    public function competitionPlan(): array
    {
        $plan = [];
        foreach ((array) config('services.api_football.competitions', []) as $configuredId) {
            $id = (int) $configuredId;
            if ($id > 0) {
                $plan[$id] = ['id' => $id, 'origin' => 'configured'];
            }
        }

        $limit = max(count($plan), (int) config('services.api_football.max_competitions_per_sync', 12));
        foreach ($this->catalog() as $competition) {
            if (count($plan) >= $limit) {
                break;
            }

            $id = (int) $competition['id'];
            if ($id <= 0 || isset($plan[$id])) {
                continue;
            }

            $plan[$id] = [
                'id' => $id,
                'origin' => 'discovered',
                'seasons' => array_values(array_map('intval', (array) ($competition['seasons'] ?? []))),
            ];

            // La saison courante du catalogue évite d'interroger une édition close.
            if (($competition['current'] ?? false) === true) {
                $plan[$id]['current'] = true;
            }
        }

        return array_values($plan);
    }

    /**
     * Quota quotidien du fournisseur, remis à zéro à la journée UTC suivante.
     */
    public function quotaAvailable(): bool
    {
        $quota = $this->quotaModel();

        return $quota->calls_used_today < $quota->daily_limit;
    }

    private function quotaModel(): ProviderQuota
    {
        $quota = ProviderQuota::firstOrCreate(
            ['provider' => self::PROVIDER],
            [
                'daily_limit' => (int) config('services.api_football.daily_call_limit', 100),
                'calls_used_today' => 0,
                'reset_at_utc' => now()->utc()->addDay()->startOfDay(),
                'is_active' => true,
            ]
        );

        if ($quota->reset_at_utc === null || $quota->reset_at_utc->isPast()) {
            $quota->forceFill([
                'calls_used_today' => 0,
                'reset_at_utc' => now()->utc()->addDay()->startOfDay(),
            ])->save();
        }

        if ($quota->daily_limit <= 0) {
            $quota->forceFill(['daily_limit' => (int) config('services.api_football.daily_call_limit', 100)])->save();
        }

        return $quota;
    }

    private function recordCall(string $endpoint, int $statusCode, float $startedAt, ?int $quotaRemaining): void
    {
        try {
            $quota = $this->quotaModel();
            $quota->forceFill(['calls_used_today' => $quota->calls_used_today + 1])->save();

            ApiCallLog::create([
                'provider' => self::PROVIDER,
                'endpoint' => $endpoint,
                'status_code' => max(0, min(65535, $statusCode)),
                'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                'quota_remaining' => $quotaRemaining,
                'called_at_utc' => now()->utc(),
            ]);
        } catch (\Throwable $exception) {
            Log::warning('Could not persist API-Football call log.', ['error' => $exception->getMessage()]);
        }
    }

    /**
     * Parcourt les pages réellement annoncées par le fournisseur, dans la
     * limite configurée pour protéger le quota.
     */
    private function allPages(string $endpoint, array $query, ?int $maxPages = null): array
    {
        $limit = $maxPages ?? max(1, (int) config('services.api_football.max_pages_per_sync', 2));
        $items = [];
        $page = 1;

        do {
            try {
                // Le fournisseur refuse le paramètre page sur la première
                // requête de certains endpoints : il n'est ajouté qu'en pagination.
                $payload = $this->request($endpoint, $page === 1 ? $query : $query + ['page' => $page]);
            } catch (RuntimeException $exception) {
                if ($page === 1) {
                    throw $exception;
                }

                // Une page suivante en erreur ne doit jamais faire perdre les
                // rencontres déjà récupérées.
                Log::warning('API-Football pagination interrompue, résultats déjà récupérés conservés.', [
                    'endpoint' => $endpoint,
                    'page' => $page,
                    'error' => $exception->getMessage(),
                ]);

                break;
            }

            $items = array_merge($items, $payload['items']);
            $totalPages = max(1, (int) ($payload['paging']['total'] ?? 1));
            $page++;
        } while ($page <= min($limit, $totalPages));

        return $items;
    }

    private function ingestFixture(array $fixture): ?\App\Models\FootballMatch
    {
        $fixtureId = data_get($fixture, 'fixture.id');
        $home = data_get($fixture, 'teams.home.name');
        $away = data_get($fixture, 'teams.away.name');
        $utcDate = data_get($fixture, 'fixture.date');

        if (! $fixtureId || ! $home || ! $away || ! $utcDate) {
            Log::warning('API-Football fixture rejected: missing essential fields.', [
                'fixture_id' => $fixtureId,
            ]);

            return null;
        }

        $leagueId = (int) data_get($fixture, 'league.id', 0);
        $season = (int) data_get($fixture, 'league.season', 0);
        $record = [
            // Préfixe obligatoire : les identifiants numériques d'un fournisseur
            // ne doivent pas entrer en collision avec football-data.org.
            'external_id' => 'api-football:fixture:'.$fixtureId,
            'home_team' => $home,
            'away_team' => $away,
            'date' => $utcDate,
            'league_code' => $this->leagueCode($leagueId),
            'league_name' => data_get($fixture, 'league.name', 'Compétition API-Football'),
            'country' => data_get($fixture, 'league.country', 'World'),
            'season' => $season > 0 ? $season.'-'.($season + 1) : now()->year.'-'.(now()->year + 1),
            'status' => $this->mapStatus((string) data_get($fixture, 'fixture.status.short', 'NS')),
            'home_score' => data_get($fixture, 'goals.home'),
            'away_score' => data_get($fixture, 'goals.away'),
            'ht_home_score' => data_get($fixture, 'score.halftime.home'),
            'ht_away_score' => data_get($fixture, 'score.halftime.away'),
            // API-Football ne fournit pas un indicateur neutre fiable sur
            // toutes les fixtures : on ne déduit jamais le domicile du flux.
            'is_neutral_ground' => false,
            'round' => data_get($fixture, 'league.round'),
        ];

        $match = $this->footballDataService->ingestMatchRecord($record, self::SOURCE);
        if (! $match) {
            return null;
        }

        // Même format d'identifiant que celui persisté sur la rencontre, afin
        // qu'un même match ne produise qu'une seule correspondance.
        ProviderEntityMap::record('match', self::PROVIDER, (string) $record['external_id'], $match->id, [
            'league_code' => $record['league_code'],
            'season' => $record['season'],
        ]);
        ProviderEntityMap::record('league', self::PROVIDER, (string) $leagueId, $match->league_id, [
            'code' => $record['league_code'],
            'name' => $record['league_name'],
        ]);

        return $match;
    }

    private function leagueCode(int $leagueId): string
    {
        $map = (array) config('services.api_football.league_map', []);

        return (string) ($map[$leagueId] ?? $map[(string) $leagueId] ?? 'AF'.$leagueId);
    }

    private function request(string $endpoint, array $query): array
    {
        $startedAt = microtime(true);

        try {
            $response = Http::baseUrl(rtrim((string) config('services.api_football.base_url'), '/'))
                ->timeout((int) config('services.api_football.timeout', 20))
                // Seules les erreurs de connexion sont réessayées : une réponse
                // HTTP d'erreur doit être traitée, pas rejouée.
                ->retry(2, 500, fn ($exception) => $exception instanceof ConnectionException, throw: false)
                ->withHeaders(['x-apisports-key' => config('services.api_football.api_key')])
                ->get($endpoint, $query);
        } catch (ConnectionException $exception) {
            $this->recordCall($endpoint, 0, $startedAt, null);
            Log::warning('API-Football connection failed.', ['error' => $exception->getMessage()]);

            throw new RuntimeException('Impossible de joindre API-Football.');
        }

        $remaining = $response->header('x-ratelimit-requests-remaining');
        $this->recordCall($endpoint, $response->status(), $startedAt, is_numeric($remaining) ? (int) $remaining : null);

        if (! $response->successful()) {
            Log::warning('API-Football request rejected.', [
                'endpoint' => $endpoint,
                'status' => $response->status(),
                'body' => substr($response->body(), 0, 500),
            ]);

            throw new RuntimeException('API-Football a refusé la requête (HTTP '.$response->status().').');
        }

        $errors = $response->json('errors', []);
        if (is_array($errors) && count($errors) > 0) {
            throw new RuntimeException('API-Football a retourné une erreur : '.json_encode($errors, JSON_UNESCAPED_UNICODE));
        }

        $items = $response->json('response', []);
        if (! is_array($items)) {
            throw new RuntimeException('La réponse API-Football ne contient pas de liste valide.');
        }

        return [
            'items' => $items,
            'paging' => (array) $response->json('paging', []),
        ];
    }

    private function filterCatalog(array $catalog): array
    {
        $excluded = array_map('intval', (array) config('services.api_football.exclude_competitions', []));
        $countries = array_map('strtolower', (array) config('services.api_football.countries', []));
        $types = array_map('strtolower', (array) config('services.api_football.types', []));

        return collect($catalog)
            ->reject(fn (array $competition): bool => in_array((int) $competition['id'], $excluded, true))
            ->filter(fn (array $competition): bool => ! $types || in_array(strtolower($competition['type']), $types, true))
            ->filter(fn (array $competition): bool => ! $countries || in_array(strtolower($competition['country']), $countries, true))
            ->values()
            ->all();
    }

    /**
     * Saisons à interroger. Par défaut : année civile courante puis précédente,
     * car certaines compétitions nomment leur saison par leur année de départ.
     */
    private function seasonsFor(): array
    {
        $seasons = array_values(array_unique(array_filter(array_map(
            'intval',
            (array) config('services.api_football.seasons', [])
        ))));

        if ($seasons !== []) {
            return $seasons;
        }

        $current = (int) now('Africa/Douala')->year;

        return [$current, $current - 1];
    }

    /**
     * Restreint les saisons demandées à celles réellement listées par le
     * catalogue, pour ne pas consommer de quota sur une saison inexistante.
     */
    private function seasonsForCompetition(array $competition): array
    {
        $wanted = $this->seasonsFor();
        $available = array_values(array_filter(array_map('intval', (array) ($competition['seasons'] ?? []))));

        if ($available === []) {
            return $wanted;
        }

        return array_values(array_intersect($wanted, $available));
    }

    private function emptyStats(string $mode, ?string $dateFrom, ?string $dateTo): array
    {
        return [
            'provider' => self::SOURCE,
            'mode' => $mode,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'competitions' => [],
            'received' => 0,
            'imported' => 0,
            'rejected' => 0,
            'failed' => 0,
            'succeeded' => 0,
            'quota_exhausted' => false,
            'errors' => [],
        ];
    }

    private function seasonUnavailableKey(int $leagueId, int $season): string
    {
        return 'api-football.season-blocked.'.$leagueId.'.'.$season;
    }

    private function seasonUnavailable(int $leagueId, int $season): bool
    {
        return Cache::has($this->seasonUnavailableKey($leagueId, $season));
    }

    private function isSeasonAccessError(string $message): bool
    {
        $lower = strtolower($message);

        return str_contains($lower, 'do not have access')
            || str_contains($lower, '"plan"');
    }

    private function ensureConfigured(): void
    {
        if (empty(config('services.api_football.api_key'))) {
            throw new RuntimeException('La clé API-Football n\'est pas configurée.');
        }

        if (empty(config('services.api_football.competitions')) && ! (bool) config('services.api_football.discover', true)) {
            throw new RuntimeException('Aucune compétition API-Football n\'est configurée.');
        }
    }

    private function mapStatus(string $status): string
    {
        return match (strtoupper($status)) {
            '1H', 'HT', '2H', 'ET', 'BT', 'P' => 'LIVE',
            'FT', 'AET', 'PEN' => 'FINISHED',
            'PST' => 'POSTPONED',
            'CANC', 'ABD', 'AWD', 'WO' => 'CANCELLED',
            default => 'SCHEDULED',
        };
    }
}
