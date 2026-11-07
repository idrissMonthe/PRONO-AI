<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SystemAlert extends Model
{
    use HasFactory;

    protected $fillable = [
        'key', 'severity', 'status', 'source', 'message', 'context',
        'last_seen_at_utc', 'resolved_at_utc',
    ];

    protected $casts = [
        'context' => 'array',
        'last_seen_at_utc' => 'datetime',
        'resolved_at_utc' => 'datetime',
    ];

    public static function raise(string $key, string $message, string $severity = 'warning', ?string $source = null, array $context = []): self
    {
        return static::updateOrCreate(
            ['key' => $key, 'status' => 'open'],
            [
                'severity' => $severity,
                'source' => $source,
                'message' => $message,
                'context' => $context,
                'last_seen_at_utc' => now()->utc(),
                'resolved_at_utc' => null,
            ]
        );
    }

    public static function resolve(string $key): void
    {
        static::where('key', $key)->where('status', 'open')->update([
            'status' => 'resolved',
            'resolved_at_utc' => now()->utc(),
            'updated_at' => now(),
        ]);
    }
}
