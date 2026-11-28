<?php

namespace App\Services\Import;

use App\Models\Bookmaker;
use App\Models\FootballMatch;
use App\Models\ImportRun;
use App\Models\League;
use App\Models\Market;
use App\Models\OddsSnapshot;
use App\Models\ProviderEntityMap;
use App\Models\Season;
use App\Models\Team;
use App\Models\TeamMatchStat;
use App\Services\FootballData\FootballDataService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Point d'entrée unique pour toutes les sources additionnelles.
 *
 * Les connecteurs ne touchent jamais la base : ils produisent des
 * NormalizedMatch. Ce service résout les entités internes, applique la
 * déduplication bornée de FootballDataService et refuse d'écraser un score
 * officiel avec une valeur divergente d'un autre fournisseur.
 */
class MultiSourceImporter
{
    public function __construct(private readonly FootballDataService $footballData)
    {
    }

    /**
     * @param  array<int, NormalizedMatch>  $matches
     * @return array<string, int|array>
     */
    public function import(ImportRun $run, array $matches, bool $dryRun = false, ?callable $progress = null): array
    {
        $counters = [
            'received' => count($matches),
            'imported' => 0,
            'updated' => 0,
            'rejected' => 0,
            'divergent' => 0,
            'skipped' => 0,
            'stats_rows' => 0,
            'odds_rows' => 0,
            'metadata' => ['divergences' => [], 'errors' => []],
        ];

        foreach ($matches as $match) {
            if (! $match instanceof NormalizedMatch || ! $match->homeTeam || ! $match->awayTeam) {
                $counters['rejected']++;
                $counters['metadata']['errors'][] = [
                    'external_id' => $match instanceof NormalizedMatch ? $match->externalId : null,
                    'reason' => 'missing_team',
                ];

                continue;
            }

            try {
                $league = $this->resolveLeague($match);
                $this->resolveSeason($league, $match);
                $homeTeam = $this->footballData->resolveTeam($match->homeTeam, $league);
                $awayTeam = $this->footballData->resolveTeam($match->awayTeam, $league);

                $existing = $this->findExisting($match, $league, $homeTeam, $awayTeam);

                if ($existing && $this->diverges($existing, $match)) {
                    // La correspondance est tout de même enregistrée : la
                    // provenance reste auditable même quand le score officiel
                    // est conservé.
                    if (! $dryRun) {
                        ProviderEntityMap::record('match', $match->source, $match->externalId, $existing->id, [
                            'league_id' => $existing->league_id,
                            'season_id' => $existing->season_id,
                            'divergent' => true,
                        ]);
                    }

                    $counters['divergent']++;
                    if (count($counters['metadata']['divergences']) < 50) {
                        $counters['metadata']['divergences'][] = [
                            'match_id' => $existing->id,
                            'kept_source' => $existing->data_source,
                            'incoming_source' => $match->source,
                            'kept_score' => [$existing->home_score, $existing->away_score],
                            'incoming_score' => [$match->homeScore, $match->awayScore],
                        ];
                    }

                    continue;
                }

                if ($dryRun) {
                    $existing ? $counters['updated']++ : $counters['imported']++;
                    $progress?->call($this, $match, $existing ? 'updated' : 'imported');

                    continue;
                }

                $record = $match->toIngestRecord();

                // L'heure de coup d'envoi existante est conservée lorsqu'elle
                // est connue : une source sans heure ne doit pas la déplacer.
                if ($existing) {
                    $record['date'] = $existing->match_date_utc->toIso8601String();
                }

                // Une source qui ne fournit pas de score (matchs à venir) ne
                // doit jamais effacer un résultat déjà enregistré : le statut et
                // les scores existants sont conservés tels quels.
                if ($existing && ! $match->hasScore()) {
                    $record['status'] = $existing->status;
                    $record['home_score'] = $existing->home_score;
                    $record['away_score'] = $existing->away_score;
                    $record['ht_home_score'] = $existing->ht_home_score;
                    $record['ht_away_score'] = $existing->ht_away_score;
                }

                $persisted = $this->footballData->ingestMatchRecord($record, $match->source);

                if (! $persisted) {
                    $counters['rejected']++;
                    $counters['metadata']['errors'][] = [
                        'external_id' => $match->externalId,
                        'reason' => 'ingestion_rejected',
                    ];

                    continue;
                }

                // Faits annexes fournis par la source (statistiques d'équipe
                // et cotes de clôture). Écrits seulement après un match
                // réellement persisté.
                $facts = $this->persistFacts($persisted, $match);
                $counters['stats_rows'] = ($counters['stats_rows'] ?? 0) + $facts['stats'];
                $counters['odds_rows'] = ($counters['odds_rows'] ?? 0) + $facts['odds'];

                if ($existing) {
                    $counters['updated']++;
                    $progress?->call($this, $match, 'updated');
                } else {
                    $counters['imported']++;
                    $progress?->call($this, $match, 'imported');
                }
            } catch (Throwable $exception) {
                $counters['rejected']++;
                if (count($counters['metadata']['errors']) < 50) {
                    $counters['metadata']['errors'][] = [
                        'external_id' => $match->externalId,
                        'reason' => $exception::class.': '.$exception->getMessage(),
                    ];
                }
                Log::error('Multi-source import rejected a match.', [
                    'source' => $match->source,
                    'external_id' => $match->externalId,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        return $counters;
    }

    /**
     * Importe les faits d'un match StatsBomb déjà stocké : tirs, xG, corners,
     * fautes, cartons, hors-jeu, score à la pause et temps de jeu individuel.
     *
     * L'écriture est idempotente : `team_match_stats` est unique par
     * (match, équipe) et `player_match_stats` par (match, fournisseur, joueur).
     */
    public function importStats(NormalizedMatch $match, StatsBombConnector $connector, bool $dryRun = false): bool
    {
        $external = ProviderEntityMap::where([
            'entity_type' => 'match',
            'provider' => StatsBombConnector::SOURCE,
            'external_id' => $match->externalId,
        ])->first();

        $stored = $external ? FootballMatch::find($external->internal_id) : null;
        if (! $stored) {
            return false;
        }

        $stats = $connector->eventStats($match);
        if ($stats['home'] === null && $stats['away'] === null) {
            return false;
        }

        if ($dryRun) {
            return true;
        }

        $teamIds = ['home' => $stored->home_team_id, 'away' => $stored->away_team_id];

        foreach ($teamIds as $side => $teamId) {
            $values = $stats[$side];
            if ($values === null) {
                continue;
            }

            TeamMatchStat::updateOrCreate(
                ['match_id' => $stored->id, 'team_id' => $teamId],
                [
                    'is_home' => $side === 'home',
                    'provider' => StatsBombConnector::SOURCE,
                    'shots' => $values['shots'],
                    'shots_on_target' => $values['shots_on_target'],
                    'xg' => round((float) $values['xg'], 2),
                    'corners' => $values['corners'],
                    'fouls' => $values['fouls'],
                    'offsides' => $values['offsides'],
                    'yellow_cards' => $values['yellow_cards'],
                    'red_cards' => $values['red_cards'],
                ]
            );
        }

        // Le score à la pause n'est écrit que s'il a réellement été observé
        // dans les événements et qu'il manque encore en base.
        $ht = $stats['ht_goals'];
        if (is_array($ht) && $stored->ht_home_score === null && $stored->ht_away_score === null) {
            $stored->forceFill([
                'ht_home_score' => (int) $ht['home'],
                'ht_away_score' => (int) $ht['away'],
            ])->saveQuietly();
        }

        foreach ($stats['players'] as $player) {
            $side = $player['team'] ?? null;
            if (! isset($teamIds[$side])) {
                continue;
            }

            DB::table('player_match_stats')->updateOrInsert(
                [
                    'match_id' => $stored->id,
                    'provider' => StatsBombConnector::SOURCE,
                    'external_player_id' => $player['external_id'],
                ],
                [
                    'team_id' => $teamIds[$side],
                    'player_name' => $player['name'],
                    'position' => $player['position'] ?? null,
                    'is_starter' => $player['is_starter'],
                    'minutes_played' => $player['minutes_played'],
                    'goals' => $player['goals'],
                    'shots' => $player['shots'],
                    'shots_on_target' => $player['shots_on_target'],
                    'xg' => round((float) $player['xg'], 3),
                    'yellow_cards' => $player['yellow_cards'],
                    'red_cards' => $player['red_cards'],
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
        }

        return true;
    }

    /**
     * Écrit les faits annexes d'un match (statistiques d'équipe, cotes).
     *
     * Une valeur déjà renseignée par un autre fournisseur n'est jamais
     * écrasée : deux sources peuvent mesurer différemment (un tir cadré
     * StatsBomb n'est pas forcément celui de Football-Data.co.uk). Seuls les
     * champs vides sont complétés, et la provenance de la ligne est conservée.
     *
     * @return array{stats: int, odds: int}
     */
    private function persistFacts(FootballMatch $stored, NormalizedMatch $incoming): array
    {
        $statsRows = 0;
        $oddsRows = 0;

        $stats = $incoming->metadata['stats'] ?? null;
        if (is_array($stats)) {
            foreach (['home' => $stored->home_team_id, 'away' => $stored->away_team_id] as $side => $teamId) {
                $values = $stats[$side] ?? null;
                if (! is_array($values) || $values === []) {
                    continue;
                }

                $row = TeamMatchStat::firstOrNew(['match_id' => $stored->id, 'team_id' => $teamId]);
                $sameProvider = $row->provider === $incoming->source;
                $updates = ['is_home' => $side === 'home'];

                foreach (['shots', 'shots_on_target', 'corners', 'fouls', 'offsides', 'yellow_cards', 'red_cards', 'xg'] as $field) {
                    if (! array_key_exists($field, $values) || $values[$field] === null) {
                        continue;
                    }

                    if ($row->{$field} === null || $sameProvider) {
                        $updates[$field] = $values[$field];
                    }
                }

                if (count($updates) <= 1) {
                    continue;
                }

                // La provenance n'est renseignée que sur les faits écrits ici.
                $updates['provider'] = $incoming->source;
                $row->fill($updates)->save();
                $statsRows++;
            }
        }

        $odds = $incoming->metadata['odds'] ?? null;
        if (is_array($odds)) {
            $oddsRows += $this->persistOdds($stored, $incoming, $odds);
        }

        return ['stats' => $statsRows, 'odds' => $oddsRows];
    }

    /**
     * @param  array<string, mixed>  $odds
     */
    private function persistOdds(FootballMatch $stored, NormalizedMatch $incoming, array $odds): int
    {
        $written = 0;
        // Cotes de clôture : horodatées au coup d'envoi, comme publié.
        $closingAt = $incoming->kickoffUtc->utc();

        $bookmakerIds = [];
        $bookmaker = function (string $code) use (&$bookmakerIds): int {
            if (isset($bookmakerIds[$code])) {
                return $bookmakerIds[$code];
            }

            $model = Bookmaker::firstOrCreate(
                ['code' => $code],
                [
                    'name' => ucfirst(str_replace('_', ' ', $code)),
                    'type' => $code === 'market_avg' ? 'high_margin' : ($code === 'betfair_ex' ? 'exchange' : 'low_margin'),
                    'is_active' => true,
                ]
            );

            return $bookmakerIds[$code] = $model->id;
        };

        $market = function (string $code, string $name): int {
            return Market::firstOrCreate(['code' => $code], ['name' => $name, 'category' => 'main'])->id;
        };

        $write = function (int $bookmakerId, int $marketId, string $selection, ?float $line, float $decimalOdds, CarbonImmutable $snapshotAt, bool $isClosing) use ($stored, $incoming, &$written): void {
            $attributes = [
                'match_id' => $stored->id,
                'bookmaker_id' => $bookmakerId,
                'market_id' => $marketId,
                'selection' => $selection,
                'line' => $line,
                'snapshot_time_utc' => $snapshotAt,
                'source' => $incoming->source,
                'is_closing' => $isClosing,
            ];

            $snapshot = OddsSnapshot::firstOrNew($attributes);
            $snapshot->decimal_odds = $decimalOdds;
            $snapshot->is_closing = $isClosing;
            $snapshot->is_opening = false;
            $snapshot->raw_hash = hash('sha256', implode('|', [$stored->id, $bookmakerId, $marketId, $selection, (string) $line, (string) $decimalOdds, $isClosing ? 'closing' : 'upcoming']));
            $snapshot->save();
            $written++;
        };

        // Date de relevé fournie par la source, jamais remplacée par l'heure
        // d'exécution : une cote de vendredi reste datée du vendredi.
        $capturedAt = function (array $entry): CarbonImmutable {
            $value = $entry['captured_at'] ?? null;
            if (is_string($value) && $value !== '') {
                try {
                    return CarbonImmutable::parse($value)->utc();
                } catch (Throwable) {
                    // Valeur illisible : on retombe sur l'heure d'import.
                }
            }

            return CarbonImmutable::now('UTC');
        };

        foreach ((array) ($odds['1x2_closing'] ?? []) as $entry) {
            if (! is_array($entry) || ! isset($entry['bookmaker'])) {
                continue;
            }

            $bookmakerId = $bookmaker((string) $entry['bookmaker']);
            $marketId = $market('1N2', 'Résultat du Match (1N2)');
            foreach (['HOME' => 'home', 'DRAW' => 'draw', 'AWAY' => 'away'] as $selection => $key) {
                if (isset($entry[$key])) {
                    $write($bookmakerId, $marketId, $selection, null, (float) $entry[$key], $closingAt, true);
                }
            }
        }

        foreach ((array) ($odds['totals_2_5_closing'] ?? []) as $entry) {
            if (! is_array($entry) || ! isset($entry['bookmaker'])) {
                continue;
            }

            $bookmakerId = $bookmaker((string) $entry['bookmaker']);
            $marketId = $market('totals', 'Total de Buts (Plus / Moins)');
            if (isset($entry['over'])) {
                $write($bookmakerId, $marketId, 'OVER', 2.5, (float) $entry['over'], $closingAt, true);
            }
            if (isset($entry['under'])) {
                $write($bookmakerId, $marketId, 'UNDER', 2.5, (float) $entry['under'], $closingAt, true);
            }
        }

        // Cotes des matchs à venir : prix du marché au moment du relevé, donc
        // non-closing. Ce sont elles que `latestComplete1N2Set` retient pour le
        // blend marché des pronostics.
        foreach ((array) ($odds['1x2_upcoming'] ?? []) as $entry) {
            if (! is_array($entry) || ! isset($entry['bookmaker'])) {
                continue;
            }

            $bookmakerId = $bookmaker((string) $entry['bookmaker']);
            $marketId = $market('1N2', 'Résultat du Match (1N2)');
            $snapshotAt = $capturedAt($entry);
            foreach (['HOME' => 'home', 'DRAW' => 'draw', 'AWAY' => 'away'] as $selection => $key) {
                if (isset($entry[$key])) {
                    $write($bookmakerId, $marketId, $selection, null, (float) $entry[$key], $snapshotAt, false);
                }
            }
        }

        foreach ((array) ($odds['totals_2_5_upcoming'] ?? []) as $entry) {
            if (! is_array($entry) || ! isset($entry['bookmaker'])) {
                continue;
            }

            $bookmakerId = $bookmaker((string) $entry['bookmaker']);
            $marketId = $market('totals', 'Total de Buts (Plus / Moins)');
            $snapshotAt = $capturedAt($entry);
            if (isset($entry['over'])) {
                $write($bookmakerId, $marketId, 'OVER', 2.5, (float) $entry['over'], $snapshotAt, false);
            }
            if (isset($entry['under'])) {
                $write($bookmakerId, $marketId, 'UNDER', 2.5, (float) $entry['under'], $snapshotAt, false);
            }
        }

        return $written;
    }

    private function resolveLeague(NormalizedMatch $match): League
    {
        $league = League::firstOrCreate(
            ['code' => $match->leagueCode],
            [
                'name' => $match->leagueName,
                'country' => $match->country,
                'tier' => '1',
                'is_active' => true,
            ]
        );

        return $league;
    }

    private function resolveSeason(League $league, NormalizedMatch $match): Season
    {
        return Season::firstOrCreate(
            ['league_id' => $league->id, 'name' => $match->seasonName],
            [
                'year_start' => $match->seasonYearStart,
                'year_end' => $match->seasonYearEnd,
                'is_current' => false,
            ]
        );
    }

    private function findExisting(
        NormalizedMatch $match,
        League $league,
        Team $homeTeam,
        Team $awayTeam
    ): ?FootballMatch {
        $mapped = ProviderEntityMap::where([
            'entity_type' => 'match',
            'provider' => $match->source,
            'external_id' => $match->externalId,
        ])->first();

        if ($mapped && ($found = FootballMatch::find($mapped->internal_id))) {
            return $found;
        }

        $query = FootballMatch::where('league_id', $league->id)
            ->where('home_team_id', $homeTeam->id)
            ->where('away_team_id', $awayTeam->id);

        if ($match->kickoffTimeKnown) {
            $query->whereBetween('match_date_utc', [
                $match->kickoffUtc->subHours(6),
                $match->kickoffUtc->addHours(6),
            ]);
        } else {
            // Heure inconnue : rapprochement sur la journée entière, jamais sur
            // une heure inventée.
            $query->whereBetween('match_date_utc', [
                $match->kickoffUtc->startOfDay(),
                $match->kickoffUtc->endOfDay(),
            ]);
        }

        return $query->orderByRaw('CASE WHEN external_id IS NULL THEN 1 ELSE 0 END')->first();
    }

    private function diverges(FootballMatch $existing, NormalizedMatch $incoming): bool
    {
        if (! $incoming->hasScore() || $existing->home_score === null || $existing->away_score === null) {
            return false;
        }

        if ($existing->data_source === $incoming->source) {
            return false;
        }

        return (int) $existing->home_score !== (int) $incoming->homeScore
            || (int) $existing->away_score !== (int) $incoming->awayScore;
    }
}
