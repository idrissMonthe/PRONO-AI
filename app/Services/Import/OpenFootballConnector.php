<?php

namespace App\Services\Import;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Connecteur du dépôt openfootball/football.json (données sous licence CC0).
 *
 * Le dépôt expose des calendriers et résultats historiques par saison, sans
 * statistiques avancées et sans aucune donnée « live ». La fréquence de mise à
 * jour dépend des contributions humaines : aucune synchronisation temps réel
 * n'est attendue.
 */
class OpenFootballConnector
{
    public const SOURCE = 'openfootball';

    public function __construct(private readonly array $config)
    {
    }

    public static function fromConfig(): self
    {
        return new self((array) config('imports.openfootball', []));
    }

    /** @return array<int, array{season:string, file:string, code:string, name:string, country:string}> */
    public function plan(?string $season = null, ?array $files = null): array
    {
        $seasons = $season !== null ? [$season] : (array) ($this->config['seasons'] ?? []);
        $competitions = (array) ($this->config['competitions'] ?? []);
        $selected = $files !== null && $files !== []
            ? array_values(array_intersect(array_keys($competitions), $files))
            : array_keys($competitions);

        $plan = [];
        foreach ($seasons as $candidateSeason) {
            foreach ($selected as $file) {
                $competition = $competitions[$file];
                $plan[] = [
                    'season' => (string) $candidateSeason,
                    'file' => $file,
                    'code' => (string) $competition['code'],
                    'name' => (string) $competition['name'],
                    'country' => (string) $competition['country'],
                ];
            }
        }

        return $plan;
    }

    /**
     * @return array<int, NormalizedMatch>
     */
    public function fetchAndNormalize(string $season, string $file, string $code, string $name, string $country): array
    {
        $url = rtrim((string) ($this->config['base_url'] ?? ''), '/').'/'.$season.'/'.$file;
        $response = Http::timeout((int) ($this->config['timeout'] ?? 20))->get($url);

        if (! $response->successful()) {
            throw new RuntimeException("OpenFootball : téléchargement impossible ({$url}, HTTP {$response->status()}).");
        }

        $payload = $response->json();
        if (! is_array($payload) || ! isset($payload['matches']) || ! is_array($payload['matches'])) {
            throw new RuntimeException("OpenFootball : format inattendu pour {$url} (clé « matches » absente).");
        }

        [$yearStart, $yearEnd] = $this->seasonYears($season);
        $matches = [];

        foreach ($payload['matches'] as $index => $row) {
            if (! is_array($row)) {
                continue;
            }

            [$homeScore, $awayScore, $htHome, $htAway] = $this->extractScores($row['score'] ?? null);

            $time = isset($row['time']) && is_string($row['time']) && trim($row['time']) !== ''
                ? trim($row['time'])
                : null;
            $date = (string) ($row['date'] ?? '');
            if ($date === '') {
                Log::warning('OpenFootball: match sans date ignoré.', ['file' => $file, 'index' => $index]);

                continue;
            }

            try {
                $kickoff = $time !== null
                    ? CarbonImmutable::parse($date.' '.$time, 'UTC')
                    : CarbonImmutable::parse($date, 'UTC')->startOfDay();
            } catch (\Throwable) {
                Log::warning('OpenFootball: date invalide ignorée.', ['file' => $file, 'date' => $date, 'index' => $index]);

                continue;
            }

            $home = isset($row['team1']) ? trim((string) $row['team1']) : null;
            $away = isset($row['team2']) ? trim((string) $row['team2']) : null;

            $matches[] = new NormalizedMatch(
                source: self::SOURCE,
                externalId: self::SOURCE.':'.$season.':'.$file.':'.substr(sha1($date.'|'.$home.'|'.$away), 0, 24),
                leagueCode: $code,
                leagueName: $name,
                country: $country,
                seasonName: $yearStart.'-'.$yearEnd,
                seasonYearStart: $yearStart,
                seasonYearEnd: $yearEnd,
                kickoffUtc: $kickoff,
                kickoffTimeKnown: $time !== null,
                homeTeam: $home,
                awayTeam: $away,
                homeScore: $homeScore,
                awayScore: $awayScore,
                htHomeScore: $htHome,
                htAwayScore: $htAway,
                round: isset($row['round']) ? (string) $row['round'] : null,
                metadata: ['file' => $season.'/'.$file],
            );
        }

        return $matches;
    }

    /** @return array{0:int,1:int} */
    private function seasonYears(string $season): array
    {
        if (preg_match('/^(\d{4})-(\d{2,4})$/', $season, $matches)) {
            $start = (int) $matches[1];
            $end = strlen($matches[2]) === 2 ? (int) substr((string) $start, 0, 2).$matches[2] : (int) $matches[2];

            return [$start, $end];
        }

        $year = (int) substr($season, 0, 4);

        return [$year, $year + 1];
    }

    /**
     * Le dépôt utilise deux formes : `score: [1, 2]` (sans mi-temps) et
     * `score: { ft: [1, 2], ht: [0, 1] }`.
     *
     * @return array{0:?int,1:?int,2:?int,3:?int}
     */
    private function extractScores(mixed $score): array
    {
        if (! is_array($score)) {
            return [null, null, null, null];
        }

        $full = $score['ft'] ?? (array_is_list($score) ? $score : null);
        $half = $score['ht'] ?? null;

        $home = is_array($full) && isset($full[0]) ? (int) $full[0] : null;
        $away = is_array($full) && isset($full[1]) ? (int) $full[1] : null;
        $htHome = is_array($half) && isset($half[0]) ? (int) $half[0] : null;
        $htAway = is_array($half) && isset($half[1]) ? (int) $half[1] : null;

        return [$home, $away, $htHome, $htAway];
    }
}
