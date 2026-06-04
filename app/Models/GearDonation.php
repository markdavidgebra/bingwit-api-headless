<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GearDonation extends Model
{
    protected $fillable = [
        'donor_id',
        'recipient_id',
        'catch_id',
        'item_name',
        'description',
        'condition',
        'status',
    ];

    public function donor()
    {
        return $this->belongsTo(User::class, 'donor_id');
    }

    public function recipient()
    {
        return $this->belongsTo(User::class, 'recipient_id');
    }

    public function catch()
    {
        return $this->belongsTo(FishCatch::class, 'catch_id');
    }
}
