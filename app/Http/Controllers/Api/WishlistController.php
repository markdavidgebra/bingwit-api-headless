<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Wishlist;
use App\Models\Product;
use Illuminate\Http\Request;

class WishlistController extends Controller
{
    // GET MY WISHLIST
    public function index(Request $request)
    {
        $wishlist = Wishlist::where('user_id', $request->user()->id)
                            ->with(['product' => function ($q) {
                                $q->with(['primaryImage', 'category', 'brand']);
                            }])
                            ->latest()
                            ->get()
                            ->map(function ($item) {
                                $product = $item->product;
                                return [
                                    'wishlist_id'       => $item->id,
                                    'product_id'        => $product->id,
                                    'name'              => $product->name,
                                    'price'             => $product->price,
                                    'original_price'    => $product->original_price,
                                    'rating'            => $product->rating,
                                    'primary_image_url' => $product->primary_image_url,
                                    'category'          => $product->category?->name,
                                    'brand'             => $product->brand?->name,
                                    'is_on_sale'        => $product->is_on_sale,
                                    'discount_percent'  => $product->discount_percentage,
                                ];
                            });

        return response()->json($wishlist);
    }

    // ADD TO WISHLIST
    public function store(Request $request, $productId)
    {
        $already = Wishlist::where('user_id',   $request->user()->id)
                           ->where('product_id', $productId)
                           ->exists();

        if ($already) {
            return response()->json([
                'message'     => 'Already in wishlist.',
                'wishlisted'  => true,
            ]);
        }

        Wishlist::create([
            'user_id'    => $request->user()->id,
            'product_id' => $productId,
        ]);

        return response()->json([
            'message'    => 'Added to wishlist!',
            'wishlisted' => true,
        ]);
    }

    // REMOVE FROM WISHLIST
    public function destroy(Request $request, $productId)
    {
        Wishlist::where('user_id',   $request->user()->id)
                ->where('product_id', $productId)
                ->delete();

        return response()->json([
            'message'    => 'Removed from wishlist.',
            'wishlisted' => false,
        ]);
    }

    // TOGGLE WISHLIST
    public function toggle(Request $request, $productId)
    {
        $existing = Wishlist::where('user_id',   $request->user()->id)
                            ->where('product_id', $productId)
                            ->first();

        if ($existing) {
            $existing->delete();
            return response()->json([
                'message'    => 'Removed from wishlist.',
                'wishlisted' => false,
            ]);
        }

        Wishlist::create([
            'user_id'    => $request->user()->id,
            'product_id' => $productId,
        ]);

        return response()->json([
            'message'    => 'Added to wishlist!',
            'wishlisted' => true,
        ]);
    }
}