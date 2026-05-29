<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Review;
use App\Models\Product;
use Illuminate\Http\Request;

class ReviewController extends Controller
{
    // GET REVIEWS FOR A PRODUCT
    public function index($productId)
    {
        $reviews = Review::where('product_id', $productId)
                         ->with(['user' => function ($q) {
                             $q->select('id', 'name', 'profile_picture');
                         }])
                         ->latest()
                         ->paginate(10);

        return response()->json($reviews);
    }

    // ADD A REVIEW
    public function store(Request $request, $productId)
    {
        $request->validate([
            'rating' => 'required|integer|min:1|max:5',
            'body'   => 'nullable|string|max:1000',
        ]);

        $existing = Review::where('user_id',   $request->user()->id)
                          ->where('product_id', $productId)
                          ->first();

        if ($existing) {
            return response()->json([
                'message' => 'You have already reviewed this product.',
            ], 400);
        }

        $review = Review::create([
            'user_id'    => $request->user()->id,
            'product_id' => $productId,
            'rating'     => $request->rating,
            'body'       => $request->body,
        ]);

        // Update product rating
        $product         = Product::findOrFail($productId);
        $avgRating       = Review::where('product_id', $productId)->avg('rating');
        $reviewsCount    = Review::where('product_id', $productId)->count();
        $product->update([
            'rating'        => round($avgRating, 2),
            'reviews_count' => $reviewsCount,
        ]);

        return response()->json([
            'message' => 'Review submitted!',
            'review'  => $review->load('user'),
        ], 201);
    }

    // DELETE A REVIEW
    public function destroy(Request $request, $reviewId)
    {
        $review = Review::findOrFail($reviewId);

        if ($review->user_id !== $request->user()->id) {
            return response()->json([
                'message' => 'You can only delete your own reviews.',
            ], 403);
        }

        $productId = $review->product_id;
        $review->delete();

        // Update product rating
        $product      = Product::findOrFail($productId);
        $avgRating    = Review::where('product_id', $productId)->avg('rating') ?? 0;
        $reviewsCount = Review::where('product_id', $productId)->count();
        $product->update([
            'rating'        => round($avgRating, 2),
            'reviews_count' => $reviewsCount,
        ]);

        return response()->json(['message' => 'Review deleted.']);
    }
}