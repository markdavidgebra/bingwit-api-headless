<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MerchantGift extends Model
{
    protected $fillable = [
        'sender_id',
        'vendor_id',
        'catalog_item_id',
        'fish_points_spent',
        'message',
    ];

    public function sender()
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    public function vendor()
    {
        return $this->belongsTo(Vendor::class);
    }

    public function catalogItem()
    {
        return $this->belongsTo(MerchantGiftCatalog::class, 'catalog_item_id');
    }
}
