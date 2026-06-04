<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EconomySetting;
use App\Models\WalletTransaction;
use App\Services\WalletService;
use Illuminate\Http\Request;

class WalletController extends Controller
{
    public function __construct(private WalletService $wallet)
    {
    }

    public function show(Request $request)
    {
        $user = $request->user();

        $transactions = WalletTransaction::where('user_id', $user->id)
            ->latest()
            ->limit(30)
            ->get();

        return response()->json([
            'fish_points'           => $user->fish_points,
            'stars'                 => $user->stars,
            'fish_points_per_star'  => $this->wallet->setting('fish_points_per_star', '10'),
            'transactions'          => $transactions,
        ]);
    }

    public function convert(Request $request)
    {
        $request->validate([
            'fish_points' => 'required|integer|min:1',
        ]);

        try {
            $starsGained = $this->wallet->convertFishPointsToStars(
                $request->user(),
                (int) $request->fish_points
            );
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $user = $request->user()->fresh();

        return response()->json([
            'message'      => "Converted to {$starsGained} Star(s)!",
            'stars_gained' => $starsGained,
            'fish_points'  => $user->fish_points,
            'stars'        => $user->stars,
        ]);
    }

    public function settings()
    {
        $keys = [
            'fish_points_per_star',
            'fish_points_post_catch',
            'fish_points_post_lesson',
            'fish_points_lesson_confirmed',
        ];

        $settings = EconomySetting::whereIn('key', $keys)
            ->pluck('value', 'key');

        return response()->json(['settings' => $settings]);
    }
}
