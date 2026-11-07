<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class FootballMatch extends Model
{
    use HasFactory;

    protected $table = 'matches';

    protected $fillable = [
        'external_id',
        'data_source',
        'last_synced_at',
        'league_id',
        'season_id',
        'home_team_id',
        'away_team_id',
        'match_date_utc',
        'status',
        'home_score',
        'away_score',
        'ht_home_score',
        'ht_away_score',
        'is_neutral_ground',
        'round',
        'quality_score',
        'quality_tier',
        'quality_breakdown',
    ];

    protected $casts = [
        'match_date_utc' => 'datetime',
        'last_synced_at' => 'datetime',
        'is_neutral_ground' => 'boolean',
        'quality_score' => 'decimal:2',
        'quality_breakdown' => 'array',
        'home_score' => 'integer',
        'away_score' => 'integer',
        'ht_home_score' => 'integer',
        'ht_away_score' => 'integer',
    ];

    public function league(): BelongsTo
    {
        return $this->belongsTo(League::class);
    }

    public function season(): BelongsTo
    {
        return $this->belongsTo(Season::class);
    }

    public function homeTeam(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'home_team_id');
    }

    public function awayTeam(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'away_team_id');
    }

    public function stats(): HasMany
    {
        return $this->hasMany(TeamMatchStat::class, 'match_id');
    }

    public function predictions(): HasMany
    {
        return $this->hasMany(Prediction::class, 'match_id');
    }

    public function officialPrediction(): HasOne
    {
        return $this->hasOne(Prediction::class, 'match_id')->where('is_official', true)->latestOfMany();
    }

    public function latestPrediction(): HasOne
    {
        return $this->hasOne(Prediction::class, 'match_id')->latestOfMany();
    }

    public function oddsSnapshots(): HasMany
    {
        return $this->hasMany(OddsSnapshot::class, 'match_id');
    }

    public function paperBets(): HasMany
    {
        return $this->hasMany(PaperBet::class, 'match_id');
    }

    public function playerAvailabilities(): HasMany
    {
        return $this->hasMany(PlayerAvailability::class, 'match_id');
    }
}
