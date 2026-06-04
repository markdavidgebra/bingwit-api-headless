<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MerchantGift;
use App\Models\MerchantGiftCatalog;
use App\Models\Vendor;
use App\Services\WalletService;
use Illuminate\Http\Request;

class MerchantGiftController extends Controller
{
    public function __construct(private WalletService $wallet)
    {
    }

    public function catalog()
    {
        $items = MerchantGiftCatalog::where('is_active', true)
            ->orderBy('fish_points_cost')
            ->get();

        return response()->json(['catalog' => $items]);
    }

    public function send(Request $request, $vendorId)
    {
        $request->validate([
            'catalog_item_id' => 'required|exists:merchant_gift_catalog,id',
            'message'         => 'nullable|string|max:500',
        ]);

        $vendor = Vendor::findOrFail($vendorId);
        $item   = MerchantGiftCatalog::where('is_active', true)
            ->findOrFail($request->catalog_item_id);

        try {
            $this->wallet->spendFishPoints(
                $request->user(),
                $item->fish_points_cost,
                'merchant_gift',
                'vendor',
                (int) $vendor->id,
                'Gift: ' . $item->name
            );
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $gift = MerchantGift::create([
            'sender_id'          => $request->user()->id,
            'vendor_id'          => $vendor->id,
            'catalog_item_id'    => $item->id,
            'fish_points_spent'  => $item->fish_points_cost,
            'message'            => $request->message,
        ]);

        return response()->json([
            'message'          => 'Gift sent to merchant!',
            'gift'             => $gift->load('catalogItem'),
            'your_fish_points' => $request->user()->fresh()->fish_points,
        ], 201);
    }
}
