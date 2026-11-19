<?php

namespace App\Services\Parlay;

/**
 * Sélecteur de diversité unique des combinés.
 *
 * Le dashboard et /parlays construisent des sélections différentes (marchés
 * calculés du modèle d'un côté, alternatives revues de l'autre) mais doivent
 * appliquer les mêmes plafonds. Ils passent tous les deux par cette classe :
 * plus de plafonds recopiés à deux endroits qui peuvent diverger.
 *
 * Les plafonds appliqués, dans l'ordre des passes :
 *  1. un choix par championnat (rotation optionnelle) ;
 *  2. les marchés autres que le résultat avant les 1X2 / doubles chances ;
 *  3. un type de sélection répété est toléré une fois de plus, sans plafond
 *     de championnat ;
 *  4. dernier recours sans plafond, uniquement pour atteindre la taille
 *     demandée quand les marchés disponibles ne suffisent pas.
 */
final class MarketDiversitySelector
{
    /** Familles soumises au plafond cumulé des marchés de résultat. */
    public const RESULT_FAMILIES = ['1x2', 'double_chance'];

    /**
     * Table de correspondance unique des libellés de marché vers une famille
     * canonique en minuscules. Les clés sont comparées en minuscules : un
     * libellé « 1N2 » ne doit pas échapper au plafond des marchés de résultat.
     */
    private const FAMILY_MAP = [
        '1n2' => '1x2',
        '1x2' => '1x2',
        'h2h' => '1x2',
        'result' => '1x2',
        'resultat' => '1x2',
        'résultat' => '1x2',
        'résultats' => '1x2',
        'home' => '1x2',
        'away' => '1x2',
        'draw' => '1x2',
        'double chance' => 'double_chance',
        'double_chance' => 'double_chance',
        'dnb' => 'dnb',
        'btts' => 'btts',
        'total buts' => 'totals',
        'totaux' => 'totals',
        'totals' => 'totals',
        'total_goals' => 'totals',
        'asian_totals' => 'totals',
        'buts par équipe' => 'team_totals',
        'team_totals' => 'team_totals',
        'clean sheet' => 'clean_sheet',
        'clean_sheet' => 'clean_sheet',
        'marge de buts' => 'goal_margin',
        'goal_margin' => 'goal_margin',
        'handicap' => 'handicap',
        'handicap asiatique' => 'handicap',
        'asian_handicap' => 'handicap',
        'european_handicap' => 'handicap',
        'combiné' => 'combos',
        'combine' => 'combos',
        'combos' => 'combos',
        'ht_result' => 'half_time',
        'half_time' => 'half_time',
        'score exact' => 'score_exact',
        'exact_score' => 'score_exact',
        'corners' => 'corners',
        'cartons' => 'cards',
        'cards' => 'cards',
        'statistiques' => 'stats',
        'tirs' => 'stats',
    ];

    /**
     * Famille canonique d'un libellé de marché.
     */
    public function family(string $market): string
    {
        $key = mb_strtolower(trim($market), 'UTF-8');

        if ($key === '') {
            return 'other';
        }

        return self::FAMILY_MAP[$key] ?? $key;
    }

    /**
     * Type de sélection indépendant des équipes : « Plus de 1,5 but » reste le
     * même type d'un match à l'autre, ce qui évite un coupon rempli de lignes
     * identiques alors que d'autres marchés sont disponibles.
     *
     * @param  array<int, string|null>  $teamNames  noms tels qu'affichés
     */
    public function selectionType(string $market, string $label, array $teamNames = []): string
    {
        foreach ($teamNames as $name) {
            $name = trim((string) $name);
            if ($name !== '') {
                $label = str_replace($name, '⌁', $label);
            }
        }

        $label = trim((string) preg_replace('/\s+/u', ' ', (string) preg_replace('/^⌁\s*/u', '', $label)));

        return $market.'|'.$label;
    }

    /**
     * Sélectionne au plus $limit candidats en respectant les plafonds.
     *
     * L'ordre d'entrée est l'ordre de priorité : l'appelant trie comme il le
     * souhaite (probabilité décroissante, proximité à une cible, ...).
     *
     * Chaque candidat doit fournir :
     *   - match      : identifiant du match (un seul choix par match) ;
     *   - family     : famille canonique (voir family()) ;
     *   - type       : type de sélection (voir selectionType()) ;
     *   - league     : clé de championnat ;
     *   - payload    : valeur renvoyée pour la sélection retenue.
     *
     * @param  array<int, array<string, mixed>>  $candidates
     * @param  array{family_cap?: int, type_cap?: int, league_cap?: int|null, combined_result_cap?: int, rotate_league?: bool}  $options
     * @return array<int, mixed>
     */
    public function select(array $candidates, int $limit, array $options = []): array
    {
        if ($limit <= 0 || $candidates === []) {
            return [];
        }

        $familyCap = (int) ($options['family_cap'] ?? 3);
        $typeCap = (int) ($options['type_cap'] ?? ($limit <= 3 ? 1 : 2));
        $leagueCap = $options['league_cap'] ?? null;
        // Deux coupons de tailles différentes ne peuvent pas être remplis de
        // la même façon : sur dix sélections la moitié suffit déjà à la
        // domination des marchés de résultat.
        $combinedResultCap = (int) ($options['combined_result_cap'] ?? max(1, (int) floor($limit / 2)));
        $rotateLeague = (bool) ($options['rotate_league'] ?? false);

        $selected = [];
        $usedMatches = [];
        $familyCounts = array_fill_keys(self::RESULT_FAMILIES, 0);
        $typeCounts = [];
        $leagueCounts = [];

        $tryAdd = function (
            array $candidate,
            bool $enforceCaps,
            ?int $typeLimit = null,
            ?int $leagueLimit = null
        ) use (
            &$selected,
            &$usedMatches,
            &$familyCounts,
            &$typeCounts,
            &$leagueCounts,
            $familyCap,
            $typeCap,
            $combinedResultCap,
            $leagueCap
        ): void {
            $matchKey = $candidate['match'] ?? null;
            if ($matchKey !== null && isset($usedMatches[(string) $matchKey])) {
                return;
            }

            $family = (string) ($candidate['family'] ?? 'other');
            $type = (string) ($candidate['type'] ?? 'autre');
            $league = (string) ($candidate['league'] ?? 'unknown');
            $isResult = in_array($family, self::RESULT_FAMILIES, true);

            if ($enforceCaps) {
                if ($isResult
                    && (($familyCounts[$family] ?? 0) >= $familyCap
                        || (($familyCounts['1x2'] ?? 0) + ($familyCounts['double_chance'] ?? 0)) >= $combinedResultCap)) {
                    return;
                }
                if (($typeCounts[$type] ?? 0) >= ($typeLimit ?? $typeCap)) {
                    return;
                }
                $leagueLimit = $leagueLimit ?? $leagueCap ?? PHP_INT_MAX;
                if (($leagueCounts[$league] ?? 0) >= $leagueLimit) {
                    return;
                }
            }

            $selected[] = $candidate;
            if ($matchKey !== null) {
                $usedMatches[(string) $matchKey] = true;
            }
            if ($isResult) {
                $familyCounts[$family] = ($familyCounts[$family] ?? 0) + 1;
            }
            $typeCounts[$type] = ($typeCounts[$type] ?? 0) + 1;
            $leagueCounts[$league] = ($leagueCounts[$league] ?? 0) + 1;
        };

        $passes = [];

        // 1. Un choix par championnat, quand le coupon doit couvrir plusieurs
        // compétitions avant de remplir une seule famille.
        if ($rotateLeague) {
            $passes[] = ['source' => 'one_per_league', 'enforce' => true];
        }

        // 2. Les marchés de buts et statistiques avant les marchés de résultat.
        $passes[] = ['source' => 'non_result_first', 'enforce' => true];

        // 3. Un type répété reste toléré une fois de plus, sans plafond de
        // championnat.
        $passes[] = [
            'source' => 'as_is',
            'enforce' => true,
            'type_limit' => $typeCap + 1,
            'league_limit' => PHP_INT_MAX,
        ];

        // 4. Dernier recours : uniquement si les marchés disponibles ne
        // permettent pas d'atteindre la taille demandée.
        $passes[] = ['source' => 'as_is', 'enforce' => false];

        foreach ($passes as $pass) {
            foreach ($this->source($candidates, $pass['source']) as $candidate) {
                if (count($selected) >= $limit) {
                    break 2;
                }

                $tryAdd(
                    $candidate,
                    $pass['enforce'],
                    $pass['type_limit'] ?? null,
                    $pass['league_limit'] ?? null
                );
            }
        }

        return array_map(
            fn (array $candidate) => $candidate['payload'] ?? $candidate,
            $selected
        );
    }

    /**
     * Ordre de parcours des candidats pour une passe.
     *
     * @param  array<int, array<string, mixed>>  $candidates
     * @return array<int, array<string, mixed>>
     */
    private function source(array $candidates, string $source): array
    {
        if ($source === 'one_per_league') {
            $seen = [];
            $first = [];
            foreach ($candidates as $candidate) {
                $league = (string) ($candidate['league'] ?? 'unknown');
                if (isset($seen[$league])) {
                    continue;
                }
                $seen[$league] = true;
                $first[] = $candidate;
            }

            return $first;
        }

        if ($source === 'non_result_first') {
            $others = [];
            $results = [];
            foreach ($candidates as $candidate) {
                if (in_array((string) ($candidate['family'] ?? 'other'), self::RESULT_FAMILIES, true)) {
                    $results[] = $candidate;
                } else {
                    $others[] = $candidate;
                }
            }

            return array_merge($others, $results);
        }

        return $candidates;
    }
}
