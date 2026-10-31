<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OddsSnapshot extends Model
{
    use HasFactory;

    protected $fillable = [
        'match_id',
        'bookmaker_id',
        'market_id',
        'selection',
        'line',
        'decimal_odds',
        'snapshot_time_utc',
        'is_opening',
        'is_closing',
        'source',
        'raw_hash',
    ];

    protected $casts = [
        'snapshot_time_utc' => 'datetime',
        'is_opening' => 'boolean',
        'is_closing' => 'boolean',
        'line' => 'decimal:2',
        'decimal_odds' => 'decimal:4',
    ];

    public function match(): BelongsTo
    {
        return $this->belongsTo(FootballMatch::class, 'match_id');
    }

    public function bookmaker(): BelongsTo
    {
        return $this->belongsTo(Bookmaker::class);
    }

    public function market(): BelongsTo
    {
        return $this->belongsTo(Market::class);
    }
}
