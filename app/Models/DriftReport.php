<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DriftReport extends Model
{
    use HasFactory;

    protected $fillable = [
        'model_version_id',
        'window_start',
        'window_end',
        'psi_metrics',
        'rolling_calibration_ece',
        'rolling_rps',
        'alert_level',
    ];

    protected $casts = [
        'window_start' => 'date',
        'window_end' => 'date',
        'psi_metrics' => 'array',
        'rolling_calibration_ece' => 'decimal:4',
        'rolling_rps' => 'decimal:4',
    ];

    public function modelVersion(): BelongsTo
    {
        return $this->belongsTo(ModelVersion::class);
    }
}

