<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\CatchStarGift;
use App\Models\EconomySetting;
use App\Models\GearDonation;
use App\Models\MerchantGift;
use App\Models\MerchantGiftCatalog;
use App\Models\Redemption;
use App\Models\RewardItem;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\WalletService;
use Illuminate\Http\Request;

class EconomyAdminController extends Controller
{
    public function overview()
    {
        return response()->json([
            'totals' => [
                'fish_points_in_wallets' => (int) User::sum('fish_points'),
                'stars_in_wallets'       => (int) User::sum('stars'),
                'users_with_fp'          => User::where('fish_points', '>', 0)->count(),
                'users_with_stars'       => User::where('stars', '>', 0)->count(),
            ],
            'counts' => [
                'wallet_transactions'  => WalletTransaction::count(),
                'catch_gifts'          => CatchStarGift::count(),
                'catch_gifts_fp_sum'   => (int) CatchStarGift::sum('fish_points'),
                'gear_donations'       => GearDonation::count(),
                'merchant_gifts'       => MerchantGift::count(),
                'merchant_gifts_fp'    => (int) MerchantGift::sum('fish_points_spent'),
                'pending_redemptions'  => Redemption::where('status', 'pending')->count(),
            ],
            'recent_transactions' => WalletTransaction::with('user:id,name,email')
                ->latest()
                ->limit(12)
                ->get(),
        ]);
    }

    public function wallets(Request $request)
    {
        $query = User::query()
            ->select('id', 'name', 'email', 'fish_points', 'stars', 'created_at')
            ->orderByDesc('fish_points')
            ->orderByDesc('stars');

        if ($request->filled('search')) {
            $term = '%' . $request->search . '%';
            $query->where(function ($q) use ($term) {
                $q->where('name', 'like', $term)
                  ->orWhere('email', 'like', $term);
            });
        }

        return response()->json($query->paginate(25));
    }

    public function adjustWallet(Request $request, $userId, WalletService $wallet)
    {
        $data = $request->validate([
            'fish_points_delta' => 'nullable|integer|between:-1000000,1000000',
            'stars_delta'       => 'nullable|integer|between:-1000000,1000000',
            'note'              => 'nullable|string|max:500',
        ]);

        $fpDelta = (int) ($data['fish_points_delta'] ?? 0);
        $starsDelta = (int) ($data['stars_delta'] ?? 0);

        if ($fpDelta === 0 && $starsDelta === 0) {
            return response()->json([
                'message' => 'Provide fish_points_delta and/or stars_delta (non-zero).',
            ], 422);
        }

        $user  = User::findOrFail($userId);
        $admin = $request->user();

        $note = trim($data['note'] ?? '');
        if ($note === '') {
            $note = 'Manual wallet adjust by admin';
        }
        if ($admin) {
            $note .= ' (by ' . ($admin->name ?? 'admin') . ')';
        }

        try {
            if ($fpDelta > 0) {
                $wallet->creditFishPoints($user, $fpDelta, 'admin_grant', 'admin', $admin?->id, $note);
            } elseif ($fpDelta < 0) {
                $wallet->spendFishPoints($user, abs($fpDelta), 'admin_deduct', 'admin', $admin?->id, $note);
            }

            if ($starsDelta > 0) {
                $wallet->creditStars($user, $starsDelta, 'admin_star_grant', 'admin', $admin?->id, $note);
            } elseif ($starsDelta < 0) {
                $wallet->spendStars($user, abs($starsDelta), 'admin_star_deduct', 'admin', $admin?->id, $note);
            }
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $fresh = $user->fresh();
        $parts = [];
        if ($fpDelta !== 0) {
            $parts[] = ($fpDelta > 0 ? '+' : '') . $fpDelta . ' FP';
        }
        if ($starsDelta !== 0) {
            $parts[] = ($starsDelta > 0 ? '+' : '') . $starsDelta . ' Stars';
        }

        return response()->json([
            'message' => 'Updated ' . $fresh->name . ': ' . implode(', ', $parts) . '.',
            'user' => [
                'id'           => $fresh->id,
                'name'         => $fresh->name,
                'email'        => $fresh->email,
                'fish_points'  => $fresh->fish_points,
                'stars'        => $fresh->stars,
            ],
        ]);
    }

    public function transactions(Request $request)
    {
        $query = WalletTransaction::with('user:id,name,email')->latest();

        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }

        return response()->json($query->paginate(30));
    }

    public function catchGifts()
    {
        $rows = CatchStarGift::with([
            'giver:id,name,email',
            'catch:id,fish_species,user_id',
            'catch.user:id,name',
        ])
            ->latest()
            ->paginate(25);

        return response()->json($rows);
    }

    public function gearDonations()
    {
        $rows = GearDonation::with([
            'donor:id,name,email',
            'recipient:id,name,email',
            'catch:id,fish_species',
        ])
            ->latest()
            ->paginate(25);

        return response()->json($rows);
    }

    public function merchantGifts()
    {
        $rows = MerchantGift::with([
            'sender:id,name,email',
            'vendor:id,store_name,name',
            'catalogItem:id,name,emoji,fish_points_cost',
        ])
            ->latest()
            ->paginate(25);

        return response()->json($rows);
    }

    public function settings()
    {
        return response()->json([
            'settings' => EconomySetting::all(),
        ]);
    }

    public function updateSettings(Request $request)
    {
        $request->validate([
            'settings'   => 'required|array',
            'settings.*' => 'string|max:20',
        ]);

        foreach ($request->settings as $key => $value) {
            EconomySetting::updateOrCreate(
                ['key' => $key],
                ['value' => (string) $value]
            );
        }

        return response()->json([
            'message'  => 'Economy settings updated.',
            'settings' => EconomySetting::all(),
        ]);
    }

    public function rewards()
    {
        return response()->json([
            'rewards' => RewardItem::orderBy('name')->get(),
        ]);
    }

    public function storeReward(Request $request)
    {
        $data = $request->validate([
            'name'             => 'required|string|max:255',
            'description'      => 'nullable|string',
            'image_url'        => 'nullable|url|max:500',
            'star_cost'        => 'nullable|integer|min:1',
            'fish_points_cost' => 'nullable|integer|min:1',
            'stock'            => 'required|integer|min:0',
            'is_active'        => 'boolean',
        ]);

        if (empty($data['fish_points_cost']) && empty($data['star_cost'])) {
            return response()->json([
                'message' => 'Provide fish_points_cost (preferred) or star_cost.',
            ], 422);
        }

        if (empty($data['fish_points_cost']) && ! empty($data['star_cost'])) {
            $rate = (int) (EconomySetting::where('key', 'fish_points_per_star')->value('value') ?? 10);
            $data['fish_points_cost'] = max(1, ((int) $data['star_cost']) * max(1, $rate));
        }

        if (empty($data['star_cost'])) {
            $data['star_cost'] = 1;
        }

        $item = RewardItem::create($data);

        return response()->json(['message' => 'Reward created.', 'reward' => $item], 201);
    }

    public function updateReward(Request $request, $id)
    {
        $item = RewardItem::findOrFail($id);

        $data = $request->validate([
            'name'             => 'sometimes|string|max:255',
            'description'      => 'nullable|string',
            'image_url'        => 'nullable|url|max:500',
            'star_cost'        => 'sometimes|integer|min:1',
            'fish_points_cost' => 'sometimes|integer|min:1',
            'stock'            => 'sometimes|integer|min:0',
            'is_active'        => 'boolean',
        ]);

        $item->update($data);

        return response()->json(['message' => 'Reward updated.', 'reward' => $item]);
    }

    public function deleteReward($id)
    {
        RewardItem::findOrFail($id)->delete();

        return response()->json(['message' => 'Reward deleted.']);
    }

    public function giftCatalog()
    {
        return response()->json([
            'catalog' => MerchantGiftCatalog::orderBy('name')->get(),
        ]);
    }

    public function storeGiftCatalog(Request $request)
    {
        $data = $request->validate([
            'name'        => 'required|string|max:255',
            'description' => 'nullable|string',
            'emoji'       => 'nullable|string|max:10',
            'fish_points_cost' => 'required|integer|min:1',
            'star_cost'        => 'sometimes|integer|min:1',
            'is_active'        => 'boolean',
        ]);

        if (! isset($data['fish_points_cost']) && isset($data['star_cost'])) {
            $data['fish_points_cost'] = $data['star_cost'];
        }
        unset($data['star_cost']);

        $item = MerchantGiftCatalog::create($data);

        return response()->json(['message' => 'Gift item created.', 'item' => $item], 201);
    }

    public function updateGiftCatalog(Request $request, $id)
    {
        $item = MerchantGiftCatalog::findOrFail($id);

        $data = $request->validate([
            'name'        => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'emoji'       => 'nullable|string|max:10',
            'fish_points_cost' => 'sometimes|integer|min:1',
            'star_cost'        => 'sometimes|integer|min:1',
            'is_active'        => 'boolean',
        ]);

        if (! isset($data['fish_points_cost']) && isset($data['star_cost'])) {
            $data['fish_points_cost'] = $data['star_cost'];
        }
        unset($data['star_cost']);

        $item->update($data);

        return response()->json(['message' => 'Gift item updated.', 'item' => $item]);
    }

    public function deleteGiftCatalog($id)
    {
        MerchantGiftCatalog::findOrFail($id)->delete();

        return response()->json(['message' => 'Gift item deleted.']);
    }

    public function redemptions()
    {
        $rows = Redemption::with(['user:id,name,email', 'rewardItem'])
            ->latest()
            ->paginate(20);

        return response()->json($rows);
    }

    public function fulfillRedemption($id)
    {
        $row = Redemption::findOrFail($id);
        $row->update([
            'status'       => 'fulfilled',
            'fulfilled_at' => now(),
        ]);

        return response()->json(['message' => 'Redemption marked fulfilled.', 'redemption' => $row]);
    }
}
