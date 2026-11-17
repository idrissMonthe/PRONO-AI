<?php

namespace App\Services\Import;

use App\Models\FootballMatch;
use App\Models\ImportRun;
use App\Models\League;
use App\Models\ProviderEntityMap;
use App\Models\Season;
use App\Models\Team;
use App\Models\TeamMatchStat;
use App\Services\FootballData\FootballDataService;
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

                $persisted = $this->footballData->ingestMatchRecord($record, $match->source);

                if (! $persisted) {
                    $counters['rejected']++;
                    $counters['metadata']['errors'][] = [
                        'external_id' => $match->externalId,
                        'reason' => 'ingestion_rejected',
                    ];

                    continue;
                }

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
     * Importe les statistiques de tirs/xG d'un match StatsBomb déjà stocké.
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

        $stats = $connector->shotStats($match);
        if ($stats['home'] === null && $stats['away'] === null) {
            return false;
        }

        if ($dryRun) {
            return true;
        }

        foreach (['home' => $stored->home_team_id, 'away' => $stored->away_team_id] as $side => $teamId) {
            $values = $stats[$side];
            if ($values === null) {
                continue;
            }

            TeamMatchStat::updateOrCreate(
                ['match_id' => $stored->id, 'team_id' => $teamId],
                [
                    'is_home' => $side === 'home',
                    'shots' => $values['shots'],
                    'shots_on_target' => $values['shots_on_target'],
                    'xg' => round($values['xg'], 2),
                ]
            );
        }

        return true;
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
