<?php

namespace App\Models;

use App\Models\CartItem;
use App\Models\Notification;
use App\Models\Product;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\WalletService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class ProductOrder extends Model
{
    protected $fillable = [
        'user_id',
        'product_id',
        'vendor_id',
        'quantity',
        'unit_price',
        'product_amount',
        'delivery_fee',
        'amount',
        'currency',
        'status',
        'payment_method',
        'fulfillment',
        'shipping_status',
        'shipping_updated_at',
        'shipping_events',
        'delivery_zone',
        'ship_name',
        'ship_phone',
        'ship_address',
        'ship_city',
        'ship_province',
        'ship_island_group',
        'reference_number',
        'paymongo_checkout_id',
        'paymongo_payment_id',
        'paid_at',
        'fulfilled_at',
    ];

    protected $casts = [
        'unit_price'      => 'decimal:2',
        'product_amount'  => 'decimal:2',
        'delivery_fee'    => 'decimal:2',
        'amount'          => 'decimal:2',
        'paid_at'              => 'datetime',
        'fulfilled_at'         => 'datetime',
        'shipping_updated_at'  => 'datetime',
        'shipping_events'      => 'array',
    ];

    protected $appends = [
        'shipping_label',
        'shipping_timeline',
    ];

    public const DELIVERY_STEPS = [
        'processing' => 'Confirm',
        'packed' => 'Packed',
        'out_for_delivery' => 'Out for delivery',
        'delivered' => 'Delivered',
    ];

    public const PICKUP_STEPS = [
        'processing' => 'Confirm',
        'ready_for_pickup' => 'Ready for pickup',
        'picked_up' => 'Picked up',
    ];

    public const SHIPPING_MESSAGES = [
        'processing' => 'The store has confirmed your order.',
        'packed' => 'Your order has been packed and is ready to ship.',
        'out_for_delivery' => 'Your order is out for delivery.',
        'delivered' => 'Your order has been delivered.',
        'ready_for_pickup' => 'Your order is ready for pickup.',
        'picked_up' => 'Your order has been picked up.',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public static function newReference(): string
    {
        do {
            $ref = 'BW-'.strtoupper(Str::random(10));
        } while (static::where('reference_number', $ref)->exists());

        return $ref;
    }

    public function isPaid(): bool
    {
        return $this->status === 'paid' || $this->status === 'fulfilled';
    }

    public function isCod(): bool
    {
        return $this->payment_method === 'cod';
    }

    public function canFulfill(): bool
    {
        return $this->isPaid() || ($this->isCod() && $this->status === 'pending');
    }

    public function shippingSteps(): array
    {
        return $this->fulfillment === 'pickup' ? self::PICKUP_STEPS : self::DELIVERY_STEPS;
    }

    public function resolvedShippingStatus(): ?string
    {
        $stored = $this->attributes['shipping_status'] ?? null;
        if (is_string($stored) && $stored !== '') {
            return $stored;
        }

        $status = $this->attributes['status'] ?? null;
        if (in_array($status, ['cancelled', 'failed'], true)) {
            return null;
        }
        if ($status === 'fulfilled') {
            return $this->fulfillment === 'pickup' ? 'picked_up' : 'delivered';
        }
        if ($this->canFulfill()) {
            return 'processing';
        }

        return null;
    }

    public function getShippingStatusAttribute($value): ?string
    {
        if (is_string($value) && $value !== '') {
            return $value;
        }

        return $this->resolvedShippingStatus();
    }

    public function getShippingLabelAttribute(): ?string
    {
        $status = $this->resolvedShippingStatus();
        if (! $status) {
            return null;
        }

        return $this->shippingSteps()[$status] ?? $status;
    }

    public function getShippingTimelineAttribute(): array
    {
        $current = $this->resolvedShippingStatus();
        if (! $current) {
            return [];
        }

        $events = collect($this->shipping_events ?? [])->keyBy('status');
        $keys = array_keys($this->shippingSteps());
        $index = array_search($current, $keys, true);
        $index = $index === false ? -1 : $index;

        return collect($this->shippingSteps())
            ->map(function (string $label, string $key) use ($events, $keys, $index, $current) {
                $stepIndex = array_search($key, $keys, true);

                return [
                    'key' => $key,
                    'label' => $label,
                    'done' => $index >= 0 && $stepIndex !== false && $stepIndex <= $index,
                    'current' => $key === $current,
                    'at' => $events[$key]['at'] ?? null,
                ];
            })
            ->values()
            ->all();
    }

    public function canUpdateShipping(): bool
    {
        if (in_array($this->status, ['cancelled', 'failed'], true)) {
            return false;
        }

        $current = $this->resolvedShippingStatus();
        if (in_array($current, ['delivered', 'picked_up'], true)) {
            return false;
        }

        return $this->canFulfill() || $current !== null;
    }

    public function startShipping(): void
    {
        if (($this->attributes['shipping_status'] ?? null) || ! $this->canFulfill()) {
            return;
        }

        $this->forceFill([
            'shipping_status' => 'processing',
            'shipping_updated_at' => now(),
            'shipping_events' => [[
                'status' => 'processing',
                'at' => now()->toIso8601String(),
            ]],
        ])->save();
    }

    public function setShippingStatus(string $status, bool $notify = true): void
    {
        if (! in_array($status, array_keys($this->shippingSteps()), true)) {
            throw new RuntimeException('That status is not valid for this order.');
        }

        if (! $this->canUpdateShipping() && $this->resolvedShippingStatus() !== $status) {
            throw new RuntimeException('This order can no longer be updated.');
        }

        $events = collect($this->shipping_events ?? [])
            ->reject(fn ($event) => ($event['status'] ?? null) === $status)
            ->values()
            ->all();
        $events[] = [
            'status' => $status,
            'at' => now()->toIso8601String(),
        ];

        $payload = [
            'shipping_status' => $status,
            'shipping_updated_at' => now(),
            'shipping_events' => $events,
        ];

        if (in_array($status, ['delivered', 'picked_up'], true)) {
            $payload['status'] = 'fulfilled';
            $payload['paid_at'] = $this->paid_at ?? now();
            $payload['fulfilled_at'] = now();
        }

        $this->update($payload);
        $this->refresh();

        if (! $notify) {
            return;
        }

        $productName = $this->product?->name ?? 'your item';
        Notification::create([
            'user_id'        => $this->user_id,
            'type'           => 'order',
            'title'          => $this->shipping_label ?? 'Order update',
            'body'           => (self::SHIPPING_MESSAGES[$status] ?? 'Your order was updated.').' '.$productName.'.',
            'reference_id'   => $this->id,
            'reference_type' => 'product_order',
        ]);
    }

    public function markPlacedCod(): void
    {
        if ($this->isPaid() || $this->status === 'cancelled') {
            return;
        }

        DB::transaction(function () {
            $order = static::where('id', $this->id)->lockForUpdate()->first();
            if (! $order || $order->isPaid() || $order->status === 'cancelled') {
                return;
            }

            $product = Product::where('id', $order->product_id)->lockForUpdate()->first();
            $take = $product
                ? min((int) $product->stock, (int) $order->quantity)
                : 0;

            if ($product && $take > 0) {
                $product->decrement('stock', $take);
            }

            $order->update([
                'payment_method' => 'cod',
                'status' => 'pending',
            ]);

            CartItem::where('user_id', $order->user_id)
                ->where('product_id', $order->product_id)
                ->delete();
        });

        $this->refresh();
        $this->startShipping();
        $this->awardPurchaseFishPoints();

        $productName = $this->product?->name ?? 'your item';
        $when = $this->fulfillment === 'delivery'
            ? 'when it is delivered'
            : 'when you pick it up';

        Notification::create([
            'user_id'        => $this->user_id,
            'type'           => 'order',
            'title'          => 'COD order placed',
            'body'           => "Pay ₱{$this->amount} {$when} for {$productName}.",
            'reference_id'   => $this->id,
            'reference_type' => 'product_order',
        ]);
    }

    public function checkoutSiblings()
    {
        if ($this->paymongo_checkout_id) {
            return static::where('paymongo_checkout_id', $this->paymongo_checkout_id);
        }

        return static::where('reference_number', $this->reference_number);
    }

    public function markPaid(?string $paymentId = null): void
    {
        if ($this->isPaid()) {
            return;
        }

        DB::transaction(function () use ($paymentId) {
            $order = static::where('id', $this->id)->lockForUpdate()->first();
            if (! $order || $order->isPaid()) {
                return;
            }

            $product = Product::where('id', $order->product_id)->lockForUpdate()->first();
            $take = $product
                ? min((int) $product->stock, (int) $order->quantity)
                : 0;

            if ($product && $take > 0) {
                $product->decrement('stock', $take);
            }

            $order->update([
                'status'              => 'paid',
                'paid_at'             => now(),
                'paymongo_payment_id' => $paymentId ?: $order->paymongo_payment_id,
            ]);

            CartItem::where('user_id', $order->user_id)
                ->where('product_id', $order->product_id)
                ->delete();
        });

        $this->refresh();
        $this->startShipping();
        $this->awardPurchaseFishPoints();

        $productName = $this->product?->name ?? 'your item';
        Notification::create([
            'user_id'        => $this->user_id,
            'type'           => 'order',
            'title'          => 'Payment received',
            'body'           => "You paid ₱{$this->amount} for {$productName}. The store will process your order.",
            'reference_id'   => $this->id,
            'reference_type' => 'product_order',
        ]);
    }

    public function markCancelled(): void
    {
        if ($this->isPaid() || $this->status === 'cancelled') {
            return;
        }

        DB::transaction(function () {
            $order = static::where('id', $this->id)->lockForUpdate()->first();
            if (! $order || $order->isPaid() || $order->status === 'cancelled') {
                return;
            }

            if ($order->payment_method === 'cod') {
                $product = Product::where('id', $order->product_id)->lockForUpdate()->first();
                if ($product) {
                    $product->increment('stock', (int) $order->quantity);
                }
            }

            $order->update(['status' => 'cancelled']);
        });

        $this->refresh();
        $this->reversePurchaseFishPoints();
    }

    public function awardPurchaseFishPoints(): void
    {
        $wallet = app(WalletService::class);
        $perUnit = $wallet->setting('fish_points_marketplace_purchase', '10');
        $amount = $perUnit * max(1, (int) $this->quantity);
        if ($amount < 1) {
            return;
        }

        $already = WalletTransaction::where('user_id', $this->user_id)
            ->where('type', 'marketplace_purchase')
            ->where('reference_type', 'product_order')
            ->where('reference_id', $this->id)
            ->exists();
        if ($already) {
            return;
        }

        $user = $this->user ?? User::find($this->user_id);
        if (! $user) {
            return;
        }

        $productName = $this->product?->name ?? 'a marketplace item';
        $wallet->creditFishPoints(
            $user,
            $amount,
            'marketplace_purchase',
            'product_order',
            (int) $this->id,
            "Purchased {$productName}"
        );

        Notification::create([
            'user_id'        => $this->user_id,
            'type'           => 'wallet',
            'title'          => 'Fish Points earned',
            'body'           => "You earned {$amount} Fish Points for buying {$productName}.",
            'reference_id'   => $this->id,
            'reference_type' => 'product_order',
        ]);
    }

    public function reversePurchaseFishPoints(): void
    {
        $credit = WalletTransaction::where('user_id', $this->user_id)
            ->where('type', 'marketplace_purchase')
            ->where('reference_type', 'product_order')
            ->where('reference_id', $this->id)
            ->first();
        if (! $credit || (int) $credit->fish_points_delta < 1) {
            return;
        }

        $already = WalletTransaction::where('user_id', $this->user_id)
            ->where('type', 'marketplace_purchase_reversal')
            ->where('reference_type', 'product_order')
            ->where('reference_id', $this->id)
            ->exists();
        if ($already) {
            return;
        }

        $user = User::where('id', $this->user_id)->first();
        if (! $user) {
            return;
        }

        $take = min((int) $credit->fish_points_delta, (int) $user->fish_points);
        if ($take < 1) {
            return;
        }

        $user->decrement('fish_points', $take);
        WalletTransaction::create([
            'user_id'           => $user->id,
            'type'              => 'marketplace_purchase_reversal',
            'fish_points_delta' => -$take,
            'stars_delta'       => 0,
            'reference_type'    => 'product_order',
            'reference_id'      => $this->id,
            'note'              => 'Cancelled marketplace order',
        ]);
    }
}
