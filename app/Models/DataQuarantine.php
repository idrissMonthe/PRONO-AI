<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DataQuarantine extends Model
{
    use HasFactory;

    protected $fillable = [
        'source',
        'entity_type',
        'raw_hash',
        'rejection_reason',
        'raw_payload',
        'quarantined_at_utc',
    ];

    protected $casts = [
        'raw_payload' => 'array',
        'quarantined_at_utc' => 'datetime',
    ];
}

