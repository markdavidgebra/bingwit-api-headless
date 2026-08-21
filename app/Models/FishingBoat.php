<?php

namespace App\Models;

use App\Support\PublicStorageUrl;
use Illuminate\Database\Eloquent\Model;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class FishingBoat extends Model implements HasMedia
{
    use InteractsWithMedia;

    protected $fillable = [
        'admin_id',
        'municipality_id',
        'name',
        'slug',
        'description',
        'location',
        'departure_point',
        'capacity',
        'trip_price',
        'duration_hours',
        'captain_name',
        'contact_phone',
        'status',
        'cover_image',
        'cover_focus_x',
        'cover_focus_y',
    ];

    protected $casts = [
        'trip_price'     => 'decimal:2',
        'cover_focus_x'  => 'integer',
        'cover_focus_y'  => 'integer',
    ];

    protected $appends = ['cover_url'];

    public function admin()
    {
        return $this->belongsTo(Admin::class, 'admin_id');
    }

    public function bookings()
    {
        return $this->hasMany(BoatBooking::class);
    }

    public function activeBookings()
    {
        return $this->bookings()->whereIn('status', ['pending', 'confirmed']);
    }

    public function hasActiveBookingForUser(?int $userId): bool
    {
        if (! $userId) {
            return false;
        }

        return $this->activeBookings()->where('user_id', $userId)->exists();
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
