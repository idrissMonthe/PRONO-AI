<?php

namespace Tests\Unit;

use App\Services\Parlay\MarketDiversitySelector;
use PHPUnit\Framework\TestCase;

class MarketDiversitySelectorTest extends TestCase
{
    private function selector(): MarketDiversitySelector
    {
        return new MarketDiversitySelector();
    }

    /**
     * @param  array<int, array<string, mixed>>  $candidates
     * @return array<int, array<string, mixed>>
     */
    private function candidates(array $candidates): array
    {
        return array_map(
            fn (array $candidate): array => array_merge([
                'family' => 'totals',
                'type' => 'type',
                'league' => 'L1',
            ], $candidate),
            $candidates
        );
    }

    private function labels(array $selected): array
    {
        return array_map(fn (array $selection): string => (string) $selection['label'], $selected);
    }

    public function test_normalizes_every_market_label_to_one_canonical_family(): void
    {
        $selector = $this->selector();

        $this->assertSame('1x2', $selector->family('1N2'));
        $this->assertSame('1x2', $selector->family('1x2'));
        $this->assertSame('1x2', $selector->family('Résultat'));
        $this->assertSame('double_chance', $selector->family('Double chance'));
        $this->assertSame('totals', $selector->family('Total buts'));
        $this->assertSame('totals', $selector->family('total_goals'));
        $this->assertSame('team_totals', $selector->family('Buts par équipe'));
        $this->assertSame('btts', $selector->family('BTTS'));
        $this->assertSame('clean_sheet', $selector->family('Clean sheet'));
        $this->assertSame('goal_margin', $selector->family('Marge de buts'));
        $this->assertSame('handicap', $selector->family('handicap asiatique'));
        $this->assertSame('combos', $selector->family('Combiné'));
        $this->assertSame('score_exact', $selector->family('Score exact'));
        $this->assertSame('corners', $selector->family('Corners'));
        $this->assertSame('stats', $selector->family('Statistiques'));
        $this->assertSame('other', $selector->family('   '));
    }

    public function test_selection_type_ignores_team_names_so_identical_lines_share_a_type(): void
    {
        $selector = $this->selector();

        $first = $selector->selectionType('Total buts', 'Plus de 1,5 buts', []);
        $second = $selector->selectionType('Total buts', 'Plus de 1,5 buts', []);

        $this->assertSame($first, $second);
        $this->assertSame(
            'Double chance|ou nul (1X)',
            $selector->selectionType('Double chance', 'Lille OSC ou nul (1X)', ['Lille OSC'])
        );
        $this->assertSame(
            '1N2|Victoire ⌁',
            $selector->selectionType('1N2', 'Victoire Athletic Club', ['Athletic Club'])
        );
    }

    public function test_keeps_one_selection_per_match(): void
    {
        $selected = $this->selector()->select($this->candidates([
            ['match' => 1, 'payload' => ['label' => 'a']],
            ['match' => 1, 'payload' => ['label' => 'b']],
            ['match' => 2, 'payload' => ['label' => 'c']],
        ]), 10);

        $this->assertSame(['a', 'c'], $this->labels($selected));
    }

    public function test_result_markets_never_dominate_a_coupon_that_other_families_can_fill(): void
    {
        $candidates = [];
        foreach (range(1, 6) as $id) {
            $candidates[] = [
                'match' => $id,
                'family' => 'double_chance',
                'type' => 'dc'.$id,
                'payload' => ['label' => 'dc'.$id],
            ];
        }
        foreach (range(1, 10) as $id) {
            $candidates[] = [
                'match' => 100 + $id,
                'family' => 'totals',
                'type' => 'total'.$id,
                'payload' => ['label' => 'total'.$id],
            ];
        }

        $selected = $this->selector()->select($this->candidates($candidates), 10);

        $this->assertCount(10, $selected);
        $this->assertSame([], array_filter(
            $selected,
            fn (array $selection): bool => $selection['label'] === 'dc1'
        ));
    }

    public function test_combined_result_cap_holds_when_goals_markets_can_fill_the_coupon(): void
    {
        $candidates = [];
        foreach (range(1, 3) as $id) {
            $candidates[] = [
                'match' => $id,
                'family' => 'double_chance',
                'type' => 'dc'.$id,
                'payload' => ['label' => 'dc'.$id],
            ];
        }
        foreach (range(1, 3) as $id) {
            $candidates[] = [
                'match' => 100 + $id,
                'family' => 'totals',
                'type' => 'total'.$id,
                'payload' => ['label' => 'total'.$id],
            ];
        }

        // Sur cinq sélections, deux marchés de résultat au maximum.
        $selected = $this->selector()->select($this->candidates($candidates), 5);

        $this->assertCount(5, $selected);
        $this->assertCount(2, array_filter(
            $selected,
            fn (array $selection): bool => str_starts_with((string) $selection['label'], 'dc')
        ));
    }

    public function test_prefers_goal_markets_over_result_markets_when_both_are_available(): void
    {
        $selected = $this->selector()->select($this->candidates([
            ['match' => 1, 'family' => '1x2', 'type' => 'w1', 'payload' => ['label' => 'victoire']],
            ['match' => 2, 'family' => 'totals', 'type' => 'over', 'payload' => ['label' => 'plus de buts']],
        ]), 2);

        $this->assertSame(['plus de buts', 'victoire'], $this->labels($selected));
    }

    public function test_short_coupons_repeat_a_type_only_when_nothing_else_is_available(): void
    {
        // Sur un coupon court, un type déjà présent cède la place à un autre.
        $selected = $this->selector()->select($this->candidates([
            ['match' => 1, 'type' => 'plus de 1,5 buts', 'payload' => ['label' => 'doublon 1']],
            ['match' => 2, 'type' => 'plus de 1,5 buts', 'payload' => ['label' => 'doublon 2']],
            ['match' => 3, 'type' => 'moins de 3,5 buts', 'payload' => ['label' => 'autre 1']],
            ['match' => 4, 'type' => 'btts oui', 'payload' => ['label' => 'autre 2']],
        ]), 3);

        $this->assertSame(['doublon 1', 'autre 1', 'autre 2'], $this->labels($selected));

        // Aucun autre type disponible : le coupon se remplit quand même.
        $duplicates = [];
        foreach (range(1, 3) as $id) {
            $duplicates[] = [
                'match' => $id,
                'type' => 'plus de 1,5 buts',
                'payload' => ['label' => 'p'.$id],
            ];
        }

        $this->assertCount(3, $this->selector()->select($this->candidates($duplicates), 3));
    }

    public function test_limits_the_number_of_selections_per_league(): void
    {
        $candidates = [];
        foreach (range(1, 10) as $id) {
            $candidates[] = [
                'match' => $id,
                'league' => 'Bundesliga',
                'type' => 'bundes'.$id,
                'payload' => ['label' => 'bundes'.$id, 'league' => 'Bundesliga'],
            ];
            $candidates[] = [
                'match' => 100 + $id,
                'league' => 'Serie A',
                'type' => 'serie'.$id,
                'payload' => ['label' => 'serie'.$id, 'league' => 'Serie A'],
            ];
        }

        $selected = $this->selector()->select($this->candidates($candidates), 6, ['league_cap' => 3]);

        $this->assertCount(6, $selected);
        $this->assertCount(3, array_filter(
            $selected,
            fn (array $selection): bool => $selection['league'] === 'Bundesliga'
        ));
    }

    public function test_league_rotation_starts_with_one_selection_per_competition(): void
    {
        $selected = $this->selector()->select($this->candidates([
            ['match' => 1, 'league' => 'L1', 'type' => 'a', 'payload' => ['label' => 'l1a']],
            ['match' => 2, 'league' => 'L1', 'type' => 'b', 'payload' => ['label' => 'l1b']],
            ['match' => 3, 'league' => 'L2', 'type' => 'c', 'payload' => ['label' => 'l2']],
        ]), 2, ['rotate_league' => true]);

        $this->assertSame(['l1a', 'l2'], $this->labels($selected));
    }

    public function test_last_resort_fills_the_coupon_when_caps_cannot_be_respected(): void
    {
        $candidates = [];
        foreach (range(1, 4) as $id) {
            $candidates[] = [
                'match' => $id,
                'family' => '1x2',
                'type' => 'w'.$id,
                'payload' => ['label' => 'w'.$id],
            ];
        }

        // Sans le repli, le plafond cumulé bloquerait le coupon à deux lignes.
        $this->assertCount(4, $this->selector()->select($this->candidates($candidates), 4));
    }

    public function test_returns_nothing_for_an_empty_or_zero_sized_request(): void
    {
        $this->assertSame([], $this->selector()->select([], 10));
        $this->assertSame([], $this->selector()->select($this->candidates([
            ['match' => 1, 'payload' => ['label' => 'a']],
        ]), 0));
    }
}
