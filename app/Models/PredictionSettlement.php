<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PredictionSettlement extends Model
{
    protected $fillable = [
        'prediction_snapshot_id',
        'outcome',
        'actual_label',
        'home_score',
        'away_score',
        'settled_at_utc',
        'source',
        'notes',
    ];

    protected $casts = [
        'settled_at_utc' => 'datetime',
    ];

    public function snapshot(): BelongsTo
    {
        return $this->belongsTo(PredictionSnapshot::class, 'prediction_snapshot_id');
    }
}
