<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WalletTransaction extends Model
{
    protected $fillable = [
        'user_id',
        'type',
        'fish_points_delta',
        'stars_delta',
        'reference_type',
        'reference_id',
        'note',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
