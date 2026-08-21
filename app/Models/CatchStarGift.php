<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CatchStarGift extends Model
{
    protected $fillable = ['giver_id', 'catch_id', 'fish_points', 'stars', 'message'];

    public function giver()
    {
        return $this->belongsTo(User::class, 'giver_id');
    }

    public function catch()
    {
        return $this->belongsTo(FishCatch::class, 'catch_id');
    }
}
