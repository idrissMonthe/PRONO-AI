<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PredictionHashChain extends Model
{
    use HasFactory;

    protected $fillable = [
        'prediction_id',
        'current_hash',
        'prev_hash',
        'chained_at_utc',
    ];

    protected $casts = [
        'chained_at_utc' => 'datetime',
    ];

    public function prediction(): BelongsTo
    {
        return $this->belongsTo(Prediction::class);
    }
}
