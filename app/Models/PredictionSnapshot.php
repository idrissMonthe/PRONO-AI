<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class PredictionSnapshot extends Model
{
    protected $fillable = [
        'forecast_run_id',
        'prediction_id',
        'match_id',
        'market_key',
        'market_group',
        'selection',
        'line',
        'category',
        'probability',
        'fair_odds',
        'odds_at_publication',
        'odds_provider',
        'odds_timestamp_utc',
        'model_version',
        'calibration_version',
        'feature_version',
        'coverage_status',
        'reliability',
        'data_sources',
        'context_json',
        'published_at_utc',
        'content_hash',
    ];

    protected $casts = [
        'line' => 'decimal:2',
        'probability' => 'decimal:6',
        'fair_odds' => 'decimal:3',
        'odds_at_publication' => 'decimal:4',
        'odds_timestamp_utc' => 'datetime',
        'published_at_utc' => 'datetime',
        'data_sources' => 'array',
        'context_json' => 'array',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(ForecastRun::class, 'forecast_run_id');
    }

    public function match(): BelongsTo
    {
        return $this->belongsTo(FootballMatch::class, 'match_id');
    }

    public function settlement(): HasOne
    {
        return $this->hasOne(PredictionSettlement::class);
    }
}
