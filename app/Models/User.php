<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class User extends Authenticatable implements HasMedia
{
    use HasApiTokens, HasFactory, Notifiable, InteractsWithMedia;

    protected $fillable = [
        'name',
        'email',
        'password',
        'profile_picture',
        'bio',
        'location',
        'fishing_style',
        'social_provider',
        'social_id',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password'          => 'hashed',
        ];
    }

    public function catches()
    {
        return $this->hasMany(FishCatch::class);
    }

    public function following()
    {
        return $this->belongsToMany(
            User::class, 'follows',
            'follower_id', 'following_id'
        );
    }

    public function followers()
    {
        return $this->belongsToMany(
            User::class, 'follows',
            'following_id', 'follower_id'
        );
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('profile_picture')
             ->singleFile();
    }
}
