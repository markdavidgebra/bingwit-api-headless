<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ResortReview extends Model
{
    protected $fillable = [
        'resort_id',
        'user_id',
        'rating',
        'body',
    ];

    public function resort()
    {
        return $this->belongsTo(Resort::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
