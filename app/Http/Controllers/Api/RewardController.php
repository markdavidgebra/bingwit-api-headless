<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use App\Models\Redemption;
use App\Models\RewardItem;
use App\Services\WalletService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RewardController extends Controller
{
    public function __construct(private WalletService $wallet)
    {
    }

    public function index()
    {
        $rate = $this->wallet->setting('fish_points_per_star', '10');

        $items = RewardItem::where('is_active', true)
            ->where('stock', '>', 0)
            ->orderByRaw('COALESCE(fish_points_cost, star_cost) asc')
            ->get()
            ->map(function (RewardItem $item) use ($rate) {
                $fpCost = (int) ($item->fish_points_cost ?: max(1, ((int) $item->star_cost) * max(1, $rate)));
                $data = $item->toArray();
                $data['fish_points_cost'] = $fpCost;
                $data['star_cost'] = (int) $item->star_cost;

                return $data;
            });

        return response()->json(['rewards' => $items]);
    }

    /** Redeem tackle-shop reward items with Fish Points. */
    public function redeem(Request $request, $id)
    {
        $item = RewardItem::where('is_active', true)->findOrFail($id);
        $rate = $this->wallet->setting('fish_points_per_star', '10');
        $fpCost = (int) ($item->fish_points_cost ?: max(1, ((int) $item->star_cost) * max(1, $rate)));

        if ($item->stock < 1) {
            return response()->json(['message' => 'This reward is out of stock.'], 422);
        }

        try {
            DB::transaction(function () use ($request, $item, $fpCost) {
                $locked = RewardItem::where('id', $item->id)->lockForUpdate()->first();

                if ($locked->stock < 1) {
                    throw new \RuntimeException('This reward is out of stock.');
                }

                $this->wallet->spendFishPoints(
                    $request->user(),
                    $fpCost,
                    'redemption',
                    'reward_item',
                    (int) $locked->id,
                    'Redeemed tackle reward: ' . $locked->name
                );

                $locked->decrement('stock');

                Redemption::create([
                    'user_id'           => $request->user()->id,
                    'reward_item_id'    => $locked->id,
                    'stars_spent'       => 0,
                    'fish_points_spent' => $fpCost,
                    'status'            => 'pending',
                ]);
            });
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        Notification::create([
            'user_id'        => $request->user()->id,
            'type'           => 'redemption',
            'title'          => 'Redemption submitted!',
            'body'           => 'You redeemed: ' . $item->name . " for {$fpCost} Fish Points.",
            'reference_id'   => $item->id,
            'reference_type' => 'reward_item',
        ]);

        return response()->json([
            'message'          => 'Reward redeemed! We will process your tackle item soon.',
            'your_fish_points' => $request->user()->fresh()->fish_points,
            'fish_points_spent'=> $fpCost,
        ], 201);
    }

    public function myRedemptions(Request $request)
    {
        $rows = Redemption::with('rewardItem')
            ->where('user_id', $request->user()->id)
            ->latest()
            ->get();

        return response()->json(['redemptions' => $rows]);
    }
}
