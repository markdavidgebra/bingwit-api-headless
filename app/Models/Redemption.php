<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Redemption extends Model
{
    protected $fillable = [
        'user_id',
        'reward_item_id',
        'stars_spent',
        'fish_points_spent',
        'status',
        'fulfilled_at',
    ];

    protected $casts = [
        'fulfilled_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function rewardItem()
    {
        return $this->belongsTo(RewardItem::class);
    }
}
