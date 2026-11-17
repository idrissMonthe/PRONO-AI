<?php

namespace App\Services\Import;

use Carbon\CarbonImmutable;

/**
 * Représentation commune d'une rencontre, quel que soit le fournisseur.
 *
 * Aucune valeur n'est inventée : `kickoffTimeKnown` indique si l'heure de coup
 * d'envoi provient réellement de la source. Quand elle manque, la date est
 * stockée à 00:00 UTC et la déduplication se fait sur la journée entière.
 */
final class NormalizedMatch
{
    public function __construct(
        public readonly string $source,
        public readonly string $externalId,
        public readonly string $leagueCode,
        public readonly string $leagueName,
        public readonly string $country,
        public readonly string $seasonName,
        public readonly int $seasonYearStart,
        public readonly int $seasonYearEnd,
        public readonly CarbonImmutable $kickoffUtc,
        public readonly bool $kickoffTimeKnown,
        public readonly ?string $homeTeam,
        public readonly ?string $awayTeam,
        public readonly ?int $homeScore,
        public readonly ?int $awayScore,
        public readonly ?int $htHomeScore = null,
        public readonly ?int $htAwayScore = null,
        public readonly bool $isNeutralGround = false,
        public readonly ?string $round = null,
        public readonly array $metadata = [],
    ) {
    }

    public function status(): string
    {
        return $this->homeScore !== null && $this->awayScore !== null ? 'FINISHED' : 'SCHEDULED';
    }

    /**
     * Charge utile acceptée par FootballDataService::ingestMatchRecord().
     */
    public function toIngestRecord(): array
    {
        return [
            'external_id' => $this->externalId,
            'league_code' => $this->leagueCode,
            'league_name' => $this->leagueName,
            'country' => $this->country,
            'season' => $this->seasonName,
            'season_year_start' => $this->seasonYearStart,
            'season_year_end' => $this->seasonYearEnd,
            'home_team' => $this->homeTeam,
            'away_team' => $this->awayTeam,
            'date' => $this->kickoffUtc->toIso8601String(),
            'status' => $this->status(),
            'home_score' => $this->homeScore,
            'away_score' => $this->awayScore,
            'ht_home_score' => $this->htHomeScore,
            'ht_away_score' => $this->htAwayScore,
            'is_neutral_ground' => $this->isNeutralGround,
            'round' => $this->round,
        ];
    }

    public function hasScore(): bool
    {
        return $this->homeScore !== null && $this->awayScore !== null;
    }
}
