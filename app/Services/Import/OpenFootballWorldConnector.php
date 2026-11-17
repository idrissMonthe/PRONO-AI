<?php

namespace App\Services\Import;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Connecteur du dépôt openfootball/world (licence CC0).
 *
 * Contrairement à football.json, ce dépôt n'expose pas de JSON : les saisons
 * sont au format texte « Football.TXT ». Le parseur ci-dessous ne lit que ce
 * que la source contient réellement (date, équipes, score, journée) et ignore
 * les annotations, sans en déduire de données absentes.
 */
class OpenFootballWorldConnector
{
    public const SOURCE = 'openfootball-world';

    private const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

    public function __construct(private readonly array $config)
    {
    }

    public static function fromConfig(): self
    {
        return new self((array) config('imports.openfootball_world', []));
    }

    /** @return array<int, array{key:string,path:string,code:string,name:string,country:string,season:string}> */
    public function plan(?array $keys = null): array
    {
        $leagues = (array) ($this->config['leagues'] ?? []);
        $selected = $keys !== null && $keys !== []
            ? array_values(array_intersect(array_map('strval', $keys), array_keys($leagues)))
            : array_keys($leagues);

        $plan = [];
        foreach ($selected as $key) {
            $plan[] = ['key' => $key] + $leagues[$key];
        }

        return $plan;
    }

    /** @return array<int, NormalizedMatch> */
    public function fetchAndNormalize(array $league): array
    {
        $path = (string) $league['path'];
        $url = rtrim((string) ($this->config['base_url'] ?? ''), '/').'/'.ltrim($path, '/');
        $response = Http::timeout((int) ($this->config['timeout'] ?? 20))->get($url);

        if (! $response->successful()) {
            throw new RuntimeException("OpenFootball World : téléchargement impossible ({$url}, HTTP {$response->status()}).");
        }

        $body = $response->body();
        if (trim($body) === '') {
            throw new RuntimeException("OpenFootball World : fichier vide ({$url}).");
        }

        [$yearStart, $yearEnd] = $this->seasonYears((string) $league['season']);

        $matches = [];
        $currentDate = null;
        $currentRound = null;

        foreach (preg_split('/\R/u', $body) ?: [] as $line) {
            if (preg_match('/^▪\s*(.+)$/u', trim($line), $roundMatch)) {
                $currentRound = trim($roundMatch[1]);

                continue;
            }

            if (preg_match('/^(Mon|Tue|Wed|Thu|Fri|Sat|Sun)\s+([A-Z][a-z]{2})\s+(\d{1,2})\s+(\d{4})$/', trim($line), $dateMatch)) {
                if (in_array($dateMatch[2], self::MONTHS, true)) {
                    $currentDate = trim($line);
                }

                continue;
            }

            // Ligne de match : deux colonnes séparées par au moins deux espaces,
            // un « v » isolé, puis le score. Les annotations éventuelles
            // (`[...]`) sont conservées hors du score.
            if ($currentDate !== null
                && preg_match('/^\s{2,}(?<home>.+?)\s{2,}v\s+(?<away>.+?)\s{2,}(?<hg>\d+)-(?<ag>\d+)(?<rest>.*)$/u', $line, $match)) {
                $home = trim($match['home']);
                $away = trim($match['away']);
                if ($home === '' || $away === '') {
                    continue;
                }

                try {
                    $kickoff = CarbonImmutable::parse($currentDate, 'UTC')->startOfDay();
                } catch (\Throwable) {
                    Log::warning('OpenFootball World: date illisible ignorée.', ['date' => $currentDate, 'line' => trim($line)]);
                    $currentDate = null;

                    continue;
                }

                $htHome = $htAway = null;
                if (preg_match('/\(\s*(\d+)-(\d+)\s*\)/', $match['rest'] ?? '', $half)) {
                    $htHome = (int) $half[1];
                    $htAway = (int) $half[2];
                }

                $matches[] = new NormalizedMatch(
                    source: self::SOURCE,
                    externalId: self::SOURCE.':'.substr(sha1($path.'|'.$currentDate.'|'.$home.'|'.$away), 0, 24),
                    leagueCode: (string) $league['code'],
                    leagueName: (string) $league['name'],
                    country: (string) $league['country'],
                    seasonName: $yearStart.'-'.$yearEnd,
                    seasonYearStart: $yearStart,
                    seasonYearEnd: $yearEnd,
                    kickoffUtc: $kickoff,
                    kickoffTimeKnown: false,
                    homeTeam: $home,
                    awayTeam: $away,
                    homeScore: (int) $match['hg'],
                    awayScore: (int) $match['ag'],
                    htHomeScore: $htHome,
                    htAwayScore: $htAway,
                    round: $currentRound,
                    metadata: ['file' => $path, 'annotation' => trim((string) ($match['rest'] ?? '')) ?: null],
                );
            }
        }

        if ($matches === []) {
            throw new RuntimeException("OpenFootball World : aucun match reconnu dans {$url} (format modifié ?).");
        }

        return $matches;
    }

    /** @return array{0:int,1:int} */
    private function seasonYears(string $season): array
    {
        if (preg_match('/^(\d{4})-(\d{4})$/', $season, $m)) {
            return [(int) $m[1], (int) $m[2]];
        }

        $year = (int) substr($season, 0, 4);

        return [$year, $year + 1];
    }
}
