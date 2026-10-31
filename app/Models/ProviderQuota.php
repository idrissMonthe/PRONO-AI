<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProviderQuota extends Model
{
    use HasFactory;

    protected $fillable = [
        'provider',
        'daily_limit',
        'calls_used_today',
        'reset_at_utc',
        'is_active',
    ];

    protected $casts = [
        'reset_at_utc' => 'datetime',
        'is_active' => 'boolean',
        'daily_limit' => 'integer',
        'calls_used_today' => 'integer',
    ];
}

