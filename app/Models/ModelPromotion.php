<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ModelPromotion extends Model
{
    use HasFactory;

    protected $fillable = [
        'source_version_id',
        'target_version_id',
        'decision', // promote, reject, rollback
        'evaluated_criteria',
        'mode', // manual, automatic
        'rollback_of_id',
        'decided_at',
    ];

    protected $casts = [
        'evaluated_criteria' => 'array',
        'decided_at' => 'datetime',
    ];

    public function sourceVersion(): BelongsTo
    {
        return $this->belongsTo(ModelVersion::class, 'source_version_id');
    }

    public function targetVersion(): BelongsTo
    {
        return $this->belongsTo(ModelVersion::class, 'target_version_id');
    }
}

