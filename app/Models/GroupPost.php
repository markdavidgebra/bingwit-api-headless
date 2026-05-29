<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GroupPost extends Model
{
    protected $fillable = [
        'group_id',
        'user_id',
        'catch_id',
        'body',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function group()
    {
        return $this->belongsTo(Group::class);
    }

    public function catch()
    {
        return $this->belongsTo(FishCatch::class, 'catch_id');
    }
}