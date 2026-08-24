<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\CatchStarGift;
use App\Models\EconomySetting;
use App\Models\GearDonation;
use App\Models\MerchantGift;
use App\Models\MerchantGiftCatalog;
use App\Models\ProductClaim;
use App\Models\ProductOrder;
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
                'referred_signups'     => User::whereNotNull('referred_by_user_id')->count(),
                'users_who_referred'   => User::has('referrals')->count(),
                'referral_fp_awarded'  => (int) WalletTransaction::where('type', 'referral_signup')->sum('fish_points_delta'),
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

    public function referrals(Request $request)
    {
        $signups = User::query()
            ->whereNotNull('referred_by_user_id')
            ->with(['referrer:id,name,email,referral_code'])
            ->latest();

        if ($request->filled('referrer_id')) {
            $signups->where('referred_by_user_id', (int) $request->referrer_id);
        }

        if ($request->filled('search')) {
            $term = '%' . $request->search . '%';
            $signups->where(function ($q) use ($term) {
                $q->where('name', 'like', $term)
                    ->orWhere('email', 'like', $term)
                    ->orWhereHas('referrer', function ($r) use ($term) {
                        $r->where('name', 'like', $term)
                            ->orWhere('email', 'like', $term)
                            ->orWhere('referral_code', 'like', $term);
                    });
            });
        }

        $page = $signups->paginate(25);
        $ids = collect($page->items())->pluck('id');
        $awards = WalletTransaction::where('type', 'referral_signup')
            ->where('reference_type', 'user')
            ->whereIn('reference_id', $ids)
            ->get()
            ->keyBy('reference_id');

        $page->getCollection()->transform(function (User $user) use ($awards) {
            $tx = $awards->get($user->id);
            $owner = $user->referrer;
            $code = $owner?->referral_code;
            $user->setAttribute('fp_awarded', (int) ($tx->fish_points_delta ?? 0));
            $user->setAttribute('link_owner', $owner ? [
                'id'             => $owner->id,
                'name'           => $owner->name,
                'email'          => $owner->email,
                'referral_code'  => $code,
                'referral_link'  => $code
                    ? rtrim((string) config('app.web_url', 'https://app.bingwit.com'), '/') . '/join?ref=' . $code
                    : null,
            ] : null);
            $user->makeVisible(['referred_by_user_id']);

            return $user;
        });

        $topReferrers = User::query()
            ->withCount('referrals')
            ->whereHas('referrals')
            ->orderByDesc('referrals_count')
            ->limit(20)
            ->get(['id', 'name', 'email', 'referral_code', 'fish_points']);

        $referrerIds = $topReferrers->pluck('id');
        $fpByUser = WalletTransaction::query()
            ->selectRaw('user_id, SUM(fish_points_delta) as fp')
            ->where('type', 'referral_signup')
            ->whereIn('user_id', $referrerIds)
            ->groupBy('user_id')
            ->pluck('fp', 'user_id');

        $topReferrers->transform(function (User $user) use ($fpByUser) {
            $user->setAttribute('referral_fp_earned', (int) ($fpByUser[$user->id] ?? 0));

            return $user;
        });

        return response()->json([
            'signups' => $page,
            'top_referrers' => $topReferrers,
            'stats' => [
                'referred_signups'    => User::whereNotNull('referred_by_user_id')->count(),
                'users_who_referred'  => User::has('referrals')->count(),
                'referral_fp_awarded' => (int) WalletTransaction::where('type', 'referral_signup')->sum('fish_points_delta'),
            ],
        ]);
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
                'message' => 'Provide star_cost so anglers can claim this item with Stars.',
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

    public function productClaims()
    {
        $rows = ProductClaim::with(['user:id,name,email', 'product:id,name'])
            ->latest()
            ->paginate(20);

        return response()->json($rows);
    }

    public function fulfillProductClaim($id)
    {
        $row = ProductClaim::findOrFail($id);
        $row->update([
            'status'       => 'fulfilled',
            'fulfilled_at' => now(),
        ]);

        return response()->json(['message' => 'Claim marked fulfilled.', 'claim' => $row]);
    }

    public function productOrders()
    {
        $rows = ProductOrder::with(['user:id,name,email', 'product:id,name'])
            ->latest()
            ->paginate(20);

        return response()->json($rows);
    }

    public function fulfillProductOrder($id)
    {
        $row = ProductOrder::with('product')->findOrFail($id);
        if (! $row->canUpdateShipping() && ! in_array($row->resolvedShippingStatus(), ['delivered', 'picked_up'], true)) {
            return response()->json(['message' => 'Only paid or COD orders can be fulfilled.'], 422);
        }

        $target = $row->fulfillment === 'pickup' ? 'picked_up' : 'delivered';
        $row->setShippingStatus($target);

        return response()->json(['message' => 'Order marked fulfilled.', 'order' => $row->fresh()]);
    }

    public function updateProductOrderShipping(Request $request, $id)
    {
        $data = $request->validate([
            'shipping_status' => 'required|string|in:processing,packed,out_for_delivery,delivered,ready_for_pickup,picked_up',
        ]);

        $row = ProductOrder::with('product')->findOrFail($id);

        try {
            $row->setShippingStatus($data['shipping_status']);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Order status updated.',
            'order' => $row->fresh(),
        ]);
    }
}
