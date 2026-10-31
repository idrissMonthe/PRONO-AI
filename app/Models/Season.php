<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Season extends Model
{
    use HasFactory;

    protected $fillable = [
        'league_id',
        'name',
        'year_start',
        'year_end',
        'is_current',
    ];

    protected $casts = [
        'is_current' => 'boolean',
        'year_start' => 'integer',
        'year_end' => 'integer',
    ];

    public function league(): BelongsTo
    {
        return $this->belongsTo(League::class);
    }

    public function matches(): HasMany
    {
        return $this->hasMany(FootballMatch::class);
    }
}
