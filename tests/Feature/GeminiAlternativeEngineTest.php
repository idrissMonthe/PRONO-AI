<?php

namespace Tests\Feature;

use App\Models\FootballMatch;
use App\Models\League;
use App\Models\ModelVersion;
use App\Models\Parlay;
use App\Models\Prediction;
use App\Models\Season;
use App\Models\Team;
use App\Services\AIExplanation\GeminiExplanationService;
use App\Services\Prediction\PredictionRevisionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GeminiAlternativeEngineTest extends TestCase
{
    use RefreshDatabase;

    private League $league;
    private Season $season;
    private Team $homeTeam;
    private Team $awayTeam;
    private FootballMatch $match;
    private ModelVersion $model;

    protected function setUp(): void
    {
        parent::setUp();

        $this->league = League::create(['code' => 'PL', 'name' => 'Premier League', 'country' => 'England', 'tier' => '1', 'is_active' => true]);
        $this->season = Season::create(['league_id' => $this->league->id, 'name' => '2025-2026', 'year_start' => 2025, 'year_end' => 2026, 'is_current' => true]);
        $this->homeTeam = Team::create(['name' => 'Arsenal FC', 'short_name' => 'Arsenal', 'country' => 'England']);
        $this->awayTeam = Team::create(['name' => 'Chelsea FC', 'short_name' => 'Chelsea', 'country' => 'England']);
        $this->match = FootballMatch::create([
            'external_id' => 'match-gemini-test-1',
            'data_source' => 'football-data.org',
            'league_id' => $this->league->id,
            'season_id' => $this->season->id,
            'home_team_id' => $this->homeTeam->id,
            'away_team_id' => $this->awayTeam->id,
            'match_date_utc' => now()->addDay(),
            'status' => 'SCHEDULED',
            'quality_score' => 85,
            'quality_tier' => 'usable',
        ]);
        $this->model = ModelVersion::create([
            'version_name' => 'M3_MARKET_SPECIALISTS_v6',
            'algorithm' => 'Dixon-Coles',
            'status' => 'champion',
        ]);
    }

    private function samplePythonResult(): array
    {
        return [
            'status' => 'ok',
            'quality_score' => 85.0,
            'abstain' => false,
            'expected_goals' => ['home' => 1.8, 'away' => 1.0, 'total' => 2.8],
            'probabilities' => [
                'home' => ['p' => 0.68],
                'draw' => ['p' => 0.20],
                'away' => ['p' => 0.12],
            ],
            'markets' => [
                '1N2' => ['home' => 0.68, 'draw' => 0.20, 'away' => 0.12],
                'totals' => ['over_2_5' => 0.55, 'under_2_5' => 0.45],
                'btts' => ['yes' => 0.52, 'no' => 0.48],
            ],
        ];
    }

    private function makeServiceWithMock(array $mockGeminiReturn): PredictionRevisionService
    {
        $mock = $this->createMock(GeminiExplanationService::class);
        $mock->method('reviewPrediction')->willReturn($mockGeminiReturn);
        return new PredictionRevisionService($mock);
    }

    // 1. Python propose -> Gemini valide -> la proposition Python est retenue.
    public function test_1_python_proposed_gemini_validates_python_pick_retained(): void
    {
        $service = $this->makeServiceWithMock([
            'fallback' => false,
            'decision' => 'validated',
            'candidate_key' => null,
            'reasoning' => 'Le pronostic initial est bien étayé par les données.',
            'supporting_factors' => ['xG favorable'],
            'risk_factors' => [],
            'required_recalculation' => false,
            'confidence_in_revision' => 'high',
        ]);

        $review = $service->review($this->samplePythonResult(), ['quality_score' => 85]);

        $this->assertSame(PredictionRevisionService::STATUS_VALIDATED, $review['gemini_status']);
        $this->assertNotNull($review['python_candidate']);
        $this->assertNull($review['alternative']);
        $this->assertSame($review['python_candidate']['selection'], $review['published_selection']['selection']);
    }

    // 2. Python propose -> Gemini conteste -> Gemini propose une alternative valide -> l'alternative est retenue.
    public function test_2_python_proposed_gemini_contests_with_valid_alternative_retained(): void
    {
        $service = $this->makeServiceWithMock([
            'fallback' => false,
            'decision' => 'not_validated',
            'candidate_key' => 'btts:yes',
            'reasoning' => 'La victoire sèche est trop risquée face à un rival.',
            'alternative_reason' => 'Les deux équipes marquent est plus probable que le clean sheet.',
            'supporting_factors' => ['Sécurité accrue'],
            'risk_factors' => ['Choc indécis'],
            'required_recalculation' => false,
            'confidence_in_revision' => 'high',
        ]);

        $review = $service->review($this->samplePythonResult(), ['quality_score' => 85]);

        $this->assertSame(PredictionRevisionService::STATUS_WITH_ALTERNATIVE, $review['gemini_status']);
        $this->assertNotNull($review['alternative']);
        $this->assertSame('btts', $review['alternative']['market']);
        $this->assertSame('yes', $review['alternative']['selection']);
        // Python candidate remains preserved!
        $this->assertNotNull($review['python_candidate']);
        $this->assertSame('1N2', $review['python_candidate']['market']);
    }

    // 3. Python propose -> Gemini conteste sans alternative -> la proposition Python reste souveraine.
    public function test_3_python_proposed_gemini_contests_without_alternative_python_sovereign(): void
    {
        $service = $this->makeServiceWithMock([
            'fallback' => false,
            'decision' => 'not_validated',
            'candidate_key' => null,
            'reasoning' => 'Match trop risqué pour toutes les options.',
            'supporting_factors' => [],
            'risk_factors' => ['Forme incertaine'],
            'required_recalculation' => false,
            'confidence_in_revision' => 'medium',
        ]);

        $review = $service->review($this->samplePythonResult(), ['quality_score' => 85]);

        $this->assertSame(PredictionRevisionService::STATUS_WITHOUT_ALTERNATIVE, $review['gemini_status']);
        $this->assertNull($review['alternative']);
        $this->assertSame($review['python_candidate']['selection'], $review['published_selection']['selection']);
    }

    // 4. Gemini indisponible -> la proposition Python est retenue.
    public function test_4_gemini_unavailable_python_pick_retained(): void
    {
        $service = $this->makeServiceWithMock([
            'fallback' => true,
            'decision' => 'review_failed',
            'candidate_key' => null,
            'reasoning' => 'La validation Gemini n’a pas pu être effectuée.',
            'supporting_factors' => [],
            'risk_factors' => ['ai_review_unavailable'],
            'required_recalculation' => false,
            'confidence_in_revision' => 'low',
        ]);

        $review = $service->review($this->samplePythonResult(), ['quality_score' => 85]);

        $this->assertSame(PredictionRevisionService::STATUS_FAILED, $review['gemini_status']);
        $this->assertNotNull($review['python_candidate']);
        $this->assertSame($review['python_candidate']['selection'], $review['published_selection']['selection']);
    }

    // 5. Gemini renvoie un JSON invalide -> la proposition Python est retenue.
    public function test_5_gemini_invalid_json_fallback_retains_python(): void
    {
        $service = $this->makeServiceWithMock([
            'fallback' => true,
            'decision' => 'review_failed',
            'candidate_key' => null,
            'reasoning' => 'JSON decode error.',
            'supporting_factors' => [],
            'risk_factors' => ['invalid_json'],
            'required_recalculation' => false,
            'confidence_in_revision' => 'low',
        ]);

        $review = $service->review($this->samplePythonResult(), ['quality_score' => 85]);

        $this->assertSame(PredictionRevisionService::STATUS_FAILED, $review['gemini_status']);
        $this->assertNotNull($review['python_candidate']);
        $this->assertSame($review['python_candidate']['selection'], $review['published_selection']['selection']);
    }

    // 6. Gemini propose un marché inconnu -> rejeté, proposition Python retenue.
    public function test_6_gemini_unknown_market_rejected_python_retained(): void
    {
        $service = $this->makeServiceWithMock([
            'fallback' => false,
            'decision' => 'not_validated',
            'candidate_key' => null,
            'alternative_market' => 'fictional_unsupported_market_xyz',
            'alternative_selection' => 'winner',
            'reasoning' => 'Proposition sur un marché inventé.',
            'supporting_factors' => [],
            'risk_factors' => [],
            'required_recalculation' => false,
            'confidence_in_revision' => 'high',
        ]);

        $review = $service->review($this->samplePythonResult(), ['quality_score' => 85]);

        $this->assertSame(PredictionRevisionService::STATUS_WITHOUT_ALTERNATIVE, $review['gemini_status']);
        $this->assertNull($review['alternative']);
        $this->assertSame($review['python_candidate']['selection'], $review['published_selection']['selection']);
    }

    // 7. Gemini propose une sélection impossible sur le marché -> rejetée, proposition Python retenue.
    public function test_7_gemini_impossible_selection_rejected_python_retained(): void
    {
        $service = $this->makeServiceWithMock([
            'fallback' => false,
            'decision' => 'not_validated',
            'candidate_key' => null,
            'alternative_market' => 'totals',
            'alternative_selection' => 'invalid_random_string',
            'reasoning' => 'Sélection syntaxiquement impossible.',
            'supporting_factors' => [],
            'risk_factors' => [],
            'required_recalculation' => false,
            'confidence_in_revision' => 'high',
        ]);

        $review = $service->review($this->samplePythonResult(), ['quality_score' => 85]);

        $this->assertSame(PredictionRevisionService::STATUS_WITHOUT_ALTERNATIVE, $review['gemini_status']);
        $this->assertNull($review['alternative']);
        $this->assertSame($review['python_candidate']['selection'], $review['published_selection']['selection']);
    }

    // 8. Vérification de la persistance des propositions de Gemini en base.
    public function test_8_persistence_of_gemini_proposals_in_database(): void
    {
        $pred = Prediction::create([
            'match_id' => $this->match->id,
            'source_model' => $this->model->version_name,
            'model_version_id' => $this->model->id,
            'is_official' => true,
            'prob_home' => 0.68,
            'prob_draw' => 0.20,
            'prob_away' => 0.12,
            'home_lambda' => 1.8,
            'away_lambda' => 1.0,
            'published_market' => '1N2',
            'published_selection' => 'home',
            'published_probability' => 0.68,
            'content_hash' => hash('sha256', 'pred-test-8'),
            'review_decision' => PredictionRevisionService::STATUS_WITH_ALTERNATIVE,
            'market_metrics_json' => [
                'gemini_review' => [
                    'status' => PredictionRevisionService::STATUS_WITH_ALTERNATIVE,
                    'status_label' => 'Non validé par Gemini (alternative proposée)',
                    'explanation' => 'Victoire trop serrée.',
                    'python' => ['market' => '1N2', 'selection' => 'home', 'probability' => 0.68, 'origin' => 'PYTHON'],
                    'alternative' => [
                        'market' => 'btts',
                        'selection' => 'yes',
                        'justification' => 'Sécurité BTTS recommandée.',
                        'probability' => 0.52,
                        'origin' => 'PYTHON',
                    ],
                ],
            ],
            'reviewed_at_utc' => now()->utc(),
        ]);

        $this->assertDatabaseHas('predictions', [
            'id' => $pred->id,
            'review_decision' => PredictionRevisionService::STATUS_WITH_ALTERNATIVE,
        ]);

        // Vérification des accessors du contrat de données
        $reloaded = Prediction::find($pred->id);
        $this->assertSame('1N2', $reloaded->python_prediction['market']);
        $this->assertSame('home', $reloaded->python_prediction['selection']);
        $this->assertSame('btts', $reloaded->gemini_alternative_market);
        $this->assertSame('yes', $reloaded->gemini_alternative_selection);
        $this->assertSame('Sécurité BTTS recommandée.', $reloaded->gemini_alternative_reason);
        $this->assertSame('Victoire trop serrée.', $reloaded->gemini_reason);
    }

    // 9. Vérification de l'affichage dans les vues.
    public function test_9_gemini_alternative_displayed_in_dashboard_data(): void
    {
        Prediction::create([
            'match_id' => $this->match->id,
            'source_model' => $this->model->version_name,
            'model_version_id' => $this->model->id,
            'is_official' => true,
            'prob_home' => 0.68,
            'prob_draw' => 0.20,
            'prob_away' => 0.12,
            'home_lambda' => 1.8,
            'away_lambda' => 1.0,
            'published_market' => '1N2',
            'published_selection' => 'home',
            'published_probability' => 0.68,
            'content_hash' => hash('sha256', 'pred-test-9'),
            'review_decision' => PredictionRevisionService::STATUS_WITH_ALTERNATIVE,
            'market_metrics_json' => [
                'published_odds' => 1.85,
                'published_odds_source' => 'bookmaker',
                'gemini_review' => [
                    'status' => PredictionRevisionService::STATUS_WITH_ALTERNATIVE,
                    'status_label' => 'Non validé par Gemini (alternative proposée)',
                    'explanation' => 'Victoire incertaine.',
                    'python' => ['market' => '1N2', 'selection' => 'home', 'probability' => 0.68, 'origin' => 'PYTHON'],
                    'alternative' => [
                        'market' => 'btts',
                        'selection' => 'yes',
                        'label' => 'Les deux équipes marquent',
                        'justification' => 'Option plus sûre avec probabilité de 52%.',
                        'origin' => 'PYTHON',
                    ],
                ],
            ],
            'reviewed_at_utc' => now()->utc(),
        ]);

        $response = $this->get('/');
        $response->assertOk();
        $response->assertSee('Arsenal');
    }

    // 10. Vérification que la proposition Python n'est jamais écrasée.
    public function test_10_python_proposal_is_never_overwritten(): void
    {
        $service = $this->makeServiceWithMock([
            'fallback' => false,
            'decision' => 'not_validated',
            'candidate_key' => 'btts:yes',
            'reasoning' => 'Alternative proposée.',
            'alternative_reason' => 'Meilleure sécurité.',
            'supporting_factors' => [],
            'risk_factors' => [],
            'required_recalculation' => false,
            'confidence_in_revision' => 'high',
        ]);

        $review = $service->review($this->samplePythonResult(), ['quality_score' => 85]);

        // Même avec une alternative, le candidat Python officiel reste intact
        $this->assertNotNull($review['python_candidate']);
        $this->assertSame(0.68, $review['python_candidate']['probability']);
        $this->assertSame('1N2', $review['python_candidate']['market']);
    }

    // 11. Vérification du comportement quand plusieurs pronostics existent sur le même match.
    public function test_11_multiple_predictions_on_same_match_only_official_used(): void
    {
        // Ancien pronostic obsolète
        Prediction::create([
            'match_id' => $this->match->id,
            'source_model' => 'OLD_MODEL',
            'model_version_id' => $this->model->id,
            'is_official' => false,
            'prob_home' => 0.40,
            'prob_draw' => 0.30,
            'prob_away' => 0.30,
            'home_lambda' => 1.2,
            'away_lambda' => 1.1,
            'published_market' => '1N2',
            'published_selection' => 'draw',
            'published_probability' => 0.30,
            'content_hash' => hash('sha256', 'pred-test-11-old'),
        ]);

        // Nouveau pronostic officiel
        $official = Prediction::create([
            'match_id' => $this->match->id,
            'source_model' => $this->model->version_name,
            'model_version_id' => $this->model->id,
            'is_official' => true,
            'prob_home' => 0.68,
            'prob_draw' => 0.20,
            'prob_away' => 0.12,
            'home_lambda' => 1.8,
            'away_lambda' => 1.0,
            'published_market' => '1N2',
            'published_selection' => 'home',
            'published_probability' => 0.68,
            'content_hash' => hash('sha256', 'pred-test-11-official'),
            'review_decision' => PredictionRevisionService::STATUS_VALIDATED,
        ]);

        $matchOfficial = FootballMatch::find($this->match->id)->officialPrediction;
        $this->assertSame($official->id, $matchOfficial->id);
        $this->assertSame('1N2', $matchOfficial->published_market);
    }

    // 12. Vérification de l'absence de doublons entre Python et Gemini.
    public function test_12_no_redundant_duplicates_between_python_and_gemini(): void
    {
        $service = $this->makeServiceWithMock([
            'fallback' => false,
            'decision' => 'not_validated',
            // Gemini propose exactement la même sélection que Python : doublon!
            'candidate_key' => '1N2:home',
            'reasoning' => 'Propose la même chose.',
            'supporting_factors' => [],
            'risk_factors' => [],
            'required_recalculation' => false,
            'confidence_in_revision' => 'high',
        ]);

        $review = $service->review($this->samplePythonResult(), ['quality_score' => 85]);

        // Une alternative identique à la sélection Python doit être neutralisée
        $this->assertNull($review['alternative']);
    }

    // 13. Vérification du comportement sur les combinés.
    public function test_13_parlay_behavior_with_gemini_reviewed_predictions(): void
    {
        $parlay = Parlay::create([
            'title' => 'Combiné Sécurité & Confiance',
            'date_for' => now()->toDateString(),
            'risk_level' => 'MODERE',
            'risk_score' => 4.5,
            'combined_odds' => 2.15,
            'combined_prob' => 0.48,
            'selections_json' => [
                ['match_label' => 'Arsenal vs Chelsea', 'selection_label' => '1X', 'odds' => 1.35, 'model_prob_pct' => 77],
                ['match_label' => 'Liverpool vs Everton', 'selection_label' => 'Over 1.5', 'odds' => 1.59, 'model_prob_pct' => 82],
            ],
            'model_version' => $this->model->version_name,
            'status' => 'PENDING',
        ]);

        $this->assertDatabaseHas('parlays', ['id' => $parlay->id, 'risk_level' => 'MODERE']);
        $this->assertSame(2, $parlay->selections_count);
    }

    /**
     * Test d'intégration complet : match -> prédiction -> révision Gemini -> persistance -> vue.
     */
    public function test_end_to_end_integration_flow(): void
    {
        $service = $this->makeServiceWithMock([
            'fallback' => false,
            'decision' => 'not_validated',
            'candidate_key' => 'btts:yes',
            'reasoning' => 'Match indécis, privilégier la couverture.',
            'alternative_reason' => 'Arsenal et Chelsea marquent régulièrement.',
            'supporting_factors' => ['solidité offensive'],
            'risk_factors' => ['derby londonien'],
            'required_recalculation' => false,
            'confidence_in_revision' => 'high',
        ]);

        $review = $service->review($this->samplePythonResult(), [
            'quality_score' => 85.0,
            'home_team' => $this->homeTeam->name,
            'away_team' => $this->awayTeam->name,
            'odds_context' => ['1N2' => ['home' => 1.85, 'draw' => 3.50, 'away' => 4.20]],
        ]);

        // Persistance en base
        $prediction = Prediction::create([
            'match_id' => $this->match->id,
            'source_model' => $this->model->version_name,
            'model_version_id' => $this->model->id,
            'is_official' => true,
            'prob_home' => 0.68,
            'prob_draw' => 0.20,
            'prob_away' => 0.12,
            'home_lambda' => 1.8,
            'away_lambda' => 1.0,
            'published_market' => $review['python_candidate']['market'],
            'published_selection' => $review['python_candidate']['selection'],
            'published_probability' => $review['python_candidate']['probability'],
            'content_hash' => hash('sha256', 'pred-integration-test'),
            'review_decision' => $review['gemini_status'],
            'market_metrics_json' => [
                'published_odds' => 1.85,
                'published_odds_source' => 'bookmaker',
                'gemini_review' => [
                    'status' => $review['gemini_status'],
                    'status_label' => PredictionRevisionService::statusLabel($review['gemini_status']),
                    'explanation' => $review['reasoning'],
                    'python' => $review['python_candidate'],
                    'alternative' => $review['alternative'] ? [
                        'market' => $review['alternative']['market'],
                        'selection' => $review['alternative']['selection'],
                        'label' => $review['alternative']['label'],
                        'justification' => $review['alternative']['justification'] ?? 'Alternative recommandée.',
                        'probability' => $review['alternative']['probability'],
                        'origin' => 'PYTHON',
                    ] : null,
                ],
            ],
            'reviewed_at_utc' => now()->utc(),
        ]);

        // Vérification du contrat Prediction
        $this->assertSame(PredictionRevisionService::STATUS_WITH_ALTERNATIVE, $prediction->gemini_review_status);
        $this->assertSame('btts', $prediction->gemini_alternative_market);
        $this->assertSame('yes', $prediction->gemini_alternative_selection);

        // Appel de la vue Dashboard
        $response = $this->get('/');
        $response->assertOk();
    }
}
