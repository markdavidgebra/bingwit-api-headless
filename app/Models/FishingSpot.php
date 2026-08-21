<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FishingSpot extends Model
{
    protected $fillable = [
        'user_id',
        'municipality_id',
        'name',
        'description',
        'latitude',
        'longitude',
        'spot_type',
    ];

    // Who added this spot
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    // Users who saved this spot
    public function savedBy()
    {
        return $this->hasMany(SavedSpot::class);
    }
}