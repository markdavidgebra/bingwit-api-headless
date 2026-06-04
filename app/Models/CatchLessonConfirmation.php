<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CatchLessonConfirmation extends Model
{
    protected $fillable = ['user_id', 'catch_id', 'note'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function catch()
    {
        return $this->belongsTo(FishCatch::class, 'catch_id');
    }
}
