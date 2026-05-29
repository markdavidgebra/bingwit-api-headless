<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    protected $fillable = [
        'vendor_id',
        'category_id',
        'brand_id',
        'name',
        'slug',
        'description',
        'price',
        'original_price',
        'stock',
        'condition',
        'is_featured',
        'is_active',
        'rating',
        'reviews_count',
        'views_count',
    ];

    protected $casts = [
        'is_featured' => 'boolean',
        'is_active' => 'boolean',
        'price' => 'decimal:2',
        'rating' => 'decimal:2',
    ];

    // Category this product belongs to
    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    // Brand this product belongs to
    public function brand()
    {
        return $this->belongsTo(Brand::class);
    }

    // All images of this product
    public function images()
    {
        return $this->hasMany(ProductImage::class)
            ->orderBy('sort_order');
    }

    // Primary image only
    public function primaryImage()
    {
        return $this->hasOne(ProductImage::class)
            ->where('is_primary', true);
    }

    // All reviews
    public function reviews()
    {
        return $this->hasMany(Review::class);
    }

    // All wishlists containing this product
    public function wishlists()
    {
        return $this->hasMany(Wishlist::class);
    }

    // Catch posts that tagged this product
    public function catches()
    {
        return $this->belongsToMany(
            FishCatch::class,
            'product_tags',
            'product_id',
            'catch_id'
        );
    }

    // Get primary image URL
    public function getPrimaryImageUrlAttribute()
    {
        $image = $this->primaryImage;
        if ($image) {
            return asset('storage/' . $image->image_path);
        }
        return null;
    }

    // Check if product is on sale
    public function getIsOnSaleAttribute()
    {
        return $this->original_price &&
            $this->original_price > $this->price;
    }

    // Get discount percentage
    public function getDiscountPercentageAttribute()
    {
        if (!$this->is_on_sale)
            return 0;
        return round(
            (($this->original_price - $this->price) /
                $this->original_price) * 100
        );
    }
    // Vendor that owns this product
    public function vendor()
    {
        return $this->belongsTo(Vendor::class, 'vendor_id');
    }
}