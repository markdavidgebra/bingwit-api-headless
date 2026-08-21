<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Resort extends Model
{
    protected $fillable = [
        'name',
        'description',
        'location',
        'municipality_id',
        'latitude',
        'longitude',
        'contact_phone',
        'contact_email',
        'has_fishing_area',
        'has_gear_rental',
        'is_verified',
        'is_active',
        'rating',
        'reviews_count',
    ];

    protected $casts = [
        'has_fishing_area' => 'boolean',
        'has_gear_rental' => 'boolean',
        'is_verified' => 'boolean',
        'is_active' => 'boolean',
        'rating' => 'decimal:2',
        'latitude' => 'float',
        'longitude' => 'float',
    ];

    public function municipality()
    {
        return $this->belongsTo(Municipality::class);
    }

    public function reviews()
    {
        return $this->hasMany(ResortReview::class);
    }
}
