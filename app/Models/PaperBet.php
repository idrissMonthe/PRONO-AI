<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaperBet extends Model
{
    use HasFactory;

    protected $fillable = [
        'prediction_id',
        'match_id',
        'market',
        'selection',
        'line',
        'placed_odds',
        'closing_odds',
        'odds_snapshot_id',
        'staking_rule',
        'stake_units',
        'status', // PENDING, WON, LOST, VOID, CANCELLED
        'virtual_profit',
        'clv',
        'placed_at_utc',
        'settled_at_utc',
    ];

    protected $casts = [
        'line' => 'decimal:2',
        'placed_odds' => 'decimal:4',
        'closing_odds' => 'decimal:4',
        'stake_units' => 'decimal:4',
        'virtual_profit' => 'decimal:4',
        'clv' => 'decimal:4',
        'placed_at_utc' => 'datetime',
        'settled_at_utc' => 'datetime',
    ];

    public function prediction(): BelongsTo
    {
        return $this->belongsTo(Prediction::class);
    }

    public function match(): BelongsTo
    {
        return $this->belongsTo(FootballMatch::class, 'match_id');
    }

    public function oddsSnapshot(): BelongsTo
    {
        return $this->belongsTo(OddsSnapshot::class);
    }
}
