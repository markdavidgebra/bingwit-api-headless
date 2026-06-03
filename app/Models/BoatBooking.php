<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BoatBooking extends Model
{
    protected $fillable = [
        'fishing_boat_id',
        'user_id',
        'trip_at',
        'passengers_count',
        'notes',
        'total_price',
        'status',
    ];

    protected $casts = [
        'trip_at'      => 'datetime',
        'total_price'  => 'decimal:2',
    ];

    public function boat()
    {
        return $this->belongsTo(FishingBoat::class, 'fishing_boat_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
