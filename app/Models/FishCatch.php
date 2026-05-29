<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class FishCatch extends Model implements HasMedia
{
    use InteractsWithMedia;

    // Tell Laravel this model uses the catches table
    protected $table = 'catches';

    protected $fillable = [
        'user_id',
        'fish_species',
        'weight_kg',
        'length_cm',
        'bait_used',
        'fishing_method',
        'caption',
        'location',
        'latitude',
        'longitude',
        'media_type',
    ];

    // Who posted this catch
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    // All likes on this catch
    public function likes()
    {
        return $this->hasMany(Like::class, 'catch_id');
    }

    // All comments on this catch
    public function comments()
    {
        return $this->hasMany(Comment::class, 'catch_id');
    }

    // Media collection for catch photos/videos
    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('catch_media');
    }
}