<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ApiCallLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'provider',
        'endpoint',
        'status_code',
        'duration_ms',
        'quota_remaining',
        'called_at_utc',
    ];

    protected $casts = [
        'called_at_utc' => 'datetime',
        'status_code' => 'integer',
        'duration_ms' => 'integer',
        'quota_remaining' => 'integer',
    ];
}

