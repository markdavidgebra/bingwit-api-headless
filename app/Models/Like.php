<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Like extends Model
{
    public const TYPE_LIKE = 'like';
    public const TYPE_LOVE = 'love';

    protected $fillable = [
        'user_id',
        'catch_id',
        'type',
    ];
}