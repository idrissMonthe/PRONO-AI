<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PredictionEvaluation extends Model
{
    protected $fillable = [
        'prediction_id', 'match_id', 'market_group', 'market_key',
        'predicted_label', 'predicted_probability', 'actual_label',
        'status', 'evaluated_at_utc',
    ];

    protected $casts = [
        'predicted_probability' => 'decimal:4',
        'evaluated_at_utc' => 'datetime',
    ];

    public function prediction(): BelongsTo
    {
        return $this->belongsTo(Prediction::class);
    }

    public function match(): BelongsTo
    {
        return $this->belongsTo(FootballMatch::class, 'match_id');
    }
}
