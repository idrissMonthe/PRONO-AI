<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Parlay extends Model
{
    use HasFactory;

    protected $table = 'parlays';

    protected $fillable = [
        'title',
        'date_for',
        'risk_level',
        'risk_score',
        'combined_odds',
        'combined_prob',
        'expected_value',
        'selections_json',
        'model_version',
        'ai_analysis',
        'ai_model_used',
        'status',
    ];

    protected $casts = [
        'date_for' => 'date',
        'risk_score' => 'float',
        'combined_odds' => 'float',
        'combined_prob' => 'float',
        'expected_value' => 'float',
        'selections_json' => 'array',
    ];

    public function getSelectionsCountAttribute(): int
    {
        return is_array($this->selections_json) ? count($this->selections_json) : 0;
    }
}
