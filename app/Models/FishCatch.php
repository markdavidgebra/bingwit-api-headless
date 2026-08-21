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
        'tournament_day_id',
        'fish_species',
        'weight_kg',
        'length_cm',
        'bait_used',
        'fishing_method',
        'caption',
        'fishing_lesson',
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

    public function tournamentDay()
    {
        return $this->belongsTo(TournamentDay::class);
    }

    public function likes()
    {
        return $this->hasMany(Like::class, 'catch_id');
    }

    public function scopeWithReactionCounts($query)
    {
        return $query->withCount([
            'comments',
            'likes as likes_count' => fn ($q) => $q->where('type', Like::TYPE_LIKE),
            'likes as loves_count' => fn ($q) => $q->where('type', Like::TYPE_LOVE),
        ]);
    }

    public function comments()
    {
        return $this->hasMany(Comment::class, 'catch_id');
    }

    public function lessonConfirmations()
    {
        return $this->hasMany(CatchLessonConfirmation::class, 'catch_id');
    }

    public function starGifts()
    {
        return $this->hasMany(CatchStarGift::class, 'catch_id');
    }

    /** Marketplace products tagged as tackle used on this catch. */
    public function taggedProducts()
    {
        return $this->belongsToMany(
            Product::class,
            'product_tags',
            'catch_id',
            'product_id'
        )->withTimestamps();
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
            $seenHashes = [];

            return $this->getMedia('catch_media')
                        ->filter(function ($media) use (&$seenHashes) {
                            try {
                                $hash = md5_file($media->getPath());
                            } catch (\Throwable) {
                                return true;
                            }

                            if (isset($seenHashes[$hash])) {
                                return false;
                            }

                            $seenHashes[$hash] = true;

                            return true;
                        })
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
