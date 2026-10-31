<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlayerAvailability extends Model
{
    use HasFactory;

    protected $fillable = [
        'match_id', 'team_id', 'player_name', 'status', 'source', 'reported_at_utc',
    ];

    protected $casts = ['reported_at_utc' => 'datetime'];

    public function match(): BelongsTo
    {
        return $this->belongsTo(FootballMatch::class, 'match_id');
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }
}
