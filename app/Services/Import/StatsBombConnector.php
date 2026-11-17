<?php

namespace App\Services\Import;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Connecteur StatsBomb Open Data.
 *
 * Licence : données ouvertes fournies pour la recherche et l'analyse, avec
 * obligation d'attribution (« data source: StatsBomb »). Elles sont
 * historiques : aucun calendrier à venir ni donnée temps réel.
 */
class StatsBombConnector
{
    public const SOURCE = 'statsbomb';

    /** Issues de tir considérées comme cadrées par StatsBomb. */
    private const ON_TARGET_OUTCOMES = ['Goal', 'Saved', 'Saved To Post'];

    public function __construct(private readonly array $config)
    {
    }

    public static function fromConfig(): self
    {
        return new self((array) config('imports.statsbomb', []));
    }

    /** @return array<int, array<string, mixed>> */
    public function seasons(): array
    {
        return array_values((array) ($this->config['seasons'] ?? []));
    }

    /** @return array<int, NormalizedMatch> */
    public function fetchAndNormalize(array $season): array
    {
        $competitionId = (int) ($season['competition_id'] ?? 0);
        $seasonId = (int) ($season['season_id'] ?? 0);
        if ($competitionId <= 0 || $seasonId <= 0) {
            throw new RuntimeException('StatsBomb : compétition ou saison invalide dans la configuration.');
        }

        $url = $this->baseUrl()."/matches/{$competitionId}/{$seasonId}.json";
        $response = Http::timeout((int) ($this->config['timeout'] ?? 30))->get($url);

        if (! $response->successful()) {
            throw new RuntimeException("StatsBomb : matchs indisponibles ({$url}, HTTP {$response->status()}).");
        }

        $payload = $response->json();
        if (! is_array($payload) || $payload === []) {
            throw new RuntimeException("StatsBomb : aucun match retourné pour la compétition {$competitionId} / saison {$seasonId}.");
        }

        $matches = [];
        foreach ($payload as $row) {
            if (! is_array($row) || ! isset($row['match_id'], $row['match_date'])) {
                continue;
            }

            try {
                // StatsBomb ne publie pas de fuseau horaire : l'heure de coup
                // d'envoi ne peut pas être convertie en UTC de façon fiable.
                // On stocke donc la date à 00:00 UTC en marquant l'heure comme
                // inconnue, ce qui déclenche une déduplication à la journée.
                $kickoff = CarbonImmutable::parse((string) $row['match_date'], 'UTC')->startOfDay();
            } catch (\Throwable) {
                Log::warning('StatsBomb: date invalide ignorée.', ['match_id' => $row['match_id'] ?? null]);

                continue;
            }

            $homeScore = isset($row['home_score']) ? (int) $row['home_score'] : null;
            $awayScore = isset($row['away_score']) ? (int) $row['away_score'] : null;

            $matches[] = new NormalizedMatch(
                source: self::SOURCE,
                externalId: self::SOURCE.':'.(string) $row['match_id'],
                leagueCode: (string) ($season['code'] ?? 'SB'.$competitionId),
                leagueName: (string) ($season['name'] ?? ($row['competition']['competition_name'] ?? 'StatsBomb')),
                country: (string) ($season['country'] ?? ($row['competition']['country_name'] ?? 'International')),
                seasonName: (string) ($season['season'] ?? ($row['season']['season_name'] ?? $seasonId)),
                seasonYearStart: $this->yearFromSeasonName((string) ($season['season'] ?? $row['season']['season_name'] ?? '')),
                seasonYearEnd: $this->yearFromSeasonName((string) ($season['season'] ?? $row['season']['season_name'] ?? '')) + 1,
                kickoffUtc: $kickoff,
                kickoffTimeKnown: false,
                homeTeam: isset($row['home_team']['home_team_name']) ? (string) $row['home_team']['home_team_name'] : null,
                awayTeam: isset($row['away_team']['away_team_name']) ? (string) $row['away_team']['away_team_name'] : null,
                homeScore: $homeScore,
                awayScore: $awayScore,
                round: isset($row['competition_stage']['name']) ? (string) $row['competition_stage']['name'] : null,
                metadata: [
                    'statsbomb_match_id' => (int) $row['match_id'],
                    'statsbomb_home_team_id' => isset($row['home_team']['home_team_id']) ? (int) $row['home_team']['home_team_id'] : null,
                    'statsbomb_away_team_id' => isset($row['away_team']['away_team_id']) ? (int) $row['away_team']['away_team_id'] : null,
                    'stadium' => $row['stadium']['name'] ?? null,
                    'attendance' => $row['attendance'] ?? null,
                    'kick_off_local' => $row['kick_off'] ?? null,
                ],
            );
        }

        return $matches;
    }

    /**
     * Agrège les tirs d'un match : total, cadrés et somme des xG par équipe.
     *
     * @return array{home: array{shots:int, shots_on_target:int, xg:float}|null, away: array{shots:int, shots_on_target:int, xg:float}|null}
     */
    public function shotStats(NormalizedMatch $match): array
    {
        $matchId = (int) ($match->metadata['statsbomb_match_id'] ?? 0);
        $homeId = (int) ($match->metadata['statsbomb_home_team_id'] ?? 0);
        $awayId = (int) ($match->metadata['statsbomb_away_team_id'] ?? 0);

        if ($matchId <= 0 || $homeId <= 0 || $awayId <= 0) {
            return ['home' => null, 'away' => null];
        }

        $url = $this->baseUrl()."/events/{$matchId}.json";
        $response = Http::timeout((int) ($this->config['timeout'] ?? 30))->get($url);

        if (! $response->successful()) {
            Log::warning('StatsBomb: événements indisponibles.', ['match_id' => $matchId, 'status' => $response->status()]);

            return ['home' => null, 'away' => null];
        }

        $events = $response->json();
        if (! is_array($events)) {
            return ['home' => null, 'away' => null];
        }

        $stats = [
            'home' => ['shots' => 0, 'shots_on_target' => 0, 'xg' => 0.0],
            'away' => ['shots' => 0, 'shots_on_target' => 0, 'xg' => 0.0],
        ];

        foreach ($events as $event) {
            if (! is_array($event) || ($event['type']['name'] ?? null) !== 'Shot') {
                continue;
            }

            $teamId = (int) ($event['team']['id'] ?? 0);
            $side = $teamId === $homeId ? 'home' : ($teamId === $awayId ? 'away' : null);
            if ($side === null) {
                continue;
            }

            $stats[$side]['shots']++;
            $outcome = $event['shot']['outcome']['name'] ?? null;
            if (in_array($outcome, self::ON_TARGET_OUTCOMES, true)) {
                $stats[$side]['shots_on_target']++;
            }
            $xg = $event['shot']['statsbomb_xg'] ?? null;
            if (is_numeric($xg)) {
                $stats[$side]['xg'] += (float) $xg;
            }
        }

        return [
            'home' => $stats['home'],
            'away' => $stats['away'],
        ];
    }

    private function baseUrl(): string
    {
        return rtrim((string) ($this->config['base_url'] ?? ''), '/');
    }

    private function yearFromSeasonName(string $seasonName): int
    {
        if (preg_match('/(\d{4})/', $seasonName, $m)) {
            return (int) $m[1];
        }

        return (int) now()->year;
    }
}
