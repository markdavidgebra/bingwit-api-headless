<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Brand extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'logo',
        'description',
        'is_active',
    ];

    // All products of this brand
    public function products()
    {
        return $this->hasMany(Product::class);
    }
}