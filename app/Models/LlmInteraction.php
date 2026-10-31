<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LlmInteraction extends Model
{
    use HasFactory;

    protected $fillable = [
        'provider',
        'model',
        'context_hash',
        'sanitized_prompt',
        'response_text',
        'verification_passed',
        'verification_details',
        'interacted_at_utc',
    ];

    protected $casts = [
        'verification_passed' => 'boolean',
        'verification_details' => 'array',
        'interacted_at_utc' => 'datetime',
    ];
}

