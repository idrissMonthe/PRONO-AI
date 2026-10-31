<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Prediction extends Model
{
    use HasFactory;

    protected $fillable = [
        'match_id',
        'model_version_id',
        'calibration_version',
        'is_official',
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
        'execution_time_ms',
    ];

    protected $casts = [
        'is_official' => 'boolean',
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
        'execution_time_ms' => 'integer',
    ];

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

    public function supersedes(): BelongsTo
    {
        return $this->belongsTo(Prediction::class, 'supersedes_id');
    }
}
