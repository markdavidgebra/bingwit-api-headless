<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EconomySetting;
use App\Models\Notification;
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

        $claimedAfilink = WalletTransaction::where('user_id', $user->id)
            ->where('type', 'company_afilink')
            ->exists();

        return response()->json([
            'fish_points'          => $user->fish_points,
            'stars'                => $user->stars,
            'fish_points_per_star' => $this->wallet->setting('fish_points_per_star', '10'),
            'stars_afilink_bonus'  => $this->wallet->setting('stars_afilink_bonus', '25'),
            'stars_boat_booking'   => $this->wallet->setting('stars_boat_booking', '5'),
            'afilink_claimed'      => $claimedAfilink,
            'transactions'         => $transactions,
        ]);
    }

    /**
     * Convert currency.
     * Primary (requirements): { stars } → Fish Points
     * Legacy: { fish_points } → Stars
     */
    public function convert(Request $request)
    {
        $request->validate([
            'stars'       => 'nullable|integer|min:1',
            'fish_points' => 'nullable|integer|min:1',
        ]);

        $stars = (int) ($request->stars ?? 0);
        $fishPoints = (int) ($request->fish_points ?? 0);

        if ($stars < 1 && $fishPoints < 1) {
            return response()->json([
                'message' => 'Provide stars (Stars → Fish Points) or fish_points (legacy FP → Stars).',
            ], 422);
        }

        try {
            if ($stars >= 1) {
                $fpGained = $this->wallet->convertStarsToFishPoints($request->user(), $stars);
                $user = $request->user()->fresh();

                return response()->json([
                    'message'             => "Converted {$stars} Star(s) to {$fpGained} Fish Points!",
                    'fish_points_gained'  => $fpGained,
                    'stars_spent'         => $stars,
                    'fish_points'         => $user->fish_points,
                    'stars'               => $user->stars,
                ]);
            }

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

    /** One-time Stars bonus from Bingwit Afilink (company). */
    public function claimAfilink(Request $request)
    {
        $user = $request->user();
        $amount = $this->wallet->setting('stars_afilink_bonus', '25');

        if ($amount < 1) {
            return response()->json(['message' => 'Afilink bonus is not available right now.'], 422);
        }

        $already = WalletTransaction::where('user_id', $user->id)
            ->where('type', 'company_afilink')
            ->exists();

        if ($already) {
            return response()->json([
                'message' => 'You already claimed your Bingwit Afilink Stars bonus.',
            ], 422);
        }

        $this->wallet->creditStars(
            $user,
            $amount,
            'company_afilink',
            'company',
            null,
            'Stars from Bingwit Afilink'
        );

        Notification::create([
            'user_id'        => $user->id,
            'type'           => 'star_gift',
            'title'          => 'Bingwit Afilink gifted you Stars!',
            'body'           => "You received {$amount} Stars from Bingwit Afilink. Exchange them for Fish Points in your wallet.",
            'reference_type' => 'company',
        ]);

        $fresh = $user->fresh();

        return response()->json([
            'message' => "Bingwit Afilink gifted you {$amount} Stars!",
            'stars_gained' => $amount,
            'stars' => $fresh->stars,
            'fish_points' => $fresh->fish_points,
        ], 201);
    }

    public function settings()
    {
        $keys = [
            'fish_points_per_star',
            'fish_points_post_catch',
            'fish_points_post_lesson',
            'fish_points_lesson_confirmed',
            'stars_boat_booking',
            'stars_afilink_bonus',
        ];

        $settings = EconomySetting::whereIn('key', $keys)
            ->pluck('value', 'key');

        return response()->json(['settings' => $settings]);
    }
}
