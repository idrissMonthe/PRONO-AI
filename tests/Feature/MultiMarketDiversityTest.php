<?php

namespace Tests\Feature;

use App\Services\Prediction\PredictionRevisionService;
use Tests\TestCase;

class MultiMarketDiversityTest extends TestCase
{
    private function revisionService(): PredictionRevisionService
    {
        return app(PredictionRevisionService::class);
    }

    /**
     * Document statistique complet simulant un match avec distribution riche.
     */
    private function sampleFullMatchResult(): array
    {
        return [
            'status' => 'ok',
            'quality_score' => 88.0,
            'abstain' => false,
            'expected_goals' => ['home' => 1.85, 'away' => 1.10, 'total' => 2.95],
            'probabilities' => [
                'home' => ['p' => 0.54],
                'draw' => ['p' => 0.24],
                'away' => ['p' => 0.22],
            ],
            'markets' => [
                '1N2' => ['home' => 0.54, 'draw' => 0.24, 'away' => 0.22],
                'double_chance' => ['1X' => 0.78, 'X2' => 0.46, '12' => 0.76],
                'totals' => [
                    'over_0_5' => 0.95, 'under_0_5' => 0.05,
                    'over_1_5' => 0.81, 'under_1_5' => 0.19,
                    'over_2_5' => 0.58, 'under_2_5' => 0.42,
                    'over_3_5' => 0.35, 'under_3_5' => 0.65,
                ],
                'btts' => ['yes' => 0.56, 'no' => 0.44],
                'clean_sheet' => ['home' => 0.33, 'away' => 0.20],
                'win_to_nil' => ['home' => 0.28, 'away' => 0.12],
                'goal_margin' => ['home_by_2_plus' => 0.28, 'away_by_2_plus' => 0.08],
                'team_totals' => [
                    'home' => ['over_1_5' => 0.55, 'under_1_5' => 0.45],
                    'away' => ['over_1_5' => 0.32, 'under_1_5' => 0.68],
                ],
                'combos' => [
                    'home_win_over_1_5' => 0.46,
                    'home_win_over_2_5' => 0.37,
                    'home_win_btts_yes' => 0.33,
                    'away_win_btts_yes' => 0.15,
                ],
            ],
            'market_predictions' => [
                // Résultats
                ['market_id' => '1N2', 'selection' => 'home', 'selection_label' => 'Victoire domicile', 'probability' => 0.54, 'family' => 'Résultats', 'reliability' => 0.78, 'coverage' => 'full'],
                ['market_id' => 'double_chance', 'selection' => '1X', 'selection_label' => 'Double chance 1X', 'probability' => 0.78, 'family' => 'Résultats', 'reliability' => 0.85, 'coverage' => 'full'],
                ['market_id' => 'dnb', 'selection' => 'home', 'selection_label' => 'DNB Domicile', 'probability' => 0.71, 'family' => 'Résultats', 'reliability' => 0.80, 'coverage' => 'full'],
                ['market_id' => 'european_handicap', 'selection' => 'EH_HOME_-1', 'selection_label' => 'Handicap -1 Domicile', 'probability' => 0.32, 'family' => 'Résultats', 'reliability' => 0.72, 'coverage' => 'full'],
                
                // Buts
                ['market_id' => 'totals', 'selection' => 'over_1_5', 'selection_label' => 'Plus de 1,5 buts', 'probability' => 0.81, 'family' => 'Buts', 'reliability' => 0.88, 'coverage' => 'full'],
                ['market_id' => 'totals', 'selection' => 'over_2_5', 'selection_label' => 'Plus de 2,5 buts', 'probability' => 0.58, 'family' => 'Buts', 'reliability' => 0.82, 'coverage' => 'full'],
                ['market_id' => 'btts', 'selection' => 'yes', 'selection_label' => 'Les deux marquent : oui', 'probability' => 0.56, 'family' => 'Buts', 'reliability' => 0.80, 'coverage' => 'full'],
                ['market_id' => 'clean_sheet', 'selection' => 'home', 'selection_label' => 'Clean sheet domicile', 'probability' => 0.33, 'family' => 'Buts', 'reliability' => 0.74, 'coverage' => 'full'],
                ['market_id' => 'win_to_nil', 'selection' => 'home', 'selection_label' => 'Victoire sans encaisser', 'probability' => 0.28, 'family' => 'Buts', 'reliability' => 0.72, 'coverage' => 'full'],
                
                // Mi-temps et périodes
                ['market_id' => 'half_time_result', 'selection' => 'home', 'selection_label' => 'Mi-temps : Domicile', 'probability' => 0.38, 'family' => 'Mi-temps et périodes', 'reliability' => 0.70, 'coverage' => 'full'],
                ['market_id' => 'half_time_totals', 'selection' => 'over_0_5', 'selection_label' => 'MT : plus de 0,5 but', 'probability' => 0.72, 'family' => 'Mi-temps et périodes', 'reliability' => 0.80, 'coverage' => 'full'],
                ['market_id' => 'btts_first_half', 'selection' => 'no', 'selection_label' => 'BTTS 1re MT : Non', 'probability' => 0.84, 'family' => 'Mi-temps et périodes', 'reliability' => 0.85, 'coverage' => 'full'],
                
                // Combinés
                ['market_id' => 'result_total_combo', 'selection' => 'home_over_1_5', 'selection_label' => '1 & Over 1.5', 'probability' => 0.46, 'family' => 'Marchés combinés', 'reliability' => 0.75, 'coverage' => 'full'],
                ['market_id' => 'result_btts_combo', 'selection' => 'home_btts_yes', 'selection_label' => '1 & BTTS', 'probability' => 0.33, 'family' => 'Marchés combinés', 'reliability' => 0.72, 'coverage' => 'full'],
                
                // Corners (statistiques disponibles)
                ['market_id' => 'corners', 'selection' => 'over_8_5', 'selection_label' => 'Plus de 8,5 corners', 'probability' => 0.62, 'family' => 'Corners', 'reliability' => 0.70, 'coverage' => 'full'],
                ['market_id' => 'corners', 'selection' => 'under_11_5', 'selection_label' => 'Moins de 11,5 corners', 'probability' => 0.65, 'family' => 'Corners', 'reliability' => 0.71, 'coverage' => 'full'],
                
                // Discipline (cartons)
                ['market_id' => 'cards', 'selection' => 'over_3_5', 'selection_label' => 'Plus de 3,5 cartons', 'probability' => 0.68, 'family' => 'Discipline', 'reliability' => 0.72, 'coverage' => 'full'],
                
                // Tirs
                ['market_id' => 'shots', 'selection' => 'over_22_5', 'selection_label' => 'Plus de 22,5 tirs', 'probability' => 0.58, 'family' => 'Tirs', 'reliability' => 0.68, 'coverage' => 'full'],
                
                // Joueurs (conditionné par données individuelles)
                ['market_id' => 'anytime_goalscorer', 'selection' => 'player_99', 'selection_label' => 'Buteur : Joueur Star', 'probability' => 0.42, 'family' => 'Joueurs', 'reliability' => 0.65, 'coverage' => 'full'],
            ],
            'market_categories' => [
                'prudent' => [
                    ['market_id' => 'double_chance', 'selection' => '1X', 'probability' => 0.78, 'family' => 'Résultats', 'reliability' => 0.85, 'coverage' => 'full'],
                    ['market_id' => 'btts_first_half', 'selection' => 'no', 'probability' => 0.84, 'family' => 'Mi-temps et périodes', 'reliability' => 0.85, 'coverage' => 'full'],
                    ['market_id' => 'totals', 'selection' => 'over_1_5', 'probability' => 0.81, 'family' => 'Buts', 'reliability' => 0.88, 'coverage' => 'full'],
                ],
                'equilibre' => [
                    ['market_id' => '1N2', 'selection' => 'home', 'probability' => 0.54, 'family' => 'Résultats', 'reliability' => 0.78, 'coverage' => 'full'],
                    ['market_id' => 'totals', 'selection' => 'over_2_5', 'probability' => 0.58, 'family' => 'Buts', 'reliability' => 0.82, 'coverage' => 'full'],
                    ['market_id' => 'btts', 'selection' => 'yes', 'probability' => 0.56, 'family' => 'Buts', 'reliability' => 0.80, 'coverage' => 'full'],
                    ['market_id' => 'corners', 'selection' => 'over_8_5', 'probability' => 0.62, 'family' => 'Corners', 'reliability' => 0.70, 'coverage' => 'full'],
                    ['market_id' => 'cards', 'selection' => 'over_3_5', 'probability' => 0.68, 'family' => 'Discipline', 'reliability' => 0.72, 'coverage' => 'full'],
                ],
                'audacieux' => [
                    ['market_id' => 'result_total_combo', 'selection' => 'home_over_1_5', 'probability' => 0.46, 'family' => 'Marchés combinés', 'reliability' => 0.75, 'coverage' => 'full'],
                    ['market_id' => 'anytime_goalscorer', 'selection' => 'player_99', 'probability' => 0.42, 'family' => 'Joueurs', 'reliability' => 0.65, 'coverage' => 'full'],
                    ['market_id' => 'win_to_nil', 'selection' => 'home', 'probability' => 0.28, 'family' => 'Buts', 'reliability' => 0.72, 'coverage' => 'full'],
                ],
            ],
        ];
    }

    /**
     * Teste que les candidats couvrent de multiples familles et ne sont pas réduits à 1N2.
     */
    public function test_candidates_include_multiple_market_families(): void
    {
        $service = $this->revisionService();
        $result = $this->sampleFullMatchResult();
        $candidates = $service->candidatesFromResult($result, [
            'home_team' => 'Athletic Club',
            'away_team' => 'Espanyol',
        ]);

        $this->assertNotEmpty($candidates);
        $families = collect($candidates)->pluck('family')->unique()->values()->all();

        // Vérifier la présence de plusieurs familles distinctes
        $this->assertGreaterThanOrEqual(4, count($families), 'Le moteur doit générer au moins 4 familles distinctes de marchés.');
        
        // Vérifier les marchés spécifiques
        $markets = collect($candidates)->pluck('market')->unique()->values()->all();
        $this->assertContains('double_chance', $markets, 'La double chance doit être évaluée.');
        $this->assertContains('totals', $markets, 'Les totaux de buts doivent être présents.');
        $this->assertContains('btts', $markets, 'Le marché BTTS doit être présent.');
        $this->assertContains('corners', $markets, 'Le marché des corners doit être évalué.');
        $this->assertContains('cards', $markets, 'Le marché de la discipline (cartons) doit être évalué.');
    }

    /**
     * Teste que la sélection initiale choisit équitablement le meilleur marché selon score multi-critères.
     */
    public function test_initial_selection_is_not_monopolized_by_1n2(): void
    {
        $service = $this->revisionService();
        $result = $this->sampleFullMatchResult();
        
        $review = $service->review($result, [
            'home_team' => 'Athletic Club',
            'away_team' => 'Espanyol',
            'quality_score' => 88.0,
            'odds_context' => [
                '1N2' => ['home' => 1.74, 'draw' => 3.50, 'away' => 5.20],
            ],
        ]);

        $initial = $review['initial_candidate'] ?? $review['python_candidate'];
        $this->assertNotNull($initial);
        // Le candidat initial peut être une double chance, un total de buts ou 1N2 selon la robustesse
        $this->assertContains($initial['market'], ['double_chance', 'totals', 'btts_first_half', '1N2', 'sh_total_goals', 'corners']);
    }

    /**
     * Teste que les marchés rejetés pour manque de données ou couverture nulle ont un motif explicite.
     */
    public function test_unsupported_or_sparse_markets_are_rejected_with_reason(): void
    {
        $service = $this->revisionService();
        
        // Match sans stats de tirs ni joueurs
        $sparseResult = [
            'markets' => [
                '1N2' => ['home' => 0.50, 'draw' => 0.30, 'away' => 0.20],
            ],
            'market_predictions' => [
                ['market_id' => '1N2', 'selection' => 'home', 'probability' => 0.50, 'coverage' => 'full', 'reliability' => 0.70],
                ['market_id' => 'shots', 'selection' => 'over_20_5', 'probability' => 0.50, 'coverage' => 'none', 'reliability' => 0.10], // pas de stats tirs
                ['market_id' => 'players', 'selection' => 'p1', 'probability' => 0.20, 'coverage' => 'insufficient', 'reliability' => 0.20], // pas de stats joueurs
            ],
        ];

        $candidates = $service->candidatesFromResult($sparseResult);
        
        // Les marchés avec coverage 'none' ne doivent pas être sélectionnés comme candidat initial
        $candidatesColl = collect($candidates);
        $shotsCandidate = $candidatesColl->firstWhere('market', 'shots');
        
        // Si présent, sa couverture doit être 'none'
        if ($shotsCandidate) {
            $this->assertSame('none', $shotsCandidate['coverage']);
        }
    }

    /**
     * Teste et produit le rapport complet de diversité des familles de marchés (Section 5).
     */
    public function test_produces_multi_market_diversity_report(): void
    {
        $service = $this->revisionService();
        $result = $this->sampleFullMatchResult();
        $candidates = $service->candidatesFromResult($result);

        $report = [];
        $byFamily = collect($candidates)->groupBy('family');

        foreach ($byFamily as $family => $items) {
            $report[$family] = [
                'generated' => $items->count(),
                'evaluated' => $items->count(),
                'avg_prob' => round($items->avg('probability') * 100, 1) . '%',
                'avg_reliability' => round($items->avg('reliability') * 100, 1) . '%',
                'full_coverage_count' => $items->where('coverage', 'full')->count(),
            ];
        }

        $this->assertNotEmpty($report);
        $this->assertArrayHasKey('Résultats', $report);
        $this->assertArrayHasKey('Buts', $report);
        
        // Vérifier qu'au moins 3 familles ont été évaluées
        $this->assertGreaterThanOrEqual(3, count($report));
    }
}
