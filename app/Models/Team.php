<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Team extends Model
{
    use HasFactory;

    protected $fillable = [
        'league_id',
        'name',
        'short_name',
        'code',
        'country',
        'logo_path',
    ];

    public function league(): BelongsTo
    {
        return $this->belongsTo(League::class);
    }

    public function aliases(): HasMany
    {
        return $this->hasMany(TeamAlias::class);
    }

    public function homeMatches(): HasMany
    {
        return $this->hasMany(FootballMatch::class, 'home_team_id');
    }

    public function awayMatches(): HasMany
    {
        return $this->hasMany(FootballMatch::class, 'away_team_id');
    }

    public function ratings(): HasMany
    {
        return $this->hasMany(TeamRating::class);
    }

    public function stats(): HasMany
    {
        return $this->hasMany(TeamMatchStat::class);
    }
}
