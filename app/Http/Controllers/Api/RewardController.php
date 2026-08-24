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
        $items = RewardItem::where('is_active', true)
            ->where('stock', '>', 0)
            ->orderByRaw('COALESCE(star_cost, fish_points_cost) asc')
            ->get()
            ->map(function (RewardItem $item) {
                $starCost = $this->wallet->starCostForReward($item);
                $data = $item->toArray();
                $data['star_cost'] = $starCost;
                $data['fish_points_cost'] = $item->fish_points_cost;

                return $data;
            });

        return response()->json(['rewards' => $items]);
    }

    /** Redeem tackle-shop reward items with Stars. */
    public function redeem(Request $request, $id)
    {
        $item = RewardItem::where('is_active', true)->findOrFail($id);
        $starCost = $this->wallet->starCostForReward($item);

        if ($item->stock < 1) {
            return response()->json(['message' => 'This reward is out of stock.'], 422);
        }

        try {
            DB::transaction(function () use ($request, $item, $starCost) {
                $locked = RewardItem::where('id', $item->id)->lockForUpdate()->first();

                if ($locked->stock < 1) {
                    throw new \RuntimeException('This reward is out of stock.');
                }

                $this->wallet->spendStars(
                    $request->user(),
                    $starCost,
                    'redemption',
                    'reward_item',
                    (int) $locked->id,
                    'Redeemed tackle reward: ' . $locked->name
                );

                $locked->decrement('stock');

                Redemption::create([
                    'user_id'           => $request->user()->id,
                    'reward_item_id'    => $locked->id,
                    'stars_spent'       => $starCost,
                    'fish_points_spent' => 0,
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
            'body'           => 'You redeemed: ' . $item->name . " for {$starCost} Stars.",
            'reference_id'   => $item->id,
            'reference_type' => 'reward_item',
        ]);

        return response()->json([
            'message'     => 'Reward claimed with Stars! We will process your tackle item soon.',
            'your_stars'  => $request->user()->fresh()->stars,
            'stars_spent' => $starCost,
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
