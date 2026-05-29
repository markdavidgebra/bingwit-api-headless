<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SavedSpot extends Model
{
    protected $fillable = [
        'user_id',
        'fishing_spot_id',
    ];
}