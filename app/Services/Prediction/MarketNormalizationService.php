<?php

namespace App\Services\Prediction;

class MarketNormalizationService
{
    /**
     * Alias de normalisation entre les identifiants Python et Laravel.
     */
    public const MARKET_ALIASES = [
        // Résultat
        '1x2' => '1N2',
        '1N2' => '1N2',
        'match_result' => '1N2',
        // Totaux
        'total_goals' => 'totals',
        'totals' => 'totals',
        // Draw No Bet
        'draw_no_bet' => 'dnb',
        'dnb' => 'dnb',
        // Totaux équipe
        'team_total_goals' => 'team_totals',
        'team_totals' => 'team_totals',
        // Mi-temps
        'ht_1x2' => 'half_time_result',
        'half_time_result' => 'half_time_result',
        'ht_total_goals' => 'half_time_totals',
        'half_time_totals' => 'half_time_totals',
        'ht_double_chance' => 'half_time_double_chance',
        // Corners
        'total_corners' => 'corners_total',
        'corners_total' => 'corners_total',
        'team_total_corners' => 'team_corners_total',
        // Cartons
        'total_yellow_cards' => 'cards_yellow_total',
        'total_red_cards' => 'cards_red_total',
        'total_cards' => 'cards_total',
        'cards_total' => 'cards_total',
        'team_total_cards' => 'team_cards_total',
        // Tirs
        'total_shots' => 'shots_total',
        'shots_total' => 'shots_total',
        'total_shots_on_target' => 'shots_on_target_total',
    ];

    /**
     * Familles de marchés considérées comme discipline/cartons.
     */
    public const CARDS_FAMILIES = [
        'cards',
        'discipline',
        'yellow_cards',
        'red_cards',
    ];

    public const CARDS_MARKET_IDS = [
        'total_yellow_cards',
        'total_red_cards',
        'total_cards',
        'cards_total',
        'team_total_cards',
        'cards_handicap',
        'first_team_carded',
        'last_team_carded',
        'player_carded',
        'player_sent_off',
    ];

    /**
     * Normalise un identifiant de marché vers la forme canonique Laravel.
     */
    public static function normalizeMarketId(string $marketId): string
    {
        $cleaned = strtolower(trim($marketId));

        return self::MARKET_ALIASES[$cleaned] ?? self::MARKET_ALIASES[$marketId] ?? $marketId;
    }

    /**
     * Vérifie si un marché ou une famille correspond à des cartons / de la discipline.
     */
    public static function isCardsMarket(?string $marketId, ?string $family = null): bool
    {
        if ($family !== null && in_array(strtolower($family), self::CARDS_FAMILIES, true)) {
            return true;
        }

        if ($marketId !== null) {
            $m = strtolower(trim($marketId));
            if (in_array($m, self::CARDS_MARKET_IDS, true)) {
                return true;
            }
            if (str_contains($m, 'card') || str_contains($m, 'carton')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Retourne le nom d'affichage lisible d'un marché.
     */
    public static function getMarketDisplayName(string $marketId): string
    {
        $canonical = self::normalizeMarketId($marketId);

        return match ($canonical) {
            '1N2' => 'Résultat du match (1N2)',
            'double_chance' => 'Double chance',
            'totals' => 'Total de buts',
            'btts' => 'Les deux équipes marquent (BTTS)',
            'dnb' => 'Remboursé si nul (Draw No Bet)',
            'team_totals' => 'Total de buts par équipe',
            'half_time_result' => 'Résultat à la mi-temps',
            'half_time_totals' => 'Total de buts en 1ère mi-temps',
            'half_time_double_chance' => 'Double chance mi-temps',
            'clean_sheet' => 'Clean sheet',
            'win_to_nil' => 'Victoire sans encaisser',
            'asian_handicap' => 'Handicap asiatique',
            'european_handicap' => 'Handicap européen',
            'corners_total' => 'Total de corners',
            'cards_total', 'cards_yellow_total' => 'Total de cartons',
            'shots_total' => 'Total de tirs',
            'shots_on_target_total' => 'Tirs cadrés',
            'both_halves_over_0_5' => 'But dans les deux mi-temps',
            'highest_scoring_half' => 'Mi-temps avec le plus de buts',
            default => ucfirst(str_replace('_', ' ', $marketId)),
        };
    }

    /**
     * Génère le libellé utilisateur clair d'une sélection.
     */
    public static function getSelectionLabel(
        string $market,
        string $selection,
        string $home = 'Domicile',
        string $away = 'Extérieur',
        ?float $line = null
    ): ?string {
        $canonical = self::normalizeMarketId($market);

        return match ($canonical) {
            '1N2' => match ($selection) {
                'home' => 'Victoire '.$home,
                'draw' => 'Match nul',
                'away' => 'Victoire '.$away,
                default => null,
            },
            'double_chance' => match ($selection) {
                '1X' => $home.' ou nul',
                'X2' => $away.' ou nul',
                '12' => 'Pas de match nul',
                default => null,
            },
            'btts' => match ($selection) {
                'yes' => 'Les deux équipes marquent',
                'no' => 'Au moins une équipe ne marque pas',
                default => null,
            },
            'dnb' => match ($selection) {
                'home' => 'Victoire '.$home.' (Remboursé si nul)',
                'away' => 'Victoire '.$away.' (Remboursé si nul)',
                default => null,
            },
            'totals' => self::formatTotalsLabel($selection, $line),
            'team_totals' => self::formatTeamTotalsLabel($selection, $home, $away),
            'half_time_result' => match ($selection) {
                'home' => $home.' mène à la mi-temps',
                'draw' => 'Match nul à la mi-temps',
                'away' => $away.' mène à la mi-temps',
                default => null,
            },
            'half_time_totals' => self::formatHalfTimeTotalsLabel($selection, $line),
            'half_time_double_chance' => match ($selection) {
                '1X' => $home.' ou nul à la mi-temps',
                'X2' => $away.' ou nul à la mi-temps',
                '12' => 'Pas de nul à la mi-temps',
                default => null,
            },
            'clean_sheet' => match ($selection) {
                'home' => $home.' garde sa cage inviolée',
                'away' => $away.' garde sa cage inviolée',
                default => null,
            },
            'win_to_nil' => match ($selection) {
                'home' => $home.' gagne sans encaisser',
                'away' => $away.' gagne sans encaisser',
                default => null,
            },
            'corners_total' => self::formatCountLabel('corners', $selection, $line),
            'cards_total', 'cards_yellow_total' => self::formatCountLabel('cartons', $selection, $line),
            'shots_total' => self::formatCountLabel('tirs', $selection, $line),
            'shots_on_target_total' => self::formatCountLabel('tirs cadrés', $selection, $line),
            'both_halves_over_0_5' => match ($selection) {
                'yes' => 'Au moins un but dans chaque mi-temps',
                'no' => 'Au moins une mi-temps sans but',
                default => null,
            },
            'highest_scoring_half' => match ($selection) {
                'first' => 'Plus de buts en 1ère mi-temps',
                'second' => 'Plus de buts en 2nde mi-temps',
                'equal' => 'Même nombre de buts dans les deux mi-temps',
                default => null,
            },
            default => null,
        };
    }

    private static function formatTotalsLabel(string $selection, ?float $line): ?string
    {
        if (preg_match('/^(over|under)_(\d+)_(\d+)$/', $selection, $m)) {
            $prefix = $m[1] === 'over' ? 'Plus de ' : 'Moins de ';

            return $prefix.$m[2].','.$m[3].' buts';
        }
        if (preg_match('/^(over|under)_(\d+(?:\.\d+)?)$/', $selection, $m)) {
            $prefix = $m[1] === 'over' ? 'Plus de ' : 'Moins de ';

            return $prefix.str_replace('.', ',', $m[2]).' buts';
        }
        if ($line !== null && in_array($selection, ['over', 'under'], true)) {
            $prefix = $selection === 'over' ? 'Plus de ' : 'Moins de ';

            return $prefix.str_replace('.', ',', (string) $line).' buts';
        }

        return null;
    }

    private static function formatHalfTimeTotalsLabel(string $selection, ?float $line): ?string
    {
        $base = self::formatTotalsLabel($selection, $line);

        return $base ? $base.' en 1ère mi-temps' : null;
    }

    private static function formatTeamTotalsLabel(string $selection, string $home, string $away): ?string
    {
        if (preg_match('/^(HOME|AWAY|home|away)_(OVER|UNDER|over|under)_(\d+(?:\.\d+)?)$/i', $selection, $parts)) {
            $team = strtoupper($parts[1]) === 'HOME' ? $home : $away;
            $prefix = strtoupper($parts[2]) === 'OVER' ? 'Plus de ' : 'Moins de ';

            return $prefix.str_replace('.', ',', $parts[3]).' buts — '.$team;
        }

        return null;
    }

    private static function formatCountLabel(string $item, string $selection, ?float $line): ?string
    {
        if (preg_match('/^(over|under)_(\d+(?:\.\d+)?)$/', $selection, $m)) {
            $prefix = $m[1] === 'over' ? 'Plus de ' : 'Moins de ';

            return $prefix.str_replace('.', ',', $m[2]).' '.$item;
        }
        if ($line !== null && in_array($selection, ['over', 'under'], true)) {
            $prefix = $selection === 'over' ? 'Plus de ' : 'Moins de ';

            return $prefix.str_replace('.', ',', (string) $line).' '.$item;
        }

        return null;
    }
}
