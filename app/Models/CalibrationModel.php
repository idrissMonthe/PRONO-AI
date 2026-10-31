<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CalibrationModel extends Model
{
    use HasFactory;

    protected $fillable = [
        'version_name',
        'method', // temperature_scaling, multinomial_logistic, isotonic, dirichlet
        'parameters',
        'validation_range',
        'metrics_before',
        'metrics_after',
    ];

    protected $casts = [
        'parameters' => 'array',
        'metrics_before' => 'array',
        'metrics_after' => 'array',
    ];
}

