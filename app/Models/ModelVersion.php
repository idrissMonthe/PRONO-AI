<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ModelVersion extends Model
{
    use HasFactory;

    protected $fillable = [
        'version_name',
        'family', // pure, hybrid
        'algorithm', // Poisson, Dixon-Coles, Elo, Ensemble
        'hyperparameters',
        'data_hash',
        'config_hash',
        'artifact_hash',
        'code_hash',
        'training_range_start',
        'training_range_end',
        'validation_metrics',
        'test_metrics',
        'status', // candidate, shadow, champion, archived, rejected
        'decision_reason',
        'decided_at',
        'decided_by',
    ];

    protected $casts = [
        'hyperparameters' => 'array',
        'validation_metrics' => 'array',
        'test_metrics' => 'array',
        'decided_at' => 'datetime',
        'training_range_start' => 'date',
        'training_range_end' => 'date',
    ];

    public function predictions(): HasMany
    {
        return $this->hasMany(Prediction::class);
    }

    public function backtestRuns(): HasMany
    {
        return $this->hasMany(BacktestRun::class);
    }

    public function driftReports(): HasMany
    {
        return $this->hasMany(DriftReport::class);
    }
}
