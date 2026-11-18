<?php

namespace App\Services\Import;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Connecteur Football-Data.co.uk.
 *
 * Le site publie un CSV par compétition et par saison sous
 * `/mmz4281/{saison}/{division}.csv`. Les colonnes évoluent d'une saison à
 * l'autre : chaque champ est donc lu par son nom, jamais par sa position, et
 * une colonne absente reste nulle au lieu de devenir zéro.
 *
 * Les cotes fournies sont des cotes de clôture pré-match (un bookmaker par
 * colonne). Elles ne couvrent que 1N2 et, selon les fichiers, Plus/Moins 2,5 :
 * aucune cote de corners ou de cartons n'existe dans cette source.
 */
class FootballDataUkConnector
{
    public const SOURCE = 'football-data-uk';

    /** Colonnes statistiques par côté, telles que publiées. */
    private const STAT_COLUMNS = [
        'home' => [
            'shots' => 'HS',
            'shots_on_target' => 'HST',
            'fouls' => 'HF',
            'corners' => 'HC',
            'yellow_cards' => 'HY',
            'red_cards' => 'HR',
            'xg' => 'HxG',
        ],
        'away' => [
            'shots' => 'AS',
            'shots_on_target' => 'AST',
            'fouls' => 'AF',
            'corners' => 'AC',
            'yellow_cards' => 'AY',
            'red_cards' => 'AR',
            'xg' => 'AxG',
        ],
    ];

    /** Bookmakers 1N2 réellement publiés, avec leur colonne préfixe. */
    private const ODDS_1X2 = [
        'bet365' => ['H' => 'B365H', 'D' => 'B365D', 'A' => 'B365A'],
        'pinnacle' => ['H' => 'PSH', 'D' => 'PSD', 'A' => 'PSA'],
        'betfair_ex' => ['H' => 'BFH', 'D' => 'BFD', 'A' => 'BFA'],
        'market_max' => ['H' => 'MaxH', 'D' => 'MaxD', 'A' => 'MaxA'],
        'market_avg' => ['H' => 'AvgH', 'D' => 'AvgD', 'A' => 'AvgA'],
    ];

    public function __construct(private readonly array $config)
    {
    }

    public static function fromConfig(): self
    {
        return new self((array) config('imports.football_data_uk', []));
    }

    /** @return array<int, string> */
    public function seasons(): array
    {
        return array_values(array_filter(array_map('strval', (array) ($this->config['seasons'] ?? []))));
    }

    /** @return array<string, array<string, string>> */
    public function competitions(): array
    {
        return (array) ($this->config['competitions'] ?? []);
    }

    /**
     * Télécharge (ou relit depuis le cache) un fichier puis le normalise.
     *
     * @return array<int, NormalizedMatch>
     */
    public function fetchAndNormalize(string $season, string $division): array
    {
        $competition = $this->competitions()[$division] ?? null;
        if ($competition === null) {
            throw new RuntimeException("Division Football-Data.co.uk inconnue : {$division}.");
        }

        $rows = $this->rows($season, $division);
        $matches = [];
        $yearStart = 2000 + (int) substr($season, 0, 2);
        $seasonName = sprintf('%d-%d', $yearStart, $yearStart + 1);

        foreach ($rows as $row) {
            $home = $this->text($row['HomeTeam'] ?? null);
            $away = $this->text($row['AwayTeam'] ?? null);
            $date = $this->parseDate($row['Date'] ?? null);

            if ($home === null || $away === null || $date === null) {
                continue;
            }

            // L'heure publiée est locale britannique : elle est convertie en UTC
            // seulement lorsqu'elle existe réellement.
            $time = $this->text($row['Time'] ?? null);
            $kickoff = $date;
            $kickoffTimeKnown = false;
            if ($time !== null && preg_match('/^(\d{1,2}):(\d{2})$/', $time, $m)) {
                $kickoff = CarbonImmutable::create(
                    (int) $date->format('Y'),
                    (int) $date->format('n'),
                    (int) $date->format('j'),
                    (int) $m[1],
                    (int) $m[2],
                    0,
                    'Europe/London'
                )->utc();
                $kickoffTimeKnown = true;
            }

            $matches[] = new NormalizedMatch(
                source: self::SOURCE,
                externalId: sprintf(
                    'fduk:%s:%s:%s:%s:%s',
                    $season,
                    $division,
                    $date->format('Y-m-d'),
                    $this->slug($home),
                    $this->slug($away)
                ),
                leagueCode: (string) $competition['code'],
                leagueName: (string) $competition['name'],
                country: (string) $competition['country'],
                seasonName: $seasonName,
                seasonYearStart: $yearStart,
                seasonYearEnd: $yearStart + 1,
                kickoffUtc: $kickoff,
                kickoffTimeKnown: $kickoffTimeKnown,
                homeTeam: $home,
                awayTeam: $away,
                homeScore: $this->int($row['FTHG'] ?? null),
                awayScore: $this->int($row['FTAG'] ?? null),
                htHomeScore: $this->int($row['HTHG'] ?? null),
                htAwayScore: $this->int($row['HTAG'] ?? null),
                metadata: [
                    'referee' => $this->text($row['Referee'] ?? null),
                    'division' => $division,
                    'season' => $season,
                    'stats' => $this->teamStats($row),
                    'odds' => $this->odds($row),
                ],
            );
        }

        return $matches;
    }

    /**
     * @param  array<string, string|null>  $row
     * @return array{home: array<string, float|int>, away: array<string, float|int>}|null
     */
    private function teamStats(array $row): ?array
    {
        $stats = ['home' => [], 'away' => []];

        foreach (self::STAT_COLUMNS as $side => $columns) {
            foreach ($columns as $field => $column) {
                $value = $this->number($row[$column] ?? null);
                if ($value === null) {
                    continue; // Colonne absente : la valeur reste inconnue.
                }

                $stats[$side][$field] = $field === 'xg'
                    ? round($value, 2)
                    : (int) round($value);
            }
        }

        return ($stats['home'] === [] && $stats['away'] === []) ? null : $stats;
    }

    /**
     * Cotes de clôture réellement présentes dans le fichier.
     *
     * @param  array<string, string|null>  $row
     * @return array<string, array<int, array<string, float|string>>>|null
     */
    private function odds(array $row): ?array
    {
        $closing = [];

        foreach (self::ODDS_1X2 as $bookmaker => $columns) {
            $home = $this->number($row[$columns['H']] ?? null);
            $draw = $this->number($row[$columns['D']] ?? null);
            $away = $this->number($row[$columns['A']] ?? null);

            if ($home === null || $draw === null || $away === null) {
                continue;
            }

            if ($home <= 1.0 || $draw <= 1.0 || $away <= 1.0) {
                continue;
            }

            $closing[] = [
                'bookmaker' => $bookmaker,
                'home' => $home,
                'draw' => $draw,
                'away' => $away,
            ];
        }

        $totals = [];
        foreach ([
            'bet365' => ['>2.5' => 'B365>2.5', '<2.5' => 'B365<2.5'],
            'market_avg' => ['>2.5' => 'Avg>2.5', '<2.5' => 'Avg<2.5'],
        ] as $bookmaker => $columns) {
            $over = $this->number($row[$columns['>2.5']] ?? null);
            $under = $this->number($row[$columns['<2.5']] ?? null);
            if ($over === null || $under === null || $over <= 1.0 || $under <= 1.0) {
                continue;
            }

            $totals[] = ['bookmaker' => $bookmaker, 'over' => $over, 'under' => $under];
        }

        if ($closing === [] && $totals === []) {
            return null;
        }

        return array_filter([
            '1x2_closing' => $closing,
            'totals_2_5_closing' => $totals,
        ]);
    }

    /**
     * @return array<int, array<string, string|null>>
     */
    private function rows(string $season, string $division): array
    {
        $raw = $this->rawCsv($season, $division);
        $lines = preg_split('/\r\n|\n|\r/', trim($raw)) ?: [];
        if ($lines === []) {
            return [];
        }

        $header = str_getcsv(array_shift($lines));
        $header = array_map(static function (?string $name): string {
            // Le premier en-tête est précédé d'un BOM UTF-8.
            return trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) $name));
        }, $header);

        $rows = [];
        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }

            $values = str_getcsv($line);
            if (count($values) < 5) {
                continue;
            }

            $row = [];
            foreach ($header as $index => $name) {
                $row[$name] = $values[$index] ?? null;
            }
            $rows[] = $row;
        }

        return $rows;
    }

    private function rawCsv(string $season, string $division): string
    {
        $directory = rtrim((string) ($this->config['cache_path'] ?? storage_path('app/football-data-uk')), '/');
        $path = $directory.'/'.$season.'_'.$division.'.csv';

        if (is_file($path)) {
            $cached = (string) file_get_contents($path);
            if (trim($cached) !== '') {
                return $cached;
            }
        }

        $url = rtrim((string) ($this->config['base_url'] ?? 'https://www.football-data.co.uk/mmz4281'), '/')
            .'/'.$season.'/'.$division.'.csv';

        // Le site renvoie une page vide sans en-tête navigateur.
        $response = Http::withHeaders([
            'User-Agent' => (string) ($this->config['user_agent'] ?? 'Mozilla/5.0 (compatible; PRONO-IA/1.0)'),
        ])->timeout(max(10, (int) ($this->config['timeout'] ?? 45)))->get($url);

        if (! $response->successful() || trim($response->body()) === '') {
            Log::warning('Football-Data.co.uk: fichier indisponible.', ['url' => $url, 'status' => $response->status()]);

            throw new RuntimeException(sprintf('Fichier Football-Data.co.uk indisponible (%s, HTTP %d).', $url, $response->status()));
        }

        if (! is_dir($directory)) {
            @mkdir($directory, 0775, true);
        }
        if (is_dir($directory)) {
            @file_put_contents($path, $response->body());
        }

        return $response->body();
    }

    private function parseDate(?string $value): ?CarbonImmutable
    {
        $value = $this->text($value);
        if ($value === null) {
            return null;
        }

        foreach (['d/m/Y', 'd/m/y'] as $format) {
            $parsed = CarbonImmutable::createFromFormat($format, $value);
            if ($parsed !== false) {
                return $parsed->startOfDay();
            }
        }

        return null;
    }

    private function text(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private function int(mixed $value): ?int
    {
        $value = $this->text(is_scalar($value) ? (string) $value : null);
        if ($value === null || ! preg_match('/^-?\d+$/', $value)) {
            return null;
        }

        return (int) $value;
    }

    private function number(mixed $value): ?float
    {
        $value = $this->text(is_scalar($value) ? (string) $value : null);
        if ($value === null || ! is_numeric($value)) {
            return null;
        }

        return (float) $value;
    }

    private function slug(string $name): string
    {
        $slug = preg_replace('/[^a-z0-9]+/', '-', strtolower($name)) ?? $name;

        return trim($slug, '-');
    }
}
