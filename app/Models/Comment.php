<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Comment extends Model
{
    protected $fillable = [
        'user_id',
        'catch_id',
        'body',
    ];

    // Who wrote this comment
    public function user()
    {
        return $this->belongsTo(User::class);
    }
}