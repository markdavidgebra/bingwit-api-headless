<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class Admin extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'profile_picture',
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

    // Always include the public URL when this model is serialized.
    protected $appends = ['profile_picture_url'];

    public function getProfilePictureUrlAttribute(): ?string
    {
        if (! $this->profile_picture) {
            return null;
        }

        // If somehow a full URL is already stored, return it as-is.
        if (preg_match('#^https?://#i', $this->profile_picture)) {
            return $this->profile_picture;
        }

        return asset('storage/' . $this->profile_picture);
    }

    public function isDeveloper(): bool
    {
        return $this->role === 'developer';
    }

    public function canUse(string $function): bool
    {
        if ($this->isDeveloper()) {
            return true;
        }

        return in_array($function, StaffRole::permissionsFor($this->role ?: 'admin'), true);
    }

    public function withStaffPermissions(): static
    {
        $this->setAttribute(
            'permissions',
            StaffRole::permissionsFor($this->role ?: 'admin')
        );

        return $this;
    }
}
