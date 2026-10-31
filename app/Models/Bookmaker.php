<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Bookmaker extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'code',
        'type', // low_margin, high_margin, exchange
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function oddsSnapshots(): HasMany
    {
        return $this->hasMany(OddsSnapshot::class);
    }
}
