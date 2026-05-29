<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Category extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'icon',
        'image',
        'description',
        'is_active',
    ];

    // All products in this category
    public function products()
    {
        return $this->hasMany(Product::class);
    }
}