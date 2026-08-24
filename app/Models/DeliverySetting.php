<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DeliverySetting extends Model
{
    protected $fillable = [
        'same_city_fee',
        'same_province_fee',
        'luzon_fee',
        'visayas_fee',
        'mindanao_fee',
        'pickup_enabled',
        'delivery_enabled',
    ];

    protected $casts = [
        'same_city_fee' => 'decimal:2',
        'same_province_fee' => 'decimal:2',
        'luzon_fee' => 'decimal:2',
        'visayas_fee' => 'decimal:2',
        'mindanao_fee' => 'decimal:2',
        'pickup_enabled' => 'boolean',
        'delivery_enabled' => 'boolean',
    ];

    public static function current(): self
    {
        return static::query()->first() ?? static::create([]);
    }
}
