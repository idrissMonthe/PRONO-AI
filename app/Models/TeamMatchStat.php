<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TeamMatchStat extends Model
{
    use HasFactory;

    protected $fillable = [
        'match_id',
        'team_id',
        'is_home',
        'shots',
        'shots_on_target',
        'corners',
        'fouls',
        'yellow_cards',
        'red_cards',
        'xg',
        'possession_pct',
    ];

    protected $casts = [
        'is_home' => 'boolean',
        'xg' => 'decimal:2',
        'possession_pct' => 'decimal:2',
    ];

    public function match(): BelongsTo
    {
        return $this->belongsTo(FootballMatch::class, 'match_id');
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }
}
