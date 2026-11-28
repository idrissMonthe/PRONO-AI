<?php

namespace App\Services\Odds;

use App\Models\Bookmaker;
use App\Models\FootballMatch;
use App\Models\Market;
use App\Models\OddsSnapshot;
use App\Models\AuditEvent;
use App\Models\Team;
use App\Models\TeamAlias;
use App\Services\FootballData\FootballDataService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class TheOddsApiService
{
    /** Marchés uniquement disponibles par événement, et leur code interne. */
    private const EVENT_MARKET_CODES = [
        'btts' => 'btts',
        'team_totals' => 'team_totals',
        'double_chance' => 'double_chance',
    ];

    public function sync(?string $dateFrom = null, ?string $dateTo = null): array
    {
        $apiKey = config('services.the_odds_api.api_key');
        if (! $apiKey) {
            throw new RuntimeException('THE_ODDS_API_KEY n’est pas configurée.');
        }

        $from = Carbon::parse($dateFrom ?? now('Africa/Douala')->toDateString(), 'Africa/Douala')->startOfDay()->utc();
        $to = Carbon::parse($dateTo ?? now('Africa/Douala')->addDays(8)->toDateString(), 'Africa/Douala')->endOfDay()->utc();
        $markets = [
            // Codes strictement identiques au seeder : une casse différente
            // créerait un second marché sur une base sensible à la casse.
            'h2h' => Market::firstOrCreate(['code' => '1N2'], ['name' => 'Résultat 1N2', 'category' => 'result']),
            'totals' => Market::firstOrCreate(['code' => 'totals'], ['name' => 'Totaux de buts', 'category' => 'goals']),
            'spreads' => Market::firstOrCreate(['code' => 'asian_handicap'], ['name' => 'Handicap asiatique', 'category' => 'handicap']),
            'btts' => Market::firstOrCreate(['code' => 'btts'], ['name' => 'Les deux équipes marquent', 'category' => 'goals']),
            'team_totals' => Market::firstOrCreate(['code' => 'team_totals'], ['name' => 'Total de buts par équipe', 'category' => 'goals']),
            'double_chance' => Market::firstOrCreate(['code' => 'double_chance'], ['name' => 'Double chance', 'category' => 'result']),
        ];
        // L'endpoint /odds ne supporte que ces marchés. Demander un marché
        // inconnu (btts, player props…) renvoie 422 et annule tout le lot.
        $requestedMarkets = (array) config('services.the_odds_api.markets', ['h2h', 'totals', 'spreads']);
        $supportedMarkets = array_values(array_intersect($requestedMarkets, ['h2h', 'totals', 'spreads']));
        if ($supportedMarkets === []) {
            $supportedMarkets = ['h2h'];
        }
        if (count($supportedMarkets) !== count($requestedMarkets)) {
            Log::notice('The Odds API : marchés non supportés par /odds ignorés.', [
                'ignored' => array_values(array_diff($requestedMarkets, $supportedMarkets)),
            ]);
        }

        // Marchés supplémentaires (BTTS) : l'endpoint global /odds les refuse,
        // ils ne sont disponibles que par événement. On borne le nombre
        // d'appels pour maîtriser le quota du fournisseur.
        $eventMarkets = array_values(array_filter(array_map('trim', explode(',', (string) config('services.the_odds_api.event_markets', 'btts')))));
        $eventBudget = (int) config('services.the_odds_api.event_market_max_events', 15);

        $stats = ['received' => 0, 'matched' => 0, 'snapshots' => 0, 'unmatched' => 0, 'failed' => 0, 'sports' => [], 'errors' => [], 'event_markets' => []];

        foreach ((array) config('services.the_odds_api.sports', []) as $leagueCode => $sport) {
            try {
                $response = Http::baseUrl(rtrim(config('services.the_odds_api.base_url'), '/'))
                    ->timeout(25)
                    ->retry(4, 1000)
                    ->withHeaders(['Accept' => 'application/json', 'Connection' => 'close'])
                    // Docker Desktop peut privilégier une route IPv6 instable vers l’API.
                    ->withOptions(['curl' => [
                        CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
                        CURLOPT_SSLVERSION => CURL_SSLVERSION_TLSv1_2,
                        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                        CURLOPT_FORBID_REUSE => true,
                    ]])
                    ->get("/sports/{$sport}/odds", array_filter([
                        'apiKey' => $apiKey,
                        'regions' => config('services.the_odds_api.regions', 'eu'),
                        'markets' => implode(',', $supportedMarkets),
                        'oddsFormat' => 'decimal',
                        'dateFormat' => 'iso',
                        'bookmakers' => implode(',', config('services.the_odds_api.bookmakers', [])),
                        'commenceTimeFrom' => $from->format('Y-m-d\\TH:i:s\\Z'),
                        'commenceTimeTo' => $to->format('Y-m-d\\TH:i:s\\Z'),
                    ]));
            } catch (\Throwable $exception) {
                AuditEvent::log('ODDS_API_FAILURE', [
                    'sport' => $sport,
                    'error' => self::redactSecrets($exception->getMessage()),
                    'occurred_at_utc' => now()->utc()->toIso8601String(),
                ]);
                $stats['failed']++;
                $stats['errors'][$sport] = self::redactSecrets($exception->getMessage());
                Log::warning('The Odds API indisponible pour une compétition; poursuite de la synchronisation.', ['sport' => $sport]);
                continue;
            }

            if (! $response->successful()) {
                AuditEvent::log('ODDS_API_FAILURE', [
                    'sport' => $sport,
                    'status' => $response->status(),
                    'body' => substr($response->body(), 0, 500),
                    'occurred_at_utc' => now()->utc()->toIso8601String(),
                ]);
                Log::warning('The Odds API rejected odds synchronization.', ['sport' => $sport, 'status' => $response->status()]);
                $stats['failed']++;
                $stats['errors'][$sport] = "HTTP {$response->status()}";
                continue;
            }

            $events = $response->json();
            if (! is_array($events)) {
                continue;
            }
            $stats['received'] += count($events);
            $stats['sports'][$sport] = count($events);

            foreach ($events as $event) {
                $commence = Carbon::parse($event['commence_time'] ?? null)->utc();
                if ($commence->lt($from) || $commence->gt($to)) {
                    continue;
                }

                $match = $this->findMatch($event['home_team'] ?? '', $event['away_team'] ?? '', $commence, $leagueCode);
                if (! $match) {
                    $stats['unmatched']++;
                    continue;
                }

                $stats['matched']++;
                $this->storeBestBookmaker($match, $event, $markets, $stats);

                if ($eventMarkets !== []) {
                    $this->syncEventMarkets($match, $sport, $event, $markets, $eventMarkets, $stats, $eventBudget);
                }

                app(FootballDataService::class)->refreshQualityScore($match->fresh());
            }
        }

        return $stats;
    }

    /**
     * Récupère les marchés complémentaires d'un événement (BTTS, etc.) via
     * l'endpoint `/sports/{sport}/events/{id}/odds`, seul à les accepter.
     */
    private function syncEventMarkets(
        FootballMatch $match,
        string $sport,
        array $event,
        array $markets,
        array $eventMarkets,
        array &$stats,
        int &$budget
    ): void {
        $eventId = $event['id'] ?? null;
        if (! $eventId || $budget <= 0) {
            return;
        }

        // Seuls les marchés connus et pas encore stockés pour cette rencontre
        // sont demandés : un second passage complète les manquants.
        $wanted = array_values(array_unique(array_intersect($eventMarkets, array_keys(self::EVENT_MARKET_CODES))));
        $wanted = array_values(array_filter($wanted, fn (string $key): bool => isset($markets[self::EVENT_MARKET_CODES[$key]])));
        if ($wanted === []) {
            return;
        }

        $alreadyStored = OddsSnapshot::where('match_id', $match->id)
            ->whereIn('market_id', array_map(fn (string $key) => $markets[self::EVENT_MARKET_CODES[$key]]->id, $wanted))
            ->where('source', 'the-odds-api')
            ->pluck('market_id')->all();
        $missing = array_values(array_filter(
            $wanted,
            fn (string $key): bool => ! in_array($markets[self::EVENT_MARKET_CODES[$key]]->id, $alreadyStored, true)
        ));
        if ($missing === []) {
            return;
        }

        $budget--;

        try {
            $response = Http::baseUrl(rtrim(config('services.the_odds_api.base_url'), '/'))
                ->timeout(20)
                ->retry(2, 800)
                ->withHeaders(['Accept' => 'application/json'])
                ->withOptions(['curl' => [CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4]])
                ->get("/sports/{$sport}/events/{$eventId}/odds", array_filter([
                    'apiKey' => config('services.the_odds_api.api_key'),
                    'regions' => config('services.the_odds_api.regions', 'eu'),
                    'markets' => implode(',', $missing),
                    'oddsFormat' => 'decimal',
                    'dateFormat' => 'iso',
                    'bookmakers' => implode(',', (array) config('services.the_odds_api.bookmakers', [])),
                ]));
        } catch (\Throwable $exception) {
            Log::notice('The Odds API : marchés par événement indisponibles.', [
                'sport' => $sport,
                'event' => $eventId,
                'error' => self::redactSecrets($exception->getMessage()),
            ]);

            return;
        }

        if (! $response->successful()) {
            Log::notice('The Odds API : marchés par événement refusés.', [
                'sport' => $sport,
                'event' => $eventId,
                'status' => $response->status(),
            ]);

            return;
        }

        $payload = $response->json();
        if (! is_array($payload)) {
            return;
        }

        $preferred = array_flip((array) config('services.the_odds_api.bookmakers', []));
        $bookmakers = collect($payload['bookmakers'] ?? [])->sortBy(
            fn (array $bookmaker): int => $preferred[$bookmaker['key'] ?? ''] ?? 999
        );

        foreach ($bookmakers as $bookmaker) {
            $covered = collect($bookmaker['markets'] ?? [])
                ->filter(fn (array $market): bool => in_array($market['key'] ?? '', $missing, true) && count($market['outcomes'] ?? []) >= 2)
                ->count();
            if ($covered === 0) {
                continue;
            }

            $this->persistEventMarkets($match, (string) $eventId, $payload, $bookmaker, $markets, $missing, $stats);

            return; // un seul bookmaker de référence, comme pour les autres marchés
        }
    }

    private function persistEventMarkets(
        FootballMatch $match,
        string $eventId,
        array $payload,
        array $bookmaker,
        array $markets,
        array $wanted,
        array &$stats
    ): void {
        $bookmakerModel = Bookmaker::firstOrCreate(
            ['code' => $bookmaker['key']],
            ['name' => $bookmaker['title'] ?? $bookmaker['key'], 'type' => 'low_margin', 'is_active' => true]
        );

        // Les noms d'équipes de l'événement priment, sinon ceux du match local.
        $teams = [
            'home_team' => (string) ($payload['home_team'] ?? $match->homeTeam?->name ?? ''),
            'away_team' => (string) ($payload['away_team'] ?? $match->awayTeam?->name ?? ''),
        ];

        foreach ($bookmaker['markets'] ?? [] as $apiMarket) {
            $apiKey = $apiMarket['key'] ?? null;
            if (! $apiKey || ! in_array($apiKey, $wanted, true)) {
                continue;
            }

            $market = $markets[self::EVENT_MARKET_CODES[$apiKey]] ?? null;
            if (! $market) {
                continue;
            }

            $snapshotTime = Carbon::parse($apiMarket['last_update'] ?? now()->utc())->utc();
            foreach ($apiMarket['outcomes'] ?? [] as $outcome) {
                $price = (float) ($outcome['price'] ?? 0);
                if ($price <= 1.0) {
                    continue;
                }

                [$selection, $line] = $this->eventSelection($apiKey, $outcome, $teams);
                if ($selection === null) {
                    continue;
                }

                $rawHash = hash('sha256', json_encode([$eventId, $bookmaker['key'], $apiKey, $selection, $line, $price, $snapshotTime->toIso8601String()]));
                $snapshot = OddsSnapshot::firstOrCreate(['raw_hash' => $rawHash], [
                    'match_id' => $match->id,
                    'bookmaker_id' => $bookmakerModel->id,
                    'market_id' => $market->id,
                    'selection' => $selection,
                    'line' => $line,
                    'decimal_odds' => $price,
                    'snapshot_time_utc' => $snapshotTime,
                    'is_closing' => false,
                    'source' => 'the-odds-api',
                    'raw_hash' => $rawHash,
                ]);

                if ($snapshot->wasRecentlyCreated) {
                    $stats['snapshots']++;
                    $stats['event_markets'][$apiKey] = ($stats['event_markets'][$apiKey] ?? 0) + 1;
                }
            }
        }
    }

    /**
     * Traduit une issue du fournisseur en sélection interne stable.
     */
    private function eventSelection(string $apiKey, array $outcome, array $payload): array
    {
        $name = strtolower(trim((string) ($outcome['name'] ?? '')));
        $home = (string) ($payload['home_team'] ?? '');
        $away = (string) ($payload['away_team'] ?? '');
        $line = isset($outcome['point']) ? (float) $outcome['point'] : null;

        return match ($apiKey) {
            'btts' => [str_starts_with($name, 'yes') ? 'YES' : (str_starts_with($name, 'no') ? 'NO' : null), null],
            'team_totals' => (function () use ($outcome, $name, $line, $home, $away): array {
                $description = (string) ($outcome['description'] ?? '');
                $side = $this->nameMatches($description, $home) ? 'HOME' : ($this->nameMatches($description, $away) ? 'AWAY' : null);
                $direction = str_contains($name, 'over') ? 'OVER' : (str_contains($name, 'under') ? 'UNDER' : null);
                if (! $side || ! $direction || $line === null) {
                    return [null, null];
                }

                return [$side . '_' . $direction, $line];
            })(),
            'double_chance' => (function () use ($name, $home, $away): array {
                $hasHome = $this->nameMatches($name, $home);
                $hasAway = $this->nameMatches($name, $away);
                $hasDraw = str_contains($name, 'draw') || str_contains($name, 'nul');

                $selection = match (true) {
                    $hasHome && $hasDraw => '1X',
                    $hasAway && $hasDraw => 'X2',
                    $hasHome && $hasAway => '12',
                    default => null,
                };

                return [$selection, null];
            })(),
            default => [null, null],
        };
    }

    private function nameMatches(string $candidate, string $team): bool
    {
        $ignore = ['over', 'under', 'yes', 'no', 'draw', 'nul', 'home', 'away', 'plus', 'moins'];
        $candidateTokens = $this->distinctiveTokens($candidate, $ignore);
        $teamTokens = $this->distinctiveTokens($team, $ignore);
        if ($candidateTokens === [] || $teamTokens === []) {
            return false;
        }

        sort($candidateTokens);
        sort($teamTokens);
        if ($candidateTokens === $teamTokens) {
            return true;
        }

        // The outcome text may only add ignored betting words. Any extra
        // distinctive token means another club, so "Milan" must not match
        // "Inter Milan" and "Paris" must not match "Paris Saint-Germain".
        return array_diff($teamTokens, $candidateTokens) === []
            && array_diff($candidateTokens, $teamTokens) === [];
    }

    /**
     * @param  array<int, string>  $ignore
     * @return array<int, string>
     */
    private function distinctiveTokens(string $value, array $ignore): array
    {
        $trans = strtr(strtolower(trim(\Illuminate\Support\Str::ascii($value))), [
            'á' => 'a', 'à' => 'a', 'â' => 'a', 'ä' => 'a', 'ã' => 'a',
            'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'í' => 'i',
            'ó' => 'o', 'ô' => 'o', 'ö' => 'o', 'ú' => 'u', 'ü' => 'u',
            'ç' => 'c', 'ñ' => 'n',
        ]);
        $words = preg_split('/[^a-z0-9]+/', $trans) ?: [];
        $stopWords = ['fc', 'cf', 'afc', 'sc', 'ac', 'as', 'club', 'de', 'the'];

        return array_values(array_unique(array_filter(
            $words,
            fn (string $word): bool => $word !== '' && strlen($word) >= 3 && ! in_array($word, $stopWords, true) && ! in_array($word, $ignore, true)
        )));
    }

    /**
     * Masque la clé avant journalisation : cURL recopie l'URL demandée dans
     * son message d'erreur, `apiKey` compris.
     */
    public static function redactSecrets(string $message): string
    {
        return (string) preg_replace('/(apiKey=)[^&\s]+/i', '$1[REDACTED]', $message);
    }

    private function findMatch(string $home, string $away, Carbon $commence, string $leagueCode): ?FootballMatch
    {
        $matches = FootballMatch::with(['homeTeam.aliases', 'awayTeam.aliases', 'league'])
            ->whereHas('league', fn ($query) => $query->where('code', $leagueCode))
            ->whereBetween('match_date_utc', [$commence->copy()->subHours(24), $commence->copy()->addHours(24)])
            ->get();

        $matched = $matches->first(function (FootballMatch $match) use ($home, $away): bool {
            return $this->matchesTeam($match->homeTeam, $home) && $this->matchesTeam($match->awayTeam, $away);
        });

        if ($matched) {
            $this->rememberAlias($matched->homeTeam, $home);
            $this->rememberAlias($matched->awayTeam, $away);
        }

        return $matched;
    }

    /**
     * N'enregistre un alias que si le nom est identique une fois normalisé.
     * Une correspondance approximative ne doit jamais devenir une vérité
     * persistante : elle figerait une erreur sur le mauvais match.
     */
    private function rememberAlias(?Team $team, string $externalName): void
    {
        if (! $team || $externalName === '') {
            return;
        }

        if (strcasecmp($team->name, $externalName) === 0
            || ($team->short_name && strcasecmp($team->short_name, $externalName) === 0)) {
            return;
        }

        $cleanStored = $this->cleanTeamName($team->name);
        $cleanExternal = $this->cleanTeamName($externalName);

        // Égalité normalisée ou synonyme canonique explicite uniquement :
        // jamais une similarité approximative.
        if ($cleanStored !== $cleanExternal
            && ! $this->checkCanonicalDivergences($cleanStored, $cleanExternal)) {
            return;
        }

        // Alias déjà présent (même pour une autre équipe) : on ne crée pas
        // d'ambiguïté supplémentaire.
        if (TeamAlias::where('alias', $externalName)->exists()) {
            return;
        }

        TeamAlias::firstOrCreate(
            ['team_id' => $team->id, 'alias' => $externalName],
            ['source' => 'the-odds-api', 'confidence' => 0.95, 'is_validated' => true]
        );
    }

    private function matchesTeam(?Team $team, string $externalName): bool
    {
        if (! $team) {
            return false;
        }

        $cleanExternal = $this->cleanTeamName($externalName);
        $cleanStoredName = $this->cleanTeamName($team->name);
        $cleanStoredShort = $team->short_name ? $this->cleanTeamName($team->short_name) : '';

        // 1. Égalité directe brute ou normalisée
        if (strcasecmp($team->name, $externalName) === 0 || ($team->short_name && strcasecmp($team->short_name, $externalName) === 0)) {
            return true;
        }
        if ($cleanStoredName === $cleanExternal || ($cleanStoredShort && $cleanStoredShort === $cleanExternal)) {
            return true;
        }

        // 2. Vérification dans les alias enregistrés en base
        if ($team->relationLoaded('aliases') && $team->aliases->isNotEmpty()) {
            foreach ($team->aliases as $aliasObj) {
                $aliasClean = $this->cleanTeamName($aliasObj->alias);
                if ($aliasClean === $cleanExternal || strcasecmp($aliasObj->alias, $externalName) === 0) {
                    return true;
                }
            }
        }

        // 3. Dictionnaire de divergences canoniques (noms internationaux vs locaux)
        if ($this->checkCanonicalDivergences($cleanStoredName, $cleanExternal)) {
            return true;
        }
        if ($cleanStoredShort && $this->checkCanonicalDivergences($cleanStoredShort, $cleanExternal)) {
            return true;
        }

        // 4. Sous-chaîne significative : au moins 6 caractères et au moins 60 %
        // de la longueur de l'autre nom (évite « Milan » ⊂ « Inter Milan »).
        foreach ([$cleanStoredName, $cleanStoredShort] as $candidate) {
            if ($candidate === '' || strlen($candidate) < 6 || strlen($cleanExternal) < 6) {
                continue;
            }
            if (! str_contains($candidate, $cleanExternal) && ! str_contains($cleanExternal, $candidate)) {
                continue;
            }
            $short = min(strlen($candidate), strlen($cleanExternal));
            $long = max(strlen($candidate), strlen($cleanExternal));
            if ($long > 0 && ($short / $long) >= 0.6) {
                return true;
            }
        }

        // 5. Similarité textuelle pour les légères variations d'orthographe
        similar_text($cleanStoredName, $cleanExternal, $percent);
        if ($percent >= 88.0) {
            return true;
        }

        return false;
    }

    private function cleanTeamName(string $value): string
    {
        $trans = strtr(strtolower(trim($value)), [
            'á' => 'a', 'à' => 'a', 'â' => 'a', 'ä' => 'a', 'ã' => 'a',
            'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'í' => 'i',
            'ó' => 'o', 'ô' => 'o', 'ö' => 'o', 'ú' => 'u', 'ü' => 'u',
            'ç' => 'c', 'ñ' => 'n',
        ]);

        $spaced = preg_replace('/[^a-z0-9]+/', ' ', $trans);
        $stopWords = ['fc', 'cf', 'afc', 'sc', 'osc', 'rcd', 'rc', 'ac', 'as', 'ss', 'us', 'bsc', 'sv', 'vfb', 'tsg', 'rb', 'cd', 'sd', 'ud', 'sp', 'ec', 'cr', 'ca', 'se', 'fbc', 'fbpa', 'club', 'de', 'du', 'la', 'le', 'the'];
        $words = array_filter(explode(' ', (string) $spaced), fn (string $w): bool => ! in_array($w, $stopWords, true) && strlen($w) > 0);

        return implode('', $words);
    }

    private function checkCanonicalDivergences(string $a, string $b): bool
    {
        // Uniquement des synonymes propres à une seule équipe. Les groupes
        // recouvrants (« inter »/« milan », « espanyol »/« barcelona »)
        // fusionnaient des clubs distincts.
        $pairs = [
            ['bayern', 'munchen', 'munich'],
            ['paris', 'parissaintgermain', 'psg'],
            ['internazionale', 'intermilan'],
            ['athletic', 'bilbao', 'athleticclub'],
            ['sporting', 'sportingcp', 'sportinglisbon'],
            ['atletico', 'atleticomadrid'],
            ['betis', 'realbetis'],
            ['celta', 'celtavigo'],
            ['dortmund', 'borussiadortmund', 'bvb'],
            ['gladbach', 'monchengladbach'],
            ['leverkusen', 'bayerleverkusen'],
            ['frankfurt', 'eintracht'],
            ['leipzig', 'rbleipzig'],
            ['wolves', 'wolverhampton'],
            ['spurs', 'tottenham'],
            ['newcastle', 'newcastleunited'],
            ['westham', 'westhamunited'],
            ['brighton', 'hovealbion'],
            ['roma', 'asroma'],
            ['lazio', 'sslazio'],
            ['napoli', 'sscnapoli'],
            ['juve', 'juventus'],
            ['atalanta', 'atalantabc'],
            ['lyon', 'olympiquelyonnais'],
            ['marseille', 'olympiquemarseille'],
            ['rennes', 'staderennais'],
            ['lille', 'losc'],
            ['nice', 'ogcnice'],
            ['lens', 'rclens'],
            ['monaco', 'asmonaco'],
        ];

        foreach ($pairs as $group) {
            $matchA = false;
            $matchB = false;
            foreach ($group as $term) {
                if (str_contains($a, $term)) {
                    $matchA = true;
                }
                if (str_contains($b, $term)) {
                    $matchB = true;
                }
            }
            if ($matchA && $matchB) {
                return true;
            }
        }

        return false;
    }

    private function storeBestBookmaker(FootballMatch $match, array $event, array $markets, array &$stats): void
    {
        $preferred = array_flip(config('services.the_odds_api.bookmakers', []));
        $bookmakers = collect($event['bookmakers'] ?? [])->sortBy(function (array $bookmaker) use ($preferred): int {
            return array_key_exists($bookmaker['key'] ?? '', $preferred) ? $preferred[$bookmaker['key']] : 999;
        });

        $selected = $bookmakers->first(function (array $bookmaker): bool {
            return collect($bookmaker['markets'] ?? [])->contains(fn (array $market): bool => in_array(($market['key'] ?? ''), ['h2h', 'totals', 'spreads', 'btts'], true) && count($market['outcomes'] ?? []) >= 2);
        });
        if (! $selected) {
            return;
        }

        $homeName = $event['home_team'] ?? '';
        $awayName = $event['away_team'] ?? '';

        $bookmaker = Bookmaker::firstOrCreate(
            ['code' => $selected['key']],
            ['name' => $selected['title'] ?? $selected['key'], 'type' => 'low_margin', 'is_active' => true]
        );
        foreach ($selected['markets'] ?? [] as $apiMarket) {
            $apiKey = $apiMarket['key'] ?? null;
            if (! $apiKey || ! isset($markets[$apiKey])) {
                continue;
            }

            $snapshotTime = Carbon::parse($apiMarket['last_update'] ?? $event['commence_time'])->utc();
            foreach ($apiMarket['outcomes'] ?? [] as $outcome) {
                $price = (float) ($outcome['price'] ?? 0);
                if ($price <= 1.0) {
                    continue;
                }
                $name = (string) ($outcome['name'] ?? '');
                $selection = match ($apiKey) {
                    'h2h' => $name === 'Draw' ? 'DRAW' : ($name === $homeName ? 'HOME' : ($name === $awayName ? 'AWAY' : null)),
                    'totals' => str_starts_with(strtolower($name), 'over') ? 'OVER' : (str_starts_with(strtolower($name), 'under') ? 'UNDER' : null),
                    'spreads' => $name === $homeName ? 'HOME' : ($name === $awayName ? 'AWAY' : null),
                    'btts' => str_starts_with(strtolower($name), 'yes') ? 'YES' : 'NO',
                    default => null,
                };
                if (! $selection) {
                    continue;
                }

                $line = isset($outcome['point']) ? (float) $outcome['point'] : null;
                $rawHash = hash('sha256', json_encode([$event['id'] ?? null, $selected['key'], $apiKey, $selection, $line, $price, $snapshotTime->toIso8601String()]));
                OddsSnapshot::firstOrCreate(['raw_hash' => $rawHash], [
                    'match_id' => $match->id,
                    'bookmaker_id' => $bookmaker->id,
                    'market_id' => $markets[$apiKey]->id,
                    'selection' => $selection,
                    'line' => $line,
                    'decimal_odds' => $price,
                    'snapshot_time_utc' => $snapshotTime,
                    'is_closing' => false,
                    'source' => 'the-odds-api',
                    'raw_hash' => $rawHash,
                ]);
                $stats['snapshots']++;
            }
        }
    }
}
