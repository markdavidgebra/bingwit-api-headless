<?php

namespace App\Models;

use App\Support\PublicStorageUrl;
use Illuminate\Database\Eloquent\Model;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class Tournament extends Model implements HasMedia
{
    use InteractsWithMedia;

    protected $fillable = [
        'admin_id',
        'name',
        'slug',
        'description',
        'location',
        'prize_pool',
        'entry_fee',
        'max_participants',
        'starts_at',
        'ends_at',
        'registration_deadline',
        'status',
        'cover_image',
    ];

    protected $casts = [
        'starts_at'             => 'datetime',
        'ends_at'               => 'datetime',
        'registration_deadline' => 'datetime',
        'prize_pool'            => 'decimal:2',
        'entry_fee'             => 'decimal:2',
    ];

    /**
     * Computed cover URL — prefer Spatie media, fall back to the
     * `cover_image` column (so admins can paste a URL too).
     */
    protected $appends = ['cover_url'];

    public function admin()
    {
        return $this->belongsTo(Admin::class, 'admin_id');
    }

    public function participants()
    {
        return $this->hasMany(TournamentParticipant::class);
    }

    public function posts()
    {
        return $this->hasMany(TournamentPost::class)->latest();
    }

    public function days()
    {
        return $this->hasMany(TournamentDay::class)->orderBy('day_date');
    }

    public function isParticipant($userId): bool
    {
        if (! $userId) {
            return false;
        }
        return $this->participants()
                    ->where('user_id', $userId)
                    ->whereIn('status', ['registered', 'confirmed'])
                    ->exists();
    }

    public function getCoverUrlAttribute(): ?string
    {
        $media = $this->getFirstMedia('cover');
        if ($media) {
            return PublicStorageUrl::fromMedia($media);
        }

        return PublicStorageUrl::normalize($this->cover_image);
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('cover')
             ->singleFile()
             ->useDisk('public');
    }
}
