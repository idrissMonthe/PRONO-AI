<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ImportRun extends Model
{
    protected $fillable = [
        'source',
        'scope',
        'status',
        'received',
        'imported',
        'updated',
        'rejected',
        'divergent',
        'skipped',
        'error',
        'metadata',
        'started_at_utc',
        'finished_at_utc',
    ];

    protected $casts = [
        'received' => 'integer',
        'imported' => 'integer',
        'updated' => 'integer',
        'rejected' => 'integer',
        'divergent' => 'integer',
        'skipped' => 'integer',
        'metadata' => 'array',
        'started_at_utc' => 'datetime',
        'finished_at_utc' => 'datetime',
    ];

    public static function begin(string $source, ?string $scope = null, array $metadata = []): self
    {
        return static::create([
            'source' => $source,
            'scope' => $scope,
            'status' => 'running',
            'metadata' => $metadata ?: null,
            'started_at_utc' => now()->utc(),
        ]);
    }

    public function finish(string $status, array $counters, ?string $error = null): void
    {
        $this->forceFill([
            'status' => $status,
            'received' => $counters['received'] ?? $this->received ?? 0,
            'imported' => $counters['imported'] ?? $this->imported ?? 0,
            'updated' => $counters['updated'] ?? $this->updated ?? 0,
            'rejected' => $counters['rejected'] ?? $this->rejected ?? 0,
            'divergent' => $counters['divergent'] ?? $this->divergent ?? 0,
            'skipped' => $counters['skipped'] ?? $this->skipped ?? 0,
            'error' => $error,
            'finished_at_utc' => now()->utc(),
            'metadata' => array_merge((array) $this->metadata, $counters['metadata'] ?? []),
        ])->save();
    }
}
