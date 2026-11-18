<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CombinationSnapshotLeg extends Model
{
    protected $fillable = [
        'combination_snapshot_id',
        'prediction_snapshot_id',
        'match_id',
        'market_key',
        'selection',
        'probability',
    ];

    protected $casts = [
        'probability' => 'decimal:6',
    ];

    public function combination(): BelongsTo
    {
        return $this->belongsTo(CombinationSnapshot::class, 'combination_snapshot_id');
    }

    public function snapshot(): BelongsTo
    {
        return $this->belongsTo(PredictionSnapshot::class, 'prediction_snapshot_id');
    }
}
