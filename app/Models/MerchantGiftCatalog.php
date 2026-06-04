<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MerchantGiftCatalog extends Model
{
    protected $table = 'merchant_gift_catalog';

    protected $fillable = [
        'name',
        'description',
        'emoji',
        'fish_points_cost',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];
}
