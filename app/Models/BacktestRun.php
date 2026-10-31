<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BacktestRun extends Model
{
    use HasFactory;

    protected $fillable = [
        'model_version_id',
        'family',
        'config_hash',
        'data_hash',
        'code_hash',
        'period_start',
        'period_end',
        'time_step',
        'metrics',
        'report_text',
        'status',
    ];

    protected $casts = [
        'period_start' => 'date',
        'period_end' => 'date',
        'metrics' => 'array',
    ];

    public function modelVersion(): BelongsTo
    {
        return $this->belongsTo(ModelVersion::class);
    }
}

