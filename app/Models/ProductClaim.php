<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductClaim extends Model
{
    protected $fillable = [
        'user_id',
        'product_id',
        'stars_spent',
        'status',
        'fulfilled_at',
    ];

    protected $casts = [
        'fulfilled_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
