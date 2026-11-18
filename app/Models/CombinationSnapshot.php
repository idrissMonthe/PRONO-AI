<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CombinationSnapshot extends Model
{
    protected $fillable = [
        'forecast_run_id',
        'parlay_id',
        'title',
        'risk_level',
        'combined_probability',
        'combined_odds',
        'expected_value',
        'model_version',
        'target_date',
        'legs_count',
        'status',
        'published_at_utc',
        'settled_at_utc',
        'content_hash',
        'context_json',
    ];

    protected $casts = [
        'combined_probability' => 'decimal:6',
        'combined_odds' => 'decimal:4',
        'expected_value' => 'decimal:4',
        'target_date' => 'date',
        'published_at_utc' => 'datetime',
        'settled_at_utc' => 'datetime',
        'context_json' => 'array',
    ];

    public function legs(): HasMany
    {
        return $this->hasMany(CombinationSnapshotLeg::class);
    }
}
