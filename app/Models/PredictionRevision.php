<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PredictionRevision extends Model
{
    use HasFactory;

    protected $fillable = [
        'initial_prediction_id', 'final_prediction_id', 'decision', 'provider',
        'ai_model', 'initial_candidate', 'ai_recommendation',
        'recalculated_candidate', 'final_candidate', 'reasoning',
        'supporting_factors', 'risk_factors', 'validation', 'reviewed_at_utc',
    ];

    protected $casts = [
        'initial_candidate' => 'array',
        'ai_recommendation' => 'array',
        'recalculated_candidate' => 'array',
        'final_candidate' => 'array',
        'supporting_factors' => 'array',
        'risk_factors' => 'array',
        'validation' => 'array',
        'reviewed_at_utc' => 'datetime',
    ];

    public function initialPrediction(): BelongsTo
    {
        return $this->belongsTo(Prediction::class, 'initial_prediction_id');
    }

    public function finalPrediction(): BelongsTo
    {
        return $this->belongsTo(Prediction::class, 'final_prediction_id');
    }
}
