<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RewardItem extends Model
{
    protected $fillable = [
        'name',
        'description',
        'image_url',
        'star_cost',
        'fish_points_cost',
        'stock',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];
}
