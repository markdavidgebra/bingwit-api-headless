<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Review extends Model
{
    protected $fillable = [
        'user_id',
        'product_id',
        'rating',
        'body',
    ];

    // Who wrote this review
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    // Which product
    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}