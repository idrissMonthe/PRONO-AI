<?php

namespace Tests\Feature;

use App\Models\FootballMatch;
use App\Models\League;
use App\Models\ModelVersion;
use App\Models\Prediction;
use App\Models\PredictionRevision;
use App\Models\Season;
use App\Models\Team;
use App\Services\Prediction\PredictionRevisionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GeminiValidationHistoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_later_review_does_not_overwrite_the_previous_decision(): void
    {
        $league = League::create(['code' => 'PL', 'name' => 'Premier League', 'country' => 'England', 'tier' => '1', 'is_active' => true]);
        $season = Season::create(['league_id' => $league->id, 'name' => '2025-2026', 'year_start' => 2025, 'year_end' => 2026, 'is_current' => true]);
        $home = Team::create(['name' => 'Team Alpha', 'short_name' => 'Alpha', 'country' => 'England']);
        $away = Team::create(['name' => 'Team Beta', 'short_name' => 'Beta', 'country' => 'England']);
        $match = FootballMatch::create([
            'external_id' => 'gemini-history-1',
            'data_source' => 'football-data.org',
            'league_id' => $league->id,
            'season_id' => $season->id,
            'home_team_id' => $home->id,
            'away_team_id' => $away->id,
            'match_date_utc' => now()->addDay(),
            'status' => 'SCHEDULED',
            'quality_score' => 80,
            'quality_tier' => 'usable',
        ]);
        $model = ModelVersion::create([
            'version_name' => 'history-test',
            'algorithm' => 'Dixon-Coles',
            'status' => 'champion',
        ]);

        $first = $this->prediction($match, $model, false);
        $firstFinal = $this->prediction($match, $model, true, [
            'review_decision' => PredictionRevisionService::STATUS_WITH_ALTERNATIVE,
            'published_market' => '1N2',
            'published_selection' => 'home',
            'published_probability' => 0.46,
            'supersedes_id' => $first->id,
            'market_metrics_json' => [
                'gemini_review' => [
                    'status' => PredictionRevisionService::STATUS_WITH_ALTERNATIVE,
                    'status_label' => 'Non validé',
                    'explanation' => 'Le pronostic domicile est fragile.',
                    'python' => ['market' => '1N2', 'selection' => 'home', 'probability' => 0.46, 'origin' => 'PYTHON'],
                    'alternative' => ['market' => 'btts', 'selection' => 'yes', 'origin' => 'PYTHON', 'probability' => 0.63],
                ],
            ],
        ]);
        $firstRevision = PredictionRevision::create([
            'initial_prediction_id' => $first->id,
            'final_prediction_id' => $firstFinal->id,
            'decision' => PredictionRevisionService::STATUS_WITH_ALTERNATIVE,
            'provider' => 'google_gemini',
            'ai_model' => 'test-model',
            'initial_candidate' => ['market' => '1N2', 'selection' => 'home', 'probability' => 0.46],
            'ai_recommendation' => ['market' => 'btts', 'selection' => 'yes', 'origin' => 'PYTHON', 'probability' => 0.63],
            'recalculated_candidate' => ['market' => 'btts', 'selection' => 'yes', 'probability' => 0.63, 'origin' => 'PYTHON'],
            'final_candidate' => ['market' => '1N2', 'selection' => 'home', 'probability' => 0.46],
            'reasoning' => 'Le pronostic domicile est fragile.',
            'validation' => ['accepted' => false, 'gemini_status' => PredictionRevisionService::STATUS_WITH_ALTERNATIVE],
            'reviewed_at_utc' => now()->utc(),
        ]);

        $second = $this->prediction($match, $model, false);
        $secondFinal = $this->prediction($match, $model, true, [
            'review_decision' => PredictionRevisionService::STATUS_VALIDATED,
            'published_market' => '1N2',
            'published_selection' => 'home',
            'published_probability' => 0.70,
            'supersedes_id' => $second->id,
        ]);
        Prediction::where('match_id', $match->id)->where('id', '!=', $secondFinal->id)->update(['is_official' => false]);
        PredictionRevision::create([
            'initial_prediction_id' => $second->id,
            'final_prediction_id' => $secondFinal->id,
            'decision' => PredictionRevisionService::STATUS_VALIDATED,
            'provider' => 'google_gemini',
            'initial_candidate' => ['market' => '1N2', 'selection' => 'home', 'probability' => 0.70],
            'final_candidate' => ['market' => '1N2', 'selection' => 'home', 'probability' => 0.70],
            'reasoning' => 'Cohérent.',
            'validation' => ['accepted' => true, 'gemini_status' => PredictionRevisionService::STATUS_VALIDATED],
            'reviewed_at_utc' => now()->utc(),
        ]);

        $preserved = $firstRevision->fresh();
        $this->assertSame(PredictionRevisionService::STATUS_WITH_ALTERNATIVE, $preserved->decision);
        $this->assertSame('1N2', $preserved->initial_candidate['market']);
        $this->assertSame('btts', $preserved->ai_recommendation['market']);
        $this->assertSame(0.63, $preserved->recalculated_candidate['probability']);
        $this->assertSame('1N2', $preserved->final_candidate['market']);
        $this->assertSame(2, PredictionRevision::count());
        $this->assertSame(PredictionRevisionService::STATUS_VALIDATED, $secondFinal->fresh()->review_decision);

        $secondFinal->forceFill([
            'market_metrics_json' => [
                'gemini_review' => [
                    'status' => PredictionRevisionService::STATUS_VALIDATED,
                    'status_label' => 'Validé',
                    'explanation' => 'Le pronostic Python est cohérent.',
                    'python' => [
                        'market' => '1N2',
                        'selection' => 'home',
                        'label' => 'Victoire domicile',
                        'probability' => 0.70,
                        'probability_label' => '70,0 %',
                        'origin' => 'PYTHON',
                        'origin_label' => 'Python',
                    ],
                    'alternative' => null,
                ],
            ],
        ])->save();

        $this->get(route('matches.show', $match))
            ->assertOk()
            ->assertSee('Décision Gemini')
            ->assertSee('Validé')
            ->assertSee('Pronostic Python')
            ->assertDontSee('Proposition de Gemini');
    }

    private function prediction(FootballMatch $match, ModelVersion $model, bool $official, array $extra = []): Prediction
    {
        return Prediction::create(array_merge([
            'match_id' => $match->id,
            'model_version_id' => $model->id,
            'is_official' => $official,
            'prediction_stage' => $official ? 'final' : 'initial',
            'prediction_origin' => 'PYTHON_ESTIMATE',
            'content_hash' => str_repeat($official ? 'b' : 'a', 64),
            'home_lambda' => 1.4,
            'away_lambda' => 1.1,
            'prob_home' => 0.46,
            'prob_draw' => 0.29,
            'prob_away' => 0.25,
            'quality_score' => 80,
            'quality_tier' => 'usable',
            'confidence_tier' => 'medium',
            'is_abstained' => false,
        ], $extra));
    }
}
