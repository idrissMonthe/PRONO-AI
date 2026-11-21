<?php

namespace App\Services\Deduplication;

use App\Models\FootballMatch;
use App\Models\Team;
use App\Services\Integrity\HashChainService;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Fusionne des identités d'équipe en doublon puis les rencontres que cette
 * fusion rend identiques (même ligue, même jour, mêmes équipes).
 *
 * Toutes les lignes filles sont déplacées avant la suppression : cotes,
 * prédictions, évaluations, snapshots, statistiques d'équipe et de joueurs,
 * disponibilités, jambes de combinés, prévisions et références de paris.
 * La chaîne d'intégrité n'est reconstruite que si une prédiction change de
 * match, car c'est le seul champ haché qui peut être modifié ici.
 */
class TeamAndMatchMergeService
{
    private const SOURCE_PRIORITY = [
        'football-data.org' => 5,
        'statsbomb' => 4,
        'manual' => 3,
        'football-data-uk' => 2,
        'api-football' => 1,
    ];

    /**
     * Tables portant un match_id à déplacer telles quelles (hors
     * team_match_stats, dont l'unicité (match_id, team_id) doit être gérée).
     */
    private const MATCH_CHILD_TABLES = [
        'odds_snapshots',
        'predictions',
        'paper_bets',
        'prediction_evaluations',
        'prediction_snapshots',
        'player_availabilities',
        'player_match_stats',
        'combination_snapshot_legs',
        'forecast_runs',
    ];

    /**
     * @param  array<int,int>  $teamMap  duplicate team id => canonical team id
     * @return array<string,int|bool>
     */
    public function merge(array $teamMap, bool $dryRun = false): array
    {
        $teamMap = $this->normalizeTeamMap($teamMap);

        if ($dryRun) {
            return $this->plan($teamMap);
        }

        return DB::transaction(fn (): array => $this->execute($teamMap));
    }

    /**
     * @param  array<int,int>  $teamMap
     * @return array<int,int>
     */
    private function normalizeTeamMap(array $teamMap): array
    {
        $normalized = [];

        foreach ($teamMap as $duplicateId => $canonicalId) {
            $duplicateId = (int) $duplicateId;
            $canonicalId = (int) $canonicalId;

            if ($duplicateId === $canonicalId) {
                continue;
            }

            // Aplatit les chaînes éventuelles A -> B -> C.
            $guard = 0;
            while (isset($teamMap[$canonicalId]) && (int) $teamMap[$canonicalId] !== $canonicalId && $guard++ < 10) {
                $canonicalId = (int) $teamMap[$canonicalId];
            }

            if ($duplicateId === $canonicalId) {
                continue;
            }

            if (! Team::whereKey($duplicateId)->exists() || ! Team::whereKey($canonicalId)->exists()) {
                continue;
            }

            $normalized[$duplicateId] = $canonicalId;
        }

        return $normalized;
    }

    /**
     * @param  array<int,int>  $teamMap
     * @return array<string,int|bool>
     */
    private function plan(array $teamMap): array
    {
        $stats = $this->emptyStats();
        $stats['teams_merged'] = count($teamMap);

        foreach ($teamMap as $duplicateId => $canonicalId) {
            $stats['aliases_moved'] += DB::table('team_aliases')->where('team_id', $duplicateId)->count();
            $stats['ratings_moved'] += DB::table('team_ratings')->where('team_id', $duplicateId)->count();
            $stats['team_stats_moved'] += DB::table('team_match_stats')->where('team_id', $duplicateId)->count();
            $stats['player_stats_moved'] += DB::table('player_match_stats')->where('team_id', $duplicateId)->count();
        }

        $matches = DB::table('matches')->get([
            'id', 'league_id', 'home_team_id', 'away_team_id', 'match_date_utc',
            'data_source', 'status', 'last_synced_at',
        ]);

        foreach ($this->groupMatches($matches, $teamMap) as $group) {
            foreach ($this->sortMatches($group)->skip(1) as $duplicate) {
                $stats['matches_merged']++;
                $stats['odds_moved'] += DB::table('odds_snapshots')->where('match_id', $duplicate->id)->count();
                $stats['predictions_moved'] += DB::table('predictions')->where('match_id', $duplicate->id)->count();
                $stats['team_stats_moved'] += DB::table('team_match_stats')->where('match_id', $duplicate->id)->count();

                foreach (self::MATCH_CHILD_TABLES as $table) {
                    if ($table === 'odds_snapshots' || $table === 'predictions') {
                        continue;
                    }
                    $stats['other_rows_moved'] += DB::table($table)->where('match_id', $duplicate->id)->count();
                }
            }
        }

        $stats['parlays_updated'] = $this->countParlaysReferencing(array_keys($this->matchMapFromGroups($matches, $teamMap)));

        return $stats;
    }

    /**
     * @param  array<int,int>  $teamMap
     * @return array<string,int|bool>
     */
    private function execute(array $teamMap): array
    {
        $stats = $this->emptyStats();

        foreach ($teamMap as $duplicateId => $canonicalId) {
            $this->mergeTeam($duplicateId, $canonicalId, $stats);
        }

        // Les matchs sont relus après la fusion des équipes : les identifiants
        // d'équipe sont désormais canoniques.
        $matches = DB::table('matches')->get([
            'id', 'league_id', 'home_team_id', 'away_team_id', 'match_date_utc',
            'data_source', 'status', 'last_synced_at',
        ]);

        $matchMap = [];

        foreach ($this->groupMatches($matches) as $group) {
            $sorted = $this->sortMatches($group);
            $canonical = $sorted->first();

            foreach ($sorted->skip(1) as $duplicate) {
                $this->mergeMatch($duplicate, $canonical, $stats);
                $matchMap[(int) $duplicate->id] = (int) $canonical->id;
            }
        }

        if ($matchMap !== []) {
            $stats['parlays_updated'] = $this->remapParlays($matchMap);
        }

        $stats['officials_demoted'] = $this->normalizeOfficialPredictions();

        if ($stats['predictions_moved'] > 0) {
            $result = app(HashChainService::class)->rebuildLedger();
            $stats['ledger_rebuilt'] = true;
            $stats['ledger_blocks'] = $result['chained'];
        }

        return $stats;
    }

    /**
     * @param  array<string,int|bool>  $stats
     */
    private function mergeTeam(int $duplicateId, int $canonicalId, array &$stats): void
    {
        DB::table('matches')->where('home_team_id', $duplicateId)->update(['home_team_id' => $canonicalId]);
        DB::table('matches')->where('away_team_id', $duplicateId)->update(['away_team_id' => $canonicalId]);

        foreach (DB::table('team_aliases')->where('team_id', $duplicateId)->get(['id', 'alias', 'source']) as $alias) {
            $exists = DB::table('team_aliases')
                ->where('team_id', $canonicalId)
                ->where('alias', $alias->alias)
                ->where('source', $alias->source)
                ->exists();

            if ($exists) {
                DB::table('team_aliases')->where('id', $alias->id)->delete();
                $stats['aliases_dropped']++;
            } else {
                DB::table('team_aliases')->where('id', $alias->id)->update(['team_id' => $canonicalId]);
                $stats['aliases_moved']++;
            }
        }

        foreach (DB::table('team_ratings')->where('team_id', $duplicateId)->get(['id', 'as_of_date']) as $rating) {
            $exists = DB::table('team_ratings')
                ->where('team_id', $canonicalId)
                ->where('as_of_date', $rating->as_of_date)
                ->exists();

            if ($exists) {
                DB::table('team_ratings')->where('id', $rating->id)->delete();
                $stats['ratings_dropped']++;
            } else {
                DB::table('team_ratings')->where('id', $rating->id)->update(['team_id' => $canonicalId]);
                $stats['ratings_moved']++;
            }
        }

        foreach (DB::table('team_match_stats')->where('team_id', $duplicateId)->get(['id', 'match_id']) as $stat) {
            $exists = DB::table('team_match_stats')
                ->where('match_id', $stat->match_id)
                ->where('team_id', $canonicalId)
                ->exists();

            if ($exists) {
                DB::table('team_match_stats')->where('id', $stat->id)->delete();
                $stats['team_stats_dropped']++;
            } else {
                DB::table('team_match_stats')->where('id', $stat->id)->update(['team_id' => $canonicalId]);
                $stats['team_stats_moved']++;
            }
        }

        $stats['player_stats_moved'] += DB::table('player_match_stats')->where('team_id', $duplicateId)->update(['team_id' => $canonicalId]);
        DB::table('player_availabilities')->where('team_id', $duplicateId)->update(['team_id' => $canonicalId]);

        DB::table('provider_entity_maps')
            ->where('entity_type', 'team')
            ->where('internal_id', $duplicateId)
            ->update(['internal_id' => $canonicalId]);

        DB::table('teams')->where('id', $duplicateId)->delete();
        $stats['teams_merged']++;
    }

    /**
     * @param  array<string,int|bool>  $stats
     */
    private function mergeMatch(object $duplicate, object $canonical, array &$stats): void
    {
        foreach (self::MATCH_CHILD_TABLES as $table) {
            $count = DB::table($table)->where('match_id', $duplicate->id)->count();
            if ($count === 0) {
                continue;
            }

            DB::table($table)->where('match_id', $duplicate->id)->update(['match_id' => $canonical->id]);

            if ($table === 'odds_snapshots') {
                $stats['odds_moved'] += $count;
            } elseif ($table === 'predictions') {
                $stats['predictions_moved'] += $count;
            } else {
                $stats['other_rows_moved'] += $count;
            }
        }

        foreach (DB::table('team_match_stats')->where('match_id', $duplicate->id)->get(['id', 'team_id']) as $stat) {
            $exists = DB::table('team_match_stats')
                ->where('match_id', $canonical->id)
                ->where('team_id', $stat->team_id)
                ->exists();

            if ($exists) {
                DB::table('team_match_stats')->where('id', $stat->id)->delete();
                $stats['team_stats_dropped']++;
            } else {
                DB::table('team_match_stats')->where('id', $stat->id)->update(['match_id' => $canonical->id]);
                $stats['team_stats_moved']++;
            }
        }

        DB::table('provider_entity_maps')
            ->where('entity_type', 'match')
            ->where('internal_id', $duplicate->id)
            ->update(['internal_id' => $canonical->id]);

        DB::table('matches')->where('id', $duplicate->id)->delete();
        $stats['matches_merged']++;
    }

    /**
     * @param  Collection<int,object>  $matches
     * @param  array<int,int>  $teamMap
     * @return Collection<string,Collection<int,object>>
     */
    private function groupMatches(Collection $matches, array $teamMap = []): Collection
    {
        return $matches
            ->groupBy(function (object $match) use ($teamMap): string {
                $home = $teamMap[$match->home_team_id] ?? $match->home_team_id;
                $away = $teamMap[$match->away_team_id] ?? $match->away_team_id;
                $date = Carbon::parse($match->match_date_utc)->toDateString();

                return $match->league_id.'|'.$date.'|'.$home.'|'.$away;
            })
            ->filter(fn (Collection $group): bool => $group->count() > 1);
    }

    /**
     * @param  Collection<int,object>  $matches
     * @return Collection<int,object>
     */
    private function sortMatches(Collection $matches): Collection
    {
        return $matches->sort(function (object $a, object $b): int {
            $priorityA = self::SOURCE_PRIORITY[$a->data_source] ?? 0;
            $priorityB = self::SOURCE_PRIORITY[$b->data_source] ?? 0;
            if ($priorityA !== $priorityB) {
                return $priorityB <=> $priorityA;
            }

            $finishedA = $a->status === 'FINISHED' ? 1 : 0;
            $finishedB = $b->status === 'FINISHED' ? 1 : 0;
            if ($finishedA !== $finishedB) {
                return $finishedB <=> $finishedA;
            }

            $syncedA = $a->last_synced_at ? Carbon::parse($a->last_synced_at)->getTimestamp() : 0;
            $syncedB = $b->last_synced_at ? Carbon::parse($b->last_synced_at)->getTimestamp() : 0;
            if ($syncedA !== $syncedB) {
                return $syncedB <=> $syncedA;
            }

            return $a->id <=> $b->id;
        })->values();
    }

    /**
     * @param  array<int,int>  $matchMap
     */
    private function remapParlays(array $matchMap): int
    {
        $updated = 0;

        foreach (DB::table('parlays')->get(['id', 'selections_json']) as $parlay) {
            $selections = json_decode((string) $parlay->selections_json, true);
            if (! is_array($selections)) {
                continue;
            }

            $changed = false;
            foreach ($selections as &$selection) {
                if (! is_array($selection)) {
                    continue;
                }

                $matchId = $selection['match_id'] ?? null;
                if ($matchId !== null && isset($matchMap[(int) $matchId])) {
                    $selection['match_id'] = $matchMap[(int) $matchId];
                    $changed = true;
                }
            }
            unset($selection);

            if ($changed) {
                DB::table('parlays')->where('id', $parlay->id)->update([
                    'selections_json' => json_encode($selections, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ]);
                $updated++;
            }
        }

        return $updated;
    }

    /**
     * @param  array<int,int>  $matchIds
     */
    private function countParlaysReferencing(array $matchIds): int
    {
        if ($matchIds === []) {
            return 0;
        }

        $count = 0;
        foreach (DB::table('parlays')->get(['selections_json']) as $parlay) {
            $selections = json_decode((string) $parlay->selections_json, true);
            if (! is_array($selections)) {
                continue;
            }

            foreach ($selections as $selection) {
                if (is_array($selection) && in_array((int) ($selection['match_id'] ?? 0), $matchIds, true)) {
                    $count++;
                    break;
                }
            }
        }

        return $count;
    }

    /**
     * @param  Collection<int,object>  $matches
     * @param  array<int,int>  $teamMap
     * @return array<int,int>
     */
    private function matchMapFromGroups(Collection $matches, array $teamMap): array
    {
        $map = [];

        foreach ($this->groupMatches($matches, $teamMap) as $group) {
            $sorted = $this->sortMatches($group);
            $canonical = $sorted->first();
            foreach ($sorted->skip(1) as $duplicate) {
                $map[(int) $duplicate->id] = (int) $canonical->id;
            }
        }

        return $map;
    }

    private function normalizeOfficialPredictions(): int
    {
        $matchIds = DB::table('predictions')
            ->select('match_id')
            ->where('is_official', true)
            ->groupBy('match_id')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('match_id');

        $demoted = 0;

        foreach ($matchIds as $matchId) {
            $ids = DB::table('predictions')
                ->where('match_id', $matchId)
                ->where('is_official', true)
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->pluck('id');

            $ids->shift();

            if ($ids->isNotEmpty()) {
                DB::table('predictions')->whereIn('id', $ids->all())->update(['is_official' => false]);
                $demoted += $ids->count();
            }
        }

        return $demoted;
    }

    /**
     * @return array<string,int|bool>
     */
    private function emptyStats(): array
    {
        return [
            'teams_merged' => 0,
            'matches_merged' => 0,
            'odds_moved' => 0,
            'predictions_moved' => 0,
            'team_stats_moved' => 0,
            'team_stats_dropped' => 0,
            'aliases_moved' => 0,
            'aliases_dropped' => 0,
            'ratings_moved' => 0,
            'ratings_dropped' => 0,
            'player_stats_moved' => 0,
            'other_rows_moved' => 0,
            'parlays_updated' => 0,
            'officials_demoted' => 0,
            'ledger_rebuilt' => false,
            'ledger_blocks' => 0,
        ];
    }
}
