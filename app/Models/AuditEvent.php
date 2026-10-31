<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AuditEvent extends Model
{
    use HasFactory;

    protected $fillable = [
        'event_type',
        'actor',
        'details',
        'occurred_at_utc',
    ];

    protected $casts = [
        'details' => 'array',
        'occurred_at_utc' => 'datetime',
    ];

    public static function log(string $eventType, array $details = [], string $actor = 'system'): self
    {
        return static::create([
            'event_type' => $eventType,
            'actor' => $actor,
            'details' => $details,
            'occurred_at_utc' => now()->utc(),
        ]);
    }
}

