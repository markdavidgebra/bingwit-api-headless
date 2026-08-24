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
            'fish_points'          => $user->fish_points,
            'stars'                => $user->stars,
            'fish_points_per_star' => $this->wallet->setting('fish_points_per_star', '10'),
            'stars_boat_booking'   => $this->wallet->setting('stars_boat_booking', '5'),
            'fish_points_marketplace_purchase' => $this->wallet->setting('fish_points_marketplace_purchase', '10'),
            'fish_points_post_catch' => $this->wallet->setting('fish_points_post_catch', '10'),
            'fish_points_post_lesson' => $this->wallet->setting('fish_points_post_lesson', '15'),
            'fish_points_lesson_confirmed' => $this->wallet->setting('fish_points_lesson_confirmed', '5'),
            'fish_points_referral_bonus' => $this->wallet->setting('fish_points_referral_bonus', '25'),
            'transactions'         => $transactions,
        ]);
    }

    /**
     * Convert Fish Points → Stars. Stars cannot be converted back.
     */
    public function convert(Request $request)
    {
        $request->validate([
            'fish_points' => 'required|integer|min:1',
        ]);

        $fishPoints = (int) $request->fish_points;

        try {
            $starsGained = $this->wallet->convertFishPointsToStars($request->user(), $fishPoints);
            $user = $request->user()->fresh();

            return response()->json([
                'message'      => "Converted to {$starsGained} Star(s)!",
                'stars_gained' => $starsGained,
                'fish_points'  => $user->fish_points,
                'stars'        => $user->stars,
            ]);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function settings()
    {
        $keys = [
            'fish_points_per_star',
            'fish_points_post_catch',
            'fish_points_post_lesson',
            'fish_points_lesson_confirmed',
            'fish_points_marketplace_purchase',
            'stars_boat_booking',
            'fish_points_referral_bonus',
        ];

        $settings = EconomySetting::whereIn('key', $keys)
            ->pluck('value', 'key');

        return response()->json(['settings' => $settings]);
    }
}
