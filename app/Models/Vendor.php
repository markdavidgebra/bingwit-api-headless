<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class Vendor extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'store_name',
        'store_slug',
        'store_description',
        'store_logo',
        'store_banner',
        'contact_phone',
        'address',
        'is_active',
        'is_verified',
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
            'is_active'         => 'boolean',
            'is_verified'       => 'boolean',
        ];
    }

    public function products()
    {
        return $this->hasMany(Product::class, 'vendor_id');
    }

    public function gifts()
    {
        return $this->hasMany(MerchantGift::class);
    }

    public function getTotalProductsAttribute()
    {
        return $this->products()->count();
    }

    public function getTotalViewsAttribute()
    {
        return $this->products()->sum('views_count');
    }
}
