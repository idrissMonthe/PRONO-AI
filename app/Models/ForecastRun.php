<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ForecastRun extends Model
{
    protected $fillable = [
        'match_id',
        'match_date_utc',
        'competition_code',
        'season',
        'generated_at_utc',
        'model_version',
        'calibration_version',
        'feature_version',
        'features_as_of',
        'data_sources',
        'payload_hash',
        'prediction_count',
        'status',
        'context_json',
    ];

    protected $casts = [
        'match_date_utc' => 'datetime',
        'generated_at_utc' => 'datetime',
        'features_as_of' => 'datetime',
        'data_sources' => 'array',
        'context_json' => 'array',
    ];

    public function match(): BelongsTo
    {
        return $this->belongsTo(FootballMatch::class, 'match_id');
    }

    public function snapshots(): HasMany
    {
        return $this->hasMany(PredictionSnapshot::class);
    }
}
