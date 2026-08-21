<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Resort;
use App\Models\ResortReview;
use Illuminate\Http\Request;

class ResortController extends Controller
{
    public function index(Request $request)
    {
        $request->validate([
            'q' => 'nullable|string|max:100',
            'location' => 'nullable|string|max:100',
            'municipality_id' => 'nullable|integer|exists:municipalities,id',
        ]);

        $query = Resort::with('municipality')
            ->where('is_active', true);

        if ($request->filled('q')) {
            $term = '%' . $request->q . '%';
            $query->where(function ($q) use ($term) {
                $q->where('name', 'like', $term)
                    ->orWhere('description', 'like', $term)
                    ->orWhere('location', 'like', $term);
            });
        }

        if ($request->filled('location')) {
            $query->where('location', 'like', '%' . $request->location . '%');
        }

        if ($request->filled('municipality_id')) {
            $query->where('municipality_id', $request->municipality_id);
        }

        $resorts = $query->orderByDesc('is_verified')
            ->orderByDesc('rating')
            ->paginate(20);

        return response()->json($resorts);
    }

    public function show($id)
    {
        $resort = Resort::with([
            'municipality',
            'reviews.user:id,name,profile_picture',
        ])->findOrFail($id);

        return response()->json(['resort' => $resort]);
    }

    public function review(Request $request, $id)
    {
        $request->validate([
            'rating' => 'required|integer|min:1|max:5',
            'body' => 'nullable|string|max:1000',
        ]);

        $resort = Resort::where('is_active', true)->findOrFail($id);

        $review = ResortReview::updateOrCreate(
            [
                'resort_id' => $resort->id,
                'user_id' => $request->user()->id,
            ],
            [
                'rating' => (int) $request->rating,
                'body' => $request->body,
            ]
        );

        $avg = ResortReview::where('resort_id', $resort->id)->avg('rating');
        $count = ResortReview::where('resort_id', $resort->id)->count();
        $resort->update([
            'rating' => round((float) $avg, 2),
            'reviews_count' => $count,
        ]);

        return response()->json([
            'message' => 'Review saved.',
            'review' => $review->load('user:id,name,profile_picture'),
            'resort' => $resort->fresh('municipality'),
        ]);
    }
}
