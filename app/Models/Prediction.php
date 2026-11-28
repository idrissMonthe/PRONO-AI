<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Prediction extends Model
{
    use HasFactory;

    protected $fillable = [
        'match_id',
        'model_version_id',
        'calibration_version',
        'is_official',
        'prediction_stage',
        'prediction_origin',
        'source_model',
        'ai_estimate_json',
        'missing_data_json',
        'uncertainty_tier',
        'published_market',
        'published_selection',
        'published_line',
        'published_probability',
        'review_decision',
        'review_confidence',
        'reviewed_at_utc',
        'freeze_time_utc',
        'supersedes_id',
        'content_hash',
        'prev_hash',
        'home_lambda',
        'away_lambda',
        'truncated_residual_mass',
        'prob_home',
        'prob_draw',
        'prob_away',
        'intervals_json',
        'derived_markets_json',
        'top_scores_json',
        'quality_score',
        'quality_tier',
        'confidence_tier',
        'is_abstained',
        'warnings_json',
        'market_metrics_json',
        'execution_time_ms',
    ];

    protected $casts = [
        'is_official' => 'boolean',
        'published_line' => 'decimal:2',
        'published_probability' => 'decimal:4',
        'reviewed_at_utc' => 'datetime',
        'is_abstained' => 'boolean',
        'freeze_time_utc' => 'datetime',
        'home_lambda' => 'decimal:4',
        'away_lambda' => 'decimal:4',
        'truncated_residual_mass' => 'decimal:8',
        'prob_home' => 'decimal:4',
        'prob_draw' => 'decimal:4',
        'prob_away' => 'decimal:4',
        'quality_score' => 'decimal:2',
        'intervals_json' => 'array',
        'derived_markets_json' => 'array',
        'top_scores_json' => 'array',
        'warnings_json' => 'array',
        'market_metrics_json' => 'array',
        'ai_estimate_json' => 'array',
        'missing_data_json' => 'array',
        'execution_time_ms' => 'integer',
    ];

    /**
     * Une prédiction déjà inscrite au registre ne peut pas être supprimée :
     * cela laisserait un trou dans la chaîne et invaliderait tous les blocs
     * suivants. Les opérations de maintenance doivent passer par une
     * reconstruction explicite du registre.
     */
    protected static function booted(): void
    {
        static::deleting(function (Prediction $prediction): void {
            if ($prediction->hashChain()->exists()) {
                throw new RuntimeException(
                    'Cette prédiction est chaînée au registre d’intégrité et ne peut pas être supprimée.'
                );
            }
        });
    }

    public function match(): BelongsTo
    {
        return $this->belongsTo(FootballMatch::class, 'match_id');
    }

    public function modelVersion(): BelongsTo
    {
        return $this->belongsTo(ModelVersion::class);
    }

    public function featureSnapshot(): HasOne
    {
        return $this->hasOne(FeatureSnapshot::class);
    }

    public function hashChain(): HasOne
    {
        return $this->hasOne(PredictionHashChain::class);
    }

    /**
     * Règlements enregistrés par marché (clé `published` pour la sélection
     * publiée). Source unique du verdict affiché dans l'historique.
     */
    public function evaluations(): HasMany
    {
        return $this->hasMany(PredictionEvaluation::class);
    }

    public function supersedes(): BelongsTo
    {
        return $this->belongsTo(Prediction::class, 'supersedes_id');
    }

    public function revision(): HasOne
    {
        return $this->hasOne(PredictionRevision::class, 'final_prediction_id');
    }

    public function initialRevision(): HasOne
    {
        return $this->hasOne(PredictionRevision::class, 'initial_prediction_id');
    }

    /**
     * Contrat de données multimarchés et révision Gemini.
     */
    public function getPythonPredictionAttribute(): ?array
    {
        if (! $this->published_market || ! $this->published_selection) {
            return null;
        }

        $storedPython = data_get($this->market_metrics_json, 'gemini_review.python');
        $label = $storedPython['label'] ?? ($this->published_market . ' ' . $this->published_selection);

        return [
            'market' => $this->published_market,
            'selection' => $this->published_selection,
            'line' => $this->published_line !== null ? (float) $this->published_line : null,
            'probability' => $this->published_probability !== null ? (float) $this->published_probability : null,
            'label' => $label,
            'odds' => isset($this->market_metrics_json['published_odds']) ? (float) $this->market_metrics_json['published_odds'] : null,
            'odds_source' => $this->market_metrics_json['published_odds_source'] ?? null,
            'origin' => 'PYTHON',
        ];
    }

    public function getGeminiReviewStatusAttribute(): string
    {
        $status = data_get($this->market_metrics_json, 'gemini_review.status')
            ?: $this->review_decision;

        return (string) ($status ?: 'not_evaluated');
    }

    public function getGeminiReasonAttribute(): ?string
    {
        return data_get($this->market_metrics_json, 'gemini_review.explanation')
            ?: data_get($this->ai_estimate_json, 'justification');
    }

    public function getGeminiAlternativeMarketAttribute(): ?string
    {
        return data_get($this->market_metrics_json, 'gemini_review.alternative.market')
            ?: data_get($this->ai_estimate_json, 'market');
    }

    public function getGeminiAlternativeSelectionAttribute(): ?string
    {
        return data_get($this->market_metrics_json, 'gemini_review.alternative.selection')
            ?: data_get($this->ai_estimate_json, 'selection');
    }

    public function getGeminiAlternativeReasonAttribute(): ?string
    {
        return data_get($this->market_metrics_json, 'gemini_review.alternative.justification')
            ?: data_get($this->ai_estimate_json, 'justification');
    }

    public function getGeminiEvaluatedAtAttribute(): ?\Carbon\Carbon
    {
        return $this->reviewed_at_utc;
    }
}
