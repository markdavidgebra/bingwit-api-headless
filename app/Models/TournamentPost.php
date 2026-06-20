<?php

namespace App\Models;

use App\Support\PublicStorageUrl;
use Illuminate\Database\Eloquent\Model;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class TournamentPost extends Model implements HasMedia
{
    use InteractsWithMedia;

    protected $fillable = [
        'tournament_id',
        'admin_id',
        'title',
        'body',
        'cross_post_to_feed',
    ];

    protected $casts = [
        'cross_post_to_feed' => 'boolean',
    ];

    protected $appends = ['image_urls'];

    public function tournament()
    {
        return $this->belongsTo(Tournament::class);
    }

    public function admin()
    {
        return $this->belongsTo(Admin::class, 'admin_id');
    }

    /**
     * Scope: only posts that should leak into the global feed.
     */
    public function scopeAnnouncements($query)
    {
        return $query->where('cross_post_to_feed', true);
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('images')
             ->useDisk('public');
    }

    public function getImageUrlsAttribute(): array
    {
        return $this->getMedia('images')
            ->map(fn ($media) => PublicStorageUrl::fromMedia($media))
            ->filter()
            ->values()
            ->all();
    }
}
