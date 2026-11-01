<?php

namespace App\Services\Odds;

use App\Models\FootballMatch;
use App\Models\Market;
use App\Models\OddsSnapshot;
use App\Models\PaperBet;
use Illuminate\Support\Collection;

class OddsMovementService
{
    public function __construct(
        private readonly OddsService $oddsService
    ) {}

    public function refreshMarkersAndPaperBetClv(): array
    {
        $stats = ['opening_snapshots' => 0, 'closing_snapshots' => 0, 'paper_bets_updated' => 0];
        $now = now()->utc();
        $marketId = Market::where('code', '1N2')->value('id');
        if (! $marketId) {
            return $stats;
        }

        FootballMatch::with(['oddsSnapshots' => fn ($query) => $query->where('market_id', $marketId)])
            ->whereHas('oddsSnapshots', fn ($query) => $query->where('market_id', $marketId))
            ->orderBy('id')
            ->chunkById(100, function (Collection $matches) use (&$stats, $now): void {
                foreach ($matches as $match) {
                    $groups = $match->oddsSnapshots->groupBy('bookmaker_id');

                    foreach ($groups as $snapshots) {
                        $completeSets = $snapshots
                            ->groupBy(fn (OddsSnapshot $snapshot): int => $snapshot->snapshot_time_utc->timestamp)
                            ->map(function (Collection $set): ?array {
                                $bySelection = $set->keyBy('selection');
                                if (! collect(['HOME', 'DRAW', 'AWAY'])->every(fn (string $selection): bool => $bySelection->has($selection))) {
                                    return null;
                                }

                                return [
                                    'timestamp' => $set->first()->snapshot_time_utc,
                                    'snapshots' => $bySelection,
                                ];
                            })
                            ->filter()
                            ->sortBy('timestamp')
                            ->values();
                        $opening = $completeSets->first();
                        $closing = null;
                        if (
                            $opening
                            &&
                            $match->match_date_utc->lessThanOrEqualTo($now)
                            && ! in_array($match->status, ['POSTPONED', 'CANCELLED'], true)
                        ) {
                            $closing = $completeSets
                                ->filter(fn (array $set): bool => $set['timestamp']->lessThanOrEqualTo($match->match_date_utc))
                                ->last();
                        }

                        $openingIds = $opening ? $opening['snapshots']->pluck('id')->all() : [];
                        $closingIds = $closing ? $closing['snapshots']->pluck('id')->all() : [];
                        foreach ($snapshots as $snapshot) {
                            $isOpening = in_array($snapshot->id, $openingIds, true);
                            $isClosing = in_array($snapshot->id, $closingIds, true);
                            if ($snapshot->is_opening !== $isOpening || $snapshot->is_closing !== $isClosing) {
                                $snapshot->forceFill([
                                    'is_opening' => $isOpening,
                                    'is_closing' => $isClosing,
                                ])->save();
                                $stats['opening_snapshots'] += $isOpening ? 1 : 0;
                                $stats['closing_snapshots'] += $isClosing ? 1 : 0;
                            }
                        }
                    }
                }
            });

        $closingMarketCache = [];
        PaperBet::with([
            'oddsSnapshot',
            'match.oddsSnapshots' => fn ($query) => $query->where('market_id', $marketId),
        ])
            ->whereNull('closing_odds')
            ->whereHas('oddsSnapshot', fn ($query) => $query->where('market_id', $marketId))
            ->whereHas('match', fn ($query) => $query
                ->where('match_date_utc', '<=', $now)
                ->whereNotIn('status', ['POSTPONED', 'CANCELLED']))
            ->orderBy('id')
            ->chunkById(100, function (Collection $bets) use (&$stats, &$closingMarketCache): void {
                foreach ($bets as $bet) {
                    $placedSnapshot = $bet->oddsSnapshot;
                    if (! $placedSnapshot) {
                        continue;
                    }

                    $cacheKey = implode(':', [$bet->match_id, $placedSnapshot->bookmaker_id, $placedSnapshot->market_id]);
                    if (! array_key_exists($cacheKey, $closingMarketCache)) {
                        $closing = $bet->match->oddsSnapshots
                            ->where('bookmaker_id', $placedSnapshot->bookmaker_id)
                            ->where('is_closing', true)
                            ->keyBy('selection');
                        if (! collect(['HOME', 'DRAW', 'AWAY'])->every(fn (string $selection): bool => $closing->has($selection))) {
                            continue;
                        }

                        $closingOdds = collect(['HOME', 'DRAW', 'AWAY'])
                            ->map(fn (string $selection): float => (float) $closing[$selection]->decimal_odds)
                            ->all();
                        if (min($closingOdds) <= 1.0) {
                            continue;
                        }

                        $devig = $this->oddsService->removeMargin($closingOdds);
                        $closingMarketCache[$cacheKey] = [
                            'odds' => array_combine(['HOME', 'DRAW', 'AWAY'], $closingOdds),
                            'probabilities' => array_combine(['HOME', 'DRAW', 'AWAY'], $devig['true_probabilities']),
                        ];
                    }

                    $closingMarket = $closingMarketCache[$cacheKey];
                    $fairProbability = (float) ($closingMarket['probabilities'][$bet->selection] ?? 0.0);
                    if ($fairProbability <= 0.0) {
                        continue;
                    }

                    $bet->forceFill([
                        'closing_odds' => $closingMarket['odds'][$bet->selection],
                        'clv' => $this->oddsService->calculateClv(
                            (float) $bet->placed_odds,
                            1.0 / $fairProbability
                        ),
                    ])->save();
                    $stats['paper_bets_updated']++;
                }
            });

        return $stats;
    }
}
