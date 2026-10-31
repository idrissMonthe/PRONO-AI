<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AppSetting extends Model
{
    use HasFactory;

    protected $fillable = [
        'key',
        'value_json',
        'description',
    ];

    protected $casts = [
        'value_json' => 'array',
    ];

    public static function get(string $key, mixed $default = null): mixed
    {
        $setting = static::where('key', $key)->first();
        if (!$setting) {
            return $default;
        }
        return $setting->value_json['value'] ?? $setting->value_json ?? $default;
    }

    public static function set(string $key, mixed $value, ?string $description = null): void
    {
        static::updateOrCreate(
            ['key' => $key],
            [
                'value_json' => is_array($value) ? $value : ['value' => $value],
                'description' => $description ?? $key,
            ]
        );
    }
}

