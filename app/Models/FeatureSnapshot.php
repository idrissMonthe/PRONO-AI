<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FeatureSnapshot extends Model
{
    use HasFactory;

    protected $fillable = [
        'prediction_id',
        'as_of',
        'variables',
        'snapshot_hash',
        'missing_indicators',
    ];

    protected $casts = [
        'as_of' => 'datetime',
        'variables' => 'array',
        'missing_indicators' => 'array',
    ];

    public function prediction(): BelongsTo
    {
        return $this->belongsTo(Prediction::class);
    }
}
