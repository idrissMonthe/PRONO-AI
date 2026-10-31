<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TeamRating extends Model
{
    use HasFactory;

    protected $fillable = [
        'team_id',
        'as_of_date',
        'elo_rating',
        'attack_strength',
        'defense_strength',
    ];

    protected $casts = [
        'as_of_date' => 'date',
        'elo_rating' => 'decimal:2',
        'attack_strength' => 'decimal:4',
        'defense_strength' => 'decimal:4',
    ];

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }
}
