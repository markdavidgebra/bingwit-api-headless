<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Group extends Model
{
    protected $fillable = [
        'creator_id',
        'name',
        'description',
        'cover_image',
        'category',
        'privacy',
    ];

    // Who created this group
    public function creator()
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    // All members
    public function members()
    {
        return $this->hasMany(GroupMember::class);
    }

    // All posts in this group
    public function posts()
    {
        return $this->hasMany(GroupPost::class);
    }

    // Check if user is member
    public function isMember($userId)
    {
        return $this->members()->where('user_id', $userId)->exists();
    }
}