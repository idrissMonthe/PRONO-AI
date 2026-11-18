<?php

namespace App\Services\Import;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
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

    /** Périodes d'événements retenues : 1 = première mi-temps, 2 = seconde. */
    private const REGULATION_PERIODS = [1, 2];

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
        $payload = $this->fetchJson($url);

        if ($payload === null || $payload === []) {
            throw new RuntimeException("StatsBomb : aucun match retourné pour la compétition {$competitionId} / saison {$seasonId} ({$url}).");
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
     * Conservé pour la compatibilité : les valeurs proviennent du même fichier
     * d'événements que eventStats(), lu une seule fois par match.
     *
     * @return array{home: array{shots:int, shots_on_target:int, xg:float}|null, away: array{shots:int, shots_on_target:int, xg:float}|null}
     */
    public function shotStats(NormalizedMatch $match): array
    {
        $stats = $this->eventStats($match);

        $pick = static function (?array $side): ?array {
            if ($side === null) {
                return null;
            }

            return [
                'shots' => $side['shots'],
                'shots_on_target' => $side['shots_on_target'],
                'xg' => $side['xg'],
            ];
        };

        return ['home' => $pick($stats['home']), 'away' => $pick($stats['away'])];
    }

    /**
     * Agrège l'ensemble des faits exploitables du fichier d'événements.
     *
     * Types StatsBomb réellement présents dans les données ouvertes :
     * - `Shot` (dont `shot.outcome.name`, `shot.statsbomb_xg`) ;
     * - `Pass` avec `pass.type.name = Corner` (corners) ;
     * - `Pass` avec `pass.outcome.name = Pass Offside` (hors-jeu) ;
     * - `Foul Committed` (fautes et cartons infligés) ;
     * - `Bad Behaviour` (cartons hors contact, `bad_behaviour.card.name`) ;
     * - `Starting XI` (`tactics.lineup`) et `Substitution` (temps de jeu) ;
     * - `Own Goal For` (but contre son camp compté pour l'équipe).
     *
     * Aucune valeur n'est déduite d'une absence : si le fichier d'événements
     * est indisponible, home/away restent null.
     *
     * @return array{home: array<string, mixed>|null, away: array<string, mixed>|null, players: array<int, array<string, mixed>>, ht_goals: array{home:int, away:int}|null, max_minute: int|null}
     */
    public function eventStats(NormalizedMatch $match): array
    {
        $matchId = (int) ($match->metadata['statsbomb_match_id'] ?? 0);
        $homeId = (int) ($match->metadata['statsbomb_home_team_id'] ?? 0);
        $awayId = (int) ($match->metadata['statsbomb_away_team_id'] ?? 0);

        $empty = ['home' => null, 'away' => null, 'players' => [], 'ht_goals' => null, 'max_minute' => null];

        if ($matchId <= 0 || $homeId <= 0 || $awayId <= 0) {
            return $empty;
        }

        $events = $this->fetchEvents($matchId);

        if ($events === null) {
            Log::warning('StatsBomb: événements indisponibles après tentatives.', ['match_id' => $matchId]);

            return $empty;
        }

        $teamStats = [
            'home' => $this->emptyTeamStats(),
            'away' => $this->emptyTeamStats(),
        ];
        $htGoals = ['home' => 0, 'away' => 0];
        $htGoalsObserved = false;
        $players = [];
        $subOffMinute = [];
        $maxMinute = 0;

        foreach ($events as $event) {
            if (! is_array($event)) {
                continue;
            }

            $type = $event['type']['name'] ?? null;
            if (! is_string($type)) {
                continue;
            }

            $teamId = (int) ($event['team']['id'] ?? 0);
            $side = $teamId === $homeId ? 'home' : ($teamId === $awayId ? 'away' : null);
            $period = (int) ($event['period'] ?? 0);
            $minute = (int) ($event['minute'] ?? 0);

            if (in_array($period, self::REGULATION_PERIODS, true)) {
                $maxMinute = max($maxMinute, $minute);
            }

            // Un fichier d'événements complet rend le score à la pause
            // observable, y compris 0-0 : une première période sans but n'est
            // pas une donnée manquante.
            if ($period === 1) {
                $htGoalsObserved = true;
            }

            // Composition initiale : ne dépend pas d'un tir ou d'un but.
            if ($type === 'Starting XI' && $side !== null) {
                foreach ((array) ($event['tactics']['lineup'] ?? []) as $entry) {
                    $playerId = (int) ($entry['player']['id'] ?? 0);
                    $playerName = $entry['player']['name'] ?? null;
                    if ($playerId <= 0 || ! is_string($playerName)) {
                        continue;
                    }
                    $players[$playerId] = $this->playerRow($playerId, $playerName, $side, true)
                        + ['position' => $entry['position']['name'] ?? null];
                }

                continue;
            }

            if ($type === 'Substitution' && $side !== null) {
                $outId = (int) ($event['player']['id'] ?? 0);
                if ($outId > 0) {
                    $subOffMinute[$outId] = $minute;
                }
                $in = $event['substitution']['replacement'] ?? [];
                $inId = (int) ($in['id'] ?? 0);
                if ($inId > 0 && is_string($in['name'] ?? null)) {
                    $players[$inId] = $this->playerRow($inId, (string) $in['name'], $side, false)
                        + ['position' => null, 'on_minute' => $minute];
                }

                continue;
            }

            if ($side === null) {
                continue;
            }

            $playerId = (int) ($event['player']['id'] ?? 0);
            $playerName = $event['player']['name'] ?? null;
            if ($playerId > 0 && is_string($playerName) && ! isset($players[$playerId])) {
                // Joueur apparu sans composition (données partielles) : il est
                // enregistré, sans inventer une titularisation.
                $players[$playerId] = $this->playerRow($playerId, $playerName, $side, null)
                    + ['position' => $event['position']['name'] ?? null];
            }

            if ($type === 'Shot') {
                $teamStats[$side]['shots']++;
                $outcome = $event['shot']['outcome']['name'] ?? null;
                $xg = $event['shot']['statsbomb_xg'] ?? null;
                if (in_array($outcome, self::ON_TARGET_OUTCOMES, true)) {
                    $teamStats[$side]['shots_on_target']++;
                }
                if (is_numeric($xg)) {
                    $teamStats[$side]['xg'] += (float) $xg;
                }

                if ($playerId > 0) {
                    $players[$playerId]['shots']++;
                    if (in_array($outcome, self::ON_TARGET_OUTCOMES, true)) {
                        $players[$playerId]['shots_on_target']++;
                    }
                    if (is_numeric($xg)) {
                        $players[$playerId]['xg'] = round($players[$playerId]['xg'] + (float) $xg, 3);
                    }
                }

                if ($outcome === 'Goal') {
                    if ($playerId > 0) {
                        $players[$playerId]['goals']++;
                    }
                    if ($period === 1) {
                        $htGoals[$side]++;
                        $htGoalsObserved = true;
                    }
                }

                continue;
            }

            if ($type === 'Own Goal For') {
                // But contre son camp crédité à l'équipe qui en bénéficie.
                $teamStats[$side]['goals'] = ($teamStats[$side]['goals'] ?? 0) + 1;
                if ($period === 1) {
                    $htGoals[$side]++;
                    $htGoalsObserved = true;
                }

                continue;
            }

            if ($type === 'Pass') {
                if (($event['pass']['type']['name'] ?? null) === 'Corner') {
                    $teamStats[$side]['corners']++;
                }
                if (($event['pass']['outcome']['name'] ?? null) === 'Pass Offside') {
                    $teamStats[$side]['offsides']++;
                }

                continue;
            }

            if ($type === 'Foul Committed') {
                $teamStats[$side]['fouls']++;
                $card = $event['foul_committed']['card']['name'] ?? null;
                $this->applyCard($teamStats[$side], $players[$playerId] ?? null, $card, $players, $playerId);

                continue;
            }

            if ($type === 'Bad Behaviour') {
                $card = $event['bad_behaviour']['card']['name'] ?? null;
                $this->applyCard($teamStats[$side], $players[$playerId] ?? null, $card, $players, $playerId);
            }
        }

        // Temps de jeu : un titulaire sort au remplacement, un entrant commence
        // à sa minute d'entrée. La fin du match est la dernière minute observée.
        if ($maxMinute > 0) {
            foreach ($players as $id => $row) {
                if ($row['is_starter'] === false) {
                    $on = (int) ($row['on_minute'] ?? 0);
                    $players[$id]['minutes_played'] = max(0, $maxMinute - $on);
                } elseif ($row['is_starter'] === true) {
                    $off = $subOffMinute[$id] ?? $maxMinute;
                    $players[$id]['minutes_played'] = max(0, $off);
                }
                unset($players[$id]['on_minute']);
            }
        }

        return [
            'home' => $teamStats['home'],
            'away' => $teamStats['away'],
            'players' => array_values($players),
            'ht_goals' => $htGoalsObserved ? $htGoals : null,
            'max_minute' => $maxMinute > 0 ? $maxMinute : null,
        ];
    }

    /** @return array<string, mixed> */
    private function emptyTeamStats(): array
    {
        return [
            'shots' => 0,
            'shots_on_target' => 0,
            'xg' => 0.0,
            'goals' => 0,
            'corners' => 0,
            'fouls' => 0,
            'offsides' => 0,
            'yellow_cards' => 0,
            'red_cards' => 0,
        ];
    }

    /** @return array<string, mixed> */
    private function playerRow(int $id, string $name, string $side, ?bool $starter): array
    {
        return [
            'external_id' => self::SOURCE.':'.$id,
            'name' => $name,
            'team' => $side,
            'is_starter' => $starter,
            'goals' => 0,
            'shots' => 0,
            'shots_on_target' => 0,
            'xg' => 0.0,
            'yellow_cards' => 0,
            'red_cards' => 0,
            'minutes_played' => null,
        ];
    }

    /**
     * Reporte un carton sur l'équipe et le joueur.
     *
     * Un second jaune est compté comme un jaune ET un rouge (expulsion), ce qui
     * correspond au décompte des marchés cartons.
     *
     * @param  array<string, mixed>  $team
     * @param  array<string, mixed>|null  $player
     * @param  array<int, array<string, mixed>>  $players
     */
    private function applyCard(array &$team, ?array $player, ?string $card, array &$players, int $playerId): void
    {
        unset($player);
        $yellow = 0;
        $red = 0;
        switch ($card) {
            case 'Yellow Card':
                $yellow = 1;
                break;
            case 'Second Yellow':
                $yellow = 1;
                $red = 1;
                break;
            case 'Red Card':
                $red = 1;
                break;
            default:
                return;
        }

        $team['yellow_cards'] += $yellow;
        $team['red_cards'] += $red;

        if ($playerId <= 0 || ! isset($players[$playerId])) {
            return;
        }

        $players[$playerId]['yellow_cards'] += $yellow;
        $players[$playerId]['red_cards'] += $red;
    }

    /**
     * Lit le fichier d'événements d'un match, depuis le cache local quand il
     * existe, sinon depuis le dépôt distant (puis mise en cache).
     *
     * Un fichier d'événements pèse plusieurs mégaoctets : sans cache, un import
     * interrompu repartait de zéro et retéléchargeait les mêmes matchs. Le
     * cache reste dans `storage/` (hors Git) car la licence StatsBomb interdit
     * la redistribution.
     */
    private function fetchEvents(int $matchId): ?array
    {
        $directory = rtrim((string) ($this->config['cache_path'] ?? storage_path('app/statsbomb-cache')), '/');
        $path = $directory.'/'.$matchId.'.json';

        if (is_file($path)) {
            $raw = @file_get_contents($path);
            if (is_string($raw) && $raw !== '') {
                $cached = json_decode($raw, true);
                if (is_array($cached) && $cached !== []) {
                    return $cached;
                }
            }
        }

        $events = $this->fetchJson($this->baseUrl()."/events/{$matchId}.json");

        if ($events !== null && $events !== []) {
            if (! is_dir($directory)) {
                @mkdir($directory, 0775, true);
            }

            $encoded = json_encode($events);
            if (is_string($encoded) && is_dir($directory)) {
                @file_put_contents($path, $encoded);
            }
        }

        return $events;
    }

    /**
     * Télécharge un JSON avec quelques tentatives. Une coupure réseau ne doit
     * pas faire perdre tout un import : les fichiers d'événements pèsent
     * plusieurs mégaoctets et les dépôts publics limitent le débit.
     */
    private function fetchJson(string $url): ?array
    {
        $attempts = max(1, (int) ($this->config['retry_attempts'] ?? 3));
        $timeout = max(5, (int) ($this->config['timeout'] ?? 60));
        $pause = max(0, (int) ($this->config['retry_pause_ms'] ?? 1500));

        $lastError = null;
        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                $response = Http::timeout($timeout)->get($url);
            } catch (ConnectionException $exception) {
                $lastError = $exception->getMessage();
                if ($attempt < $attempts) {
                    usleep($pause * 1000 * $attempt);

                    continue;
                }

                throw new RuntimeException("StatsBomb : téléchargement impossible ({$url}) après {$attempts} tentative(s) : {$lastError}");
            }

            if ($response->successful()) {
                $payload = $response->json();

                return is_array($payload) ? $payload : null;
            }

            if ($response->serverError() && $attempt < $attempts) {
                usleep($pause * 1000 * $attempt);

                continue;
            }

            Log::warning('StatsBomb: réponse non exploitable.', ['url' => $url, 'status' => $response->status()]);

            return null;
        }

        return null;
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
