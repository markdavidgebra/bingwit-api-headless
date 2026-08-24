<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CartItem;
use App\Models\Product;
use App\Models\ProductOrder;
use App\Services\DeliveryFeeService;
use App\Services\PayMongoService;
use App\Support\PhilippineRegions;
use Illuminate\Http\Request;
use RuntimeException;

class CartController extends Controller
{
    public function __construct(
        private PayMongoService $paymongo,
        private DeliveryFeeService $delivery,
    ) {
    }

    public function index(Request $request)
    {
        return response()->json($this->payload($request->user()->id));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'product_id' => 'required|integer|exists:products,id',
            'quantity' => 'nullable|integer|min:1|max:10',
        ]);

        $product = Product::where('is_active', true)->findOrFail($data['product_id']);
        try {
            $this->assertCashProduct($product);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        if ((int) $product->stock < 1) {
            return response()->json(['message' => 'This item is out of stock.'], 422);
        }

        $add = (int) ($data['quantity'] ?? 1);
        $item = CartItem::firstOrNew([
            'user_id' => $request->user()->id,
            'product_id' => $product->id,
        ]);
        $next = min(($item->exists ? (int) $item->quantity : 0) + $add, min(10, (int) $product->stock));
        $item->quantity = max(1, $next);
        $item->save();

        return response()->json($this->payload($request->user()->id), 201);
    }

    public function update(Request $request, $productId)
    {
        $data = $request->validate([
            'quantity' => 'required|integer|min:0|max:10',
        ]);

        $item = CartItem::where('user_id', $request->user()->id)
            ->where('product_id', $productId)
            ->firstOrFail();

        if ((int) $data['quantity'] < 1) {
            $item->delete();

            return response()->json($this->payload($request->user()->id));
        }

        $product = Product::find($item->product_id);
        $max = min(10, max(1, (int) ($product?->stock ?? 1)));
        $item->update(['quantity' => min((int) $data['quantity'], $max)]);

        return response()->json($this->payload($request->user()->id));
    }

    public function destroy(Request $request, $productId)
    {
        CartItem::where('user_id', $request->user()->id)
            ->where('product_id', $productId)
            ->delete();

        return response()->json($this->payload($request->user()->id));
    }

    public function options(Request $request)
    {
        $items = $this->activeItems($request->user()->id);
        if ($items->isEmpty()) {
            return response()->json(['message' => 'Your cart is empty.'], 422);
        }

        $vendors = $items->map(fn (CartItem $item) => $item->product?->vendor)->filter()->unique('id')->values();
        $pickup = $vendors->every(fn ($vendor) => $this->delivery->resolvedRates($vendor)['pickup_enabled']);
        $delivery = $vendors->every(fn ($vendor) => $this->delivery->resolvedRates($vendor)['delivery_enabled']);
        $first = $this->delivery->options($vendors->first());

        return response()->json([
            ...$first,
            'pickup_enabled' => $pickup,
            'delivery_enabled' => $delivery,
            'vendors' => $vendors->map(fn ($vendor) => $this->delivery->options($vendor)['vendor'])->values(),
            'item_count' => $items->sum('quantity'),
            'product_amount' => $this->subtotal($items),
        ]);
    }

    public function quote(Request $request)
    {
        $data = $request->validate([
            'fulfillment' => 'required|in:pickup,delivery',
            'city' => 'nullable|string|max:80',
            'province' => 'nullable|string|max:80',
        ]);

        $items = $this->activeItems($request->user()->id);
        if ($items->isEmpty()) {
            return response()->json(['message' => 'Your cart is empty.'], 422);
        }

        try {
            $quoted = $this->quoteItems($items, $data['fulfillment'], $data['city'] ?? null, $data['province'] ?? null);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $productAmount = $this->subtotal($items);

        return response()->json([
            ...$quoted['summary'],
            'product_amount' => $productAmount,
            'total' => round($productAmount + (float) $quoted['summary']['fee'], 2),
            'stores' => $quoted['stores'],
        ]);
    }

    public function checkout(Request $request)
    {
        $data = $request->validate([
            'success_url' => 'nullable|string|max:500',
            'cancel_url' => 'nullable|string|max:500',
            'fulfillment' => 'required|in:pickup,delivery',
            'payment_method' => 'nullable|in:paymongo,cod',
            'ship_name' => 'nullable|string|max:120',
            'ship_phone' => 'nullable|string|max:40',
            'ship_address' => 'nullable|string|max:255',
            'ship_city' => 'nullable|string|max:80',
            'ship_province' => 'nullable|string|max:80',
        ]);

        $items = $this->activeItems($request->user()->id);
        if ($items->isEmpty()) {
            return response()->json(['message' => 'Your cart is empty.'], 422);
        }

        foreach ($items as $item) {
            $product = $item->product;
            if (! $product) {
                return response()->json(['message' => 'A cart item is no longer available.'], 422);
            }
            try {
                $this->assertCashProduct($product);
            } catch (RuntimeException $e) {
                return response()->json(['message' => $e->getMessage()], 422);
            }
            if ((int) $product->stock < (int) $item->quantity) {
                return response()->json([
                    'message' => (int) $product->stock < 1
                        ? "{$product->name} is out of stock."
                        : "Not enough stock for {$product->name}.",
                ], 422);
            }
        }

        if ($data['fulfillment'] === 'delivery') {
            $request->validate([
                'ship_name' => 'required|string|max:120',
                'ship_phone' => 'required|string|max:40',
                'ship_address' => 'required|string|max:255',
                'ship_city' => 'required|string|max:80',
                'ship_province' => 'required|string|max:80',
            ]);
        }

        try {
            $quoted = $this->quoteItems(
                $items,
                $data['fulfillment'],
                $data['ship_city'] ?? null,
                $data['ship_province'] ?? null,
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $successUrl = $this->safeRedirect($data['success_url'] ?? null);
        $cancelUrl = $this->safeRedirect($data['cancel_url'] ?? null);
        $reference = ProductOrder::newReference();
        $successUrl = $this->withRef($successUrl, $reference);
        $cancelUrl = $this->withRef($cancelUrl, $reference);

        $orders = [];
        $feesAssigned = [];
        foreach ($items as $item) {
            $product = $item->product;
            $vendorId = (int) $product->vendor_id;
            $store = $quoted['stores'][$vendorId] ?? ['fee' => 0, 'zone' => 'pickup', 'island_group' => null];
            $deliveryFee = empty($feesAssigned[$vendorId]) ? (float) $store['fee'] : 0;
            $feesAssigned[$vendorId] = true;
            $productAmount = round(((float) $product->price) * (int) $item->quantity, 2);

            $orders[] = ProductOrder::create([
                'user_id' => $request->user()->id,
                'product_id' => $product->id,
                'vendor_id' => $product->vendor_id,
                'quantity' => (int) $item->quantity,
                'unit_price' => (float) $product->price,
                'product_amount' => $productAmount,
                'delivery_fee' => $deliveryFee,
                'amount' => round($productAmount + $deliveryFee, 2),
                'currency' => 'PHP',
                'status' => 'pending',
                'payment_method' => $data['payment_method'] ?? 'paymongo',
                'fulfillment' => $data['fulfillment'],
                'delivery_zone' => $store['zone'] ?? $quoted['summary']['zone'],
                'ship_name' => $data['ship_name'] ?? $request->user()->name,
                'ship_phone' => $data['ship_phone'] ?? null,
                'ship_address' => $data['ship_address'] ?? $product->vendor?->address,
                'ship_city' => $data['ship_city'] ?? $product->vendor?->city,
                'ship_province' => $data['ship_province'] ?? $product->vendor?->province,
                'ship_island_group' => $store['island_group'] ?? PhilippineRegions::islandGroup($data['ship_province'] ?? $product->vendor?->province),
                'reference_number' => $reference,
            ]);
        }

        if (($data['payment_method'] ?? 'paymongo') === 'cod') {
            foreach ($orders as $order) {
                $order->markPlacedCod();
            }

            return response()->json([
                'orders' => collect($orders)->map->fresh()->values(),
                'order' => $orders[0]->fresh(),
                'payment_method' => 'cod',
            ], 201);
        }

        $lineItems = [];
        foreach ($orders as $order) {
            $product = $order->product()->with('primaryImage')->first() ?? $order->product;
            $row = [
                'name' => mb_substr($product->name, 0, 50),
                'amount' => (int) round(((float) $order->unit_price) * 100),
                'currency' => 'PHP',
                'quantity' => (int) $order->quantity,
                'description' => mb_substr(trim((string) $product->description) ?: 'Bingwit marketplace', 0, 80),
            ];
            $lineItems[] = $row;
        }
        foreach ($quoted['stores'] as $store) {
            if ((float) $store['fee'] < 1) {
                continue;
            }
            $label = count($quoted['stores']) > 1
                ? 'Delivery · '.($store['store_name'] ?? 'Store')
                : 'Delivery';
            $lineItems[] = [
                'name' => mb_substr($label, 0, 50),
                'amount' => (int) round(((float) $store['fee']) * 100),
                'currency' => 'PHP',
                'quantity' => 1,
                'description' => $data['fulfillment'] === 'delivery'
                    ? 'Shipping to '.trim(($data['ship_city'] ?? '').' '.($data['ship_province'] ?? ''))
                    : 'Delivery',
            ];
        }

        try {
            $session = $this->paymongo->createCheckoutSession(
                $request->user(),
                $reference,
                'Bingwit · '.count($orders).' item'.(count($orders) === 1 ? '' : 's'),
                $lineItems,
                $successUrl,
                $cancelUrl,
                [
                    'user_id' => (string) $request->user()->id,
                    'reference' => $reference,
                    'item_count' => (string) count($orders),
                ],
            );
        } catch (RuntimeException $e) {
            foreach ($orders as $order) {
                $order->update(['status' => 'failed']);
            }

            return response()->json(['message' => $e->getMessage()], 422);
        }

        $checkoutId = $session['data']['id'] ?? null;
        $checkoutUrl = $this->paymongo->checkoutUrl($session);

        if (! $checkoutId || ! $checkoutUrl) {
            foreach ($orders as $order) {
                $order->update(['status' => 'failed']);
            }

            return response()->json(['message' => 'PayMongo did not return a checkout URL.'], 502);
        }

        foreach ($orders as $order) {
            $order->update(['paymongo_checkout_id' => $checkoutId]);
        }

        return response()->json([
            'orders' => collect($orders)->map->fresh()->values(),
            'order' => $orders[0]->fresh(),
            'checkout_url' => $checkoutUrl,
            'checkout_id' => $checkoutId,
        ], 201);
    }

    private function assertCashProduct(Product $product): void
    {
        if ($product->isClaimableWithStars()) {
            throw new RuntimeException('Stars-only items cannot be added to the cart.');
        }
        if ((float) $product->price < 1) {
            throw new RuntimeException('This item is not for sale.');
        }
    }

    private function activeItems(int $userId)
    {
        return CartItem::with(['product.primaryImage', 'product.vendor'])
            ->where('user_id', $userId)
            ->get()
            ->filter(function (CartItem $item) {
                $product = $item->product;

                return $product
                    && $product->is_active
                    && ! $product->isClaimableWithStars()
                    && (float) $product->price >= 1;
            })
            ->values();
    }

    private function subtotal($items): float
    {
        return round($items->sum(function (CartItem $item) {
            return ((float) $item->product->price) * (int) $item->quantity;
        }), 2);
    }

    private function payload(int $userId): array
    {
        $items = $this->activeItems($userId)->map(function (CartItem $item) {
            $product = $item->product;

            return [
                'id' => $item->id,
                'product_id' => $product->id,
                'quantity' => (int) $item->quantity,
                'line_total' => round(((float) $product->price) * (int) $item->quantity, 2),
                'product' => [
                    'id' => $product->id,
                    'name' => $product->name,
                    'price' => $product->price,
                    'stock' => $product->stock,
                    'primary_image_url' => $product->primary_image_url,
                    'vendor' => $product->vendor ? [
                        'id' => $product->vendor->id,
                        'store_name' => $product->vendor->store_name,
                        'name' => $product->vendor->name ?? null,
                    ] : null,
                ],
            ];
        })->values();

        return [
            'items' => $items,
            'count' => $items->sum('quantity'),
            'subtotal' => $items->sum('line_total'),
        ];
    }

    private function quoteItems($items, string $fulfillment, ?string $city, ?string $province): array
    {
        $stores = [];
        foreach ($items->groupBy(fn (CartItem $item) => $item->product->vendor_id) as $vendorId => $group) {
            $vendor = $group->first()->product?->vendor;
            $quote = $this->delivery->quote($vendor, $fulfillment, $city, $province);
            $stores[(int) $vendorId] = [
                ...$quote,
                'store_name' => $vendor?->store_name,
                'vendor_id' => (int) $vendorId,
            ];
        }

        $fee = round(collect($stores)->sum('fee'), 2);
        $zones = collect($stores)->pluck('zone')->unique()->values();
        $first = reset($stores) ?: [];

        return [
            'stores' => $stores,
            'summary' => [
                'fulfillment' => $fulfillment,
                'zone' => $zones->count() === 1 ? $zones->first() : 'mixed',
                'zone_label' => $zones->count() > 1
                    ? 'Combined delivery'
                    : ($first['zone_label'] ?? ($fulfillment === 'pickup' ? 'Store pickup' : 'Delivery')),
                'fee' => $fee,
                'city' => $city,
                'province' => $province,
                'island_group' => $first['island_group'] ?? PhilippineRegions::islandGroup($province),
            ],
        ];
    }

    private function withRef(string $url, string $ref): string
    {
        $join = str_contains($url, '?') ? '&' : '?';

        return $url.$join.'ref='.urlencode($ref);
    }

    private function safeRedirect(?string $url): string
    {
        $fallback = rtrim((string) config('app.url'), '/').'/api/marketplace/checkout/return';
        if (! $url) {
            return $fallback;
        }

        if (preg_match('/^(https?:\/\/|bingwitapp:\/\/|exp\+?[\w.-]*:\/\/)/i', $url)) {
            return $url;
        }

        return $fallback;
    }
}
