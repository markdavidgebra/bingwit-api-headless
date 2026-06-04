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
            ->orderBy('star_cost')
            ->get();

        return response()->json(['rewards' => $items]);
    }

    public function redeem(Request $request, $id)
    {
        $item = RewardItem::where('is_active', true)->findOrFail($id);

        if ($item->stock < 1) {
            return response()->json(['message' => 'This reward is out of stock.'], 422);
        }

        try {
            DB::transaction(function () use ($request, $item) {
                $locked = RewardItem::where('id', $item->id)->lockForUpdate()->first();

                if ($locked->stock < 1) {
                    throw new \RuntimeException('This reward is out of stock.');
                }

                $this->wallet->spendStars(
                    $request->user(),
                    $locked->star_cost,
                    'redemption',
                    'reward_item',
                    (int) $locked->id,
                    'Redeemed: ' . $locked->name
                );

                $locked->decrement('stock');

                Redemption::create([
                    'user_id'        => $request->user()->id,
                    'reward_item_id' => $locked->id,
                    'stars_spent'    => $locked->star_cost,
                    'status'         => 'pending',
                ]);
            });
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        Notification::create([
            'user_id'        => $request->user()->id,
            'type'           => 'redemption',
            'title'          => 'Redemption submitted!',
            'body'           => 'You redeemed: ' . $item->name,
            'reference_id'   => $item->id,
            'reference_type' => 'reward_item',
        ]);

        return response()->json([
            'message'    => 'Reward redeemed! We will process your item soon.',
            'your_stars' => $request->user()->fresh()->stars,
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
