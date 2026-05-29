<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Notification extends Model
{
    protected $fillable = [
        'user_id',
        'type',
        'title',
        'body',
        'is_read',
        'reference_id',
        'reference_type',
    ];

    protected $casts = [
        'is_read' => 'boolean',
    ];

    // Who owns this notification
    public function user()
    {
        return $this->belongsTo(User::class);
    }
}