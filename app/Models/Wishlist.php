<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Wishlist extends Model
{
    protected $fillable = [
        'user_id',
        'product_id',
    ];

    // The product in this wishlist item
    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    // The user who wishlisted
    public function user()
    {
        return $this->belongsTo(User::class);
    }
}