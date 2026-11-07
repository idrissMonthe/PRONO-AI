<?php

namespace App\Services\Odds;

use App\Models\Bookmaker;
use App\Models\FootballMatch;
use App\Models\Market;
use App\Models\OddsSnapshot;
use App\Models\AuditEvent;
use App\Services\FootballData\FootballDataService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class TheOddsApiService
{
    public function sync(?string $dateFrom = null, ?string $dateTo = null): array
    {
        $apiKey = config('services.the_odds_api.api_key');
        if (! $apiKey) {
            throw new RuntimeException('THE_ODDS_API_KEY n’est pas configurée.');
        }

        $from = Carbon::parse($dateFrom ?? now('Africa/Douala')->toDateString(), 'Africa/Douala')->startOfDay()->utc();
        $to = Carbon::parse($dateTo ?? now('Africa/Douala')->addDays(8)->toDateString(), 'Africa/Douala')->endOfDay()->utc();
        $markets = [
            'h2h' => Market::firstOrCreate(['code' => '1N2'], ['name' => 'Résultat 1N2', 'category' => 'result']),
            'totals' => Market::firstOrCreate(['code' => 'TOTALS'], ['name' => 'Totaux de buts', 'category' => 'goals']),
            'spreads' => Market::firstOrCreate(['code' => 'ASIAN_HANDICAP'], ['name' => 'Handicap asiatique', 'category' => 'handicap']),
        ];
        $stats = ['received' => 0, 'matched' => 0, 'snapshots' => 0, 'unmatched' => 0, 'failed' => 0, 'sports' => [], 'errors' => []];

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
                        'markets' => implode(',', config('services.the_odds_api.markets', ['h2h', 'totals', 'spreads'])),
                        'oddsFormat' => 'decimal',
                        'dateFormat' => 'iso',
                        'bookmakers' => implode(',', config('services.the_odds_api.bookmakers', [])),
                        'commenceTimeFrom' => $from->format('Y-m-d\\TH:i:s\\Z'),
                        'commenceTimeTo' => $to->format('Y-m-d\\TH:i:s\\Z'),
                    ]));
            } catch (\Throwable $exception) {
                AuditEvent::log('ODDS_API_FAILURE', [
                    'sport' => $sport,
                    'error' => $this->redactSecrets($exception->getMessage()),
                    'occurred_at_utc' => now()->utc()->toIso8601String(),
                ]);
                $stats['failed']++;
                $stats['errors'][$sport] = $this->redactSecrets($exception->getMessage());
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
            app(FootballDataService::class)->refreshQualityScore($match->fresh());
            }
        }

        return $stats;
    }

    private function redactSecrets(string $message): string
    {
        return (string) preg_replace('/([?&]apiKey=)[^&\s]+/i', '$1[REDACTED]', $message);
    }

    private function findMatch(string $home, string $away, Carbon $commence, string $leagueCode): ?FootballMatch
    {
        $normalize = static fn (string $value): string => preg_replace('/[^a-z0-9]/', '', strtr(strtolower(trim($value)), [
            'á' => 'a', 'à' => 'a', 'â' => 'a', 'ä' => 'a', 'ã' => 'a',
            'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'í' => 'i',
            'ó' => 'o', 'ô' => 'o', 'ö' => 'o', 'ú' => 'u', 'ü' => 'u',
            'ç' => 'c', 'ñ' => 'n',
        ]));
        $homeKey = $normalize($home);
        $awayKey = $normalize($away);

        $matches = FootballMatch::with(['homeTeam', 'awayTeam', 'league'])
            ->whereHas('league', fn ($query) => $query->where('code', $leagueCode))
            ->whereBetween('match_date_utc', [$commence->copy()->subHours(18), $commence->copy()->addHours(18)])
            ->get();

        return $matches->first(function (FootballMatch $match) use ($normalize, $homeKey, $awayKey): bool {
            $storedHome = $normalize($match->homeTeam?->name ?? '');
            $storedAway = $normalize($match->awayTeam?->name ?? '');
            return $this->teamMatches($storedHome, $homeKey) && $this->teamMatches($storedAway, $awayKey);
        });
    }

    private function teamMatches(string $left, string $right): bool
    {
        if ($left === $right) {
            return true;
        }
        return strlen($left) > 5 && strlen($right) > 5 && (str_contains($left, $right) || str_contains($right, $left));
    }

    private function storeBestBookmaker(FootballMatch $match, array $event, array $markets, array &$stats): void
    {
        $preferred = array_flip(config('services.the_odds_api.bookmakers', []));
        $bookmakers = collect($event['bookmakers'] ?? [])->sortBy(function (array $bookmaker) use ($preferred): int {
            return array_key_exists($bookmaker['key'] ?? '', $preferred) ? $preferred[$bookmaker['key']] : 999;
        });

        $selected = $bookmakers->first(function (array $bookmaker): bool {
            return collect($bookmaker['markets'] ?? [])->contains(fn (array $market): bool => in_array(($market['key'] ?? ''), ['h2h', 'totals', 'spreads'], true) && count($market['outcomes'] ?? []) >= 2);
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
