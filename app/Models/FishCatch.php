<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class FishCatch extends Model implements HasMedia
{
    use InteractsWithMedia;

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

    /**
     * Always include computed photo URLs in JSON output.
     * They depend on the `media` relation, which controllers
     * should eager-load via ->with('media') to avoid N+1 queries.
     */
    protected $appends = ['media_url', 'media_urls'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function likes()
    {
        return $this->hasMany(Like::class, 'catch_id');
    }

    public function comments()
    {
        return $this->hasMany(Comment::class, 'catch_id');
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('catch_media');
    }

    /**
     * All photo URLs for this catch as a plain array of strings.
     */
    protected function mediaUrls(): Attribute
    {
        return Attribute::get(function () {
            return $this->getMedia('catch_media')
                        ->map(fn ($m) => $m->getUrl())
                        ->values()
                        ->all();
        });
    }

    /**
     * Convenience: first photo URL (or null).
     */
    protected function mediaUrl(): Attribute
    {
        return Attribute::get(function () {
            return $this->media_urls[0] ?? null;
        });
    }
}
