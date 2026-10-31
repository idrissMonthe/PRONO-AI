<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TeamAlias extends Model
{
    use HasFactory;

    protected $fillable = [
        'team_id',
        'alias',
        'source',
        'confidence',
        'is_validated',
    ];

    protected $casts = [
        'confidence' => 'decimal:2',
        'is_validated' => 'boolean',
    ];

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }
}
