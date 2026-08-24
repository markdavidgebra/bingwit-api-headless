<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductOrder;
use App\Models\TournamentParticipant;
use App\Services\DeliveryFeeService;
use App\Services\PayMongoService;
use App\Support\PhilippineRegions;
use Illuminate\Http\Request;
use RuntimeException;

class MarketplaceCheckoutController extends Controller
{
    private const PRODUCT_WITH = ['product.primaryImage'];

    public function __construct(
        private PayMongoService $paymongo,
        private DeliveryFeeService $delivery,
    ) {
    }

    public function options($id)
    {
        $product = Product::with('vendor')->where('is_active', true)->findOrFail($id);

        return response()->json($this->delivery->options($product->vendor));
    }

    public function quote(Request $request, $id)
    {
        $data = $request->validate([
            'fulfillment' => 'required|in:pickup,delivery',
            'city' => 'nullable|string|max:80',
            'province' => 'nullable|string|max:80',
        ]);

        $product = Product::with('vendor')->where('is_active', true)->findOrFail($id);

        try {
            $quote = $this->delivery->quote(
                $product->vendor,
                $data['fulfillment'],
                $data['city'] ?? null,
                $data['province'] ?? null,
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $productAmount = (float) $product->price;

        return response()->json([
            ...$quote,
            'product_amount' => $productAmount,
            'total' => round($productAmount + (float) $quote['fee'], 2),
        ]);
    }

    public function checkout(Request $request, $id)
    {
        $data = $request->validate([
            'quantity'     => 'nullable|integer|min:1|max:10',
            'success_url'  => 'nullable|string|max:500',
            'cancel_url'   => 'nullable|string|max:500',
            'fulfillment'  => 'required|in:pickup,delivery',
            'payment_method' => 'nullable|in:paymongo,cod',
            'ship_name'    => 'nullable|string|max:120',
            'ship_phone'   => 'nullable|string|max:40',
            'ship_address' => 'nullable|string|max:255',
            'ship_city'    => 'nullable|string|max:80',
            'ship_province'=> 'nullable|string|max:80',
        ]);

        $product = Product::with('vendor')->where('is_active', true)->findOrFail($id);

        if ($product->isClaimableWithStars()) {
            return response()->json([
                'message' => 'This item can only be claimed with Stars.',
            ], 422);
        }

        $quantity = (int) ($data['quantity'] ?? 1);
        $price = (float) $product->price;

        if ($price < 1) {
            return response()->json([
                'message' => 'This item is not for sale.',
            ], 422);
        }

        if ((int) $product->stock < $quantity) {
            return response()->json([
                'message' => (int) $product->stock < 1
                    ? 'This item is out of stock.'
                    : 'Not enough stock for that quantity.',
            ], 422);
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
            $quote = $this->delivery->quote(
                $product->vendor,
                $data['fulfillment'],
                $data['ship_city'] ?? null,
                $data['ship_province'] ?? null,
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $productAmount = round($price * $quantity, 2);
        $deliveryFee = (float) $quote['fee'];

        $successUrl = $this->safeRedirect(
            $data['success_url'] ?? config('services.paymongo.success_url')
        );
        $cancelUrl = $this->safeRedirect(
            $data['cancel_url'] ?? config('services.paymongo.cancel_url')
        );

        $order = ProductOrder::create([
            'user_id'            => $request->user()->id,
            'product_id'         => $product->id,
            'vendor_id'          => $product->vendor_id,
            'quantity'           => $quantity,
            'unit_price'         => $price,
            'product_amount'     => $productAmount,
            'delivery_fee'       => $deliveryFee,
            'amount'             => round($productAmount + $deliveryFee, 2),
            'currency'           => 'PHP',
            'status'             => 'pending',
            'payment_method'     => $data['payment_method'] ?? 'paymongo',
            'fulfillment'        => $data['fulfillment'],
            'delivery_zone'      => $quote['zone'],
            'ship_name'          => $data['ship_name'] ?? $request->user()->name,
            'ship_phone'         => $data['ship_phone'] ?? null,
            'ship_address'       => $data['ship_address'] ?? $product->vendor?->address,
            'ship_city'          => $data['ship_city'] ?? $product->vendor?->city,
            'ship_province'      => $data['ship_province'] ?? $product->vendor?->province,
            'ship_island_group'  => $quote['island_group'] ?? PhilippineRegions::islandGroup($data['ship_province'] ?? $product->vendor?->province),
            'reference_number'   => ProductOrder::newReference(),
        ]);

        if (($data['payment_method'] ?? 'paymongo') === 'cod') {
            $order->markPlacedCod();

            return response()->json([
                'order' => $order->fresh(),
                'payment_method' => 'cod',
            ], 201);
        }

        $successUrl = $this->withRef($successUrl, $order->reference_number);
        $cancelUrl = $this->withRef($cancelUrl, $order->reference_number);

        try {
            $session = $this->paymongo->createCheckout(
                $order,
                $product,
                $request->user(),
                $successUrl,
                $cancelUrl
            );
        } catch (RuntimeException $e) {
            $order->update(['status' => 'failed']);

            return response()->json(['message' => $e->getMessage()], 422);
        }

        $checkoutId = $session['data']['id'] ?? null;
        $checkoutUrl = $this->paymongo->checkoutUrl($session);

        if (! $checkoutId || ! $checkoutUrl) {
            $order->update(['status' => 'failed']);

            return response()->json(['message' => 'PayMongo did not return a checkout URL.'], 502);
        }

        $order->update(['paymongo_checkout_id' => $checkoutId]);

        return response()->json([
            'order'        => $order->fresh(),
            'checkout_url' => $checkoutUrl,
            'checkout_id'  => $checkoutId,
        ], 201);
    }

    public function sync(Request $request, $id)
    {
        $order = ProductOrder::where('user_id', $request->user()->id)->findOrFail($id);

        $this->refreshFromPayMongo($order);

        return response()->json(['order' => $order->fresh()->load(self::PRODUCT_WITH)]);
    }

    public function show(Request $request, $id)
    {
        $order = ProductOrder::with(self::PRODUCT_WITH)
            ->where('user_id', $request->user()->id)
            ->findOrFail($id);

        return response()->json(['order' => $order]);
    }

    public function mine(Request $request)
    {
        $rows = ProductOrder::with(self::PRODUCT_WITH)
            ->where('user_id', $request->user()->id)
            ->latest()
            ->get();

        return response()->json(['orders' => $rows]);
    }

    public function webhook(Request $request)
    {
        $raw = $request->getContent();
        if (! $this->paymongo->verifyWebhookSignature($raw, $request->header('Paymongo-Signature'))) {
            return response()->json(['message' => 'Invalid signature.'], 401);
        }

        $type = $request->input('data.attributes.type') ?? $request->input('data.type');
        $session = $request->input('data.attributes.data') ?? $request->input('data.attributes.data.data');

        $checkoutId = is_array($session) ? ($session['id'] ?? null) : null;
        $reference = is_array($session)
            ? ($session['attributes']['reference_number'] ?? null)
            : null;

        $order = null;
        if ($checkoutId) {
            $order = ProductOrder::where('paymongo_checkout_id', $checkoutId)->first();
        }
        if (! $order && $reference) {
            $order = ProductOrder::where('reference_number', $reference)->first();
        }

        $paidEvent = is_string($type) && str_contains($type, 'payment.paid');

        if (! $order) {
            $participant = null;
            if ($checkoutId) {
                $participant = TournamentParticipant::where('paymongo_checkout_id', $checkoutId)->first();
            }
            if (! $participant && $reference) {
                $participant = TournamentParticipant::where('reference_number', $reference)->first();
            }

            if ($participant && ($paidEvent || ($checkoutId && $this->sessionIsPaid($checkoutId)))) {
                $participant->markPaid($this->paymongo->paymentId(['data' => $session ?? []]));
            } elseif ($participant && is_string($type) && (
                str_contains($type, 'expired') || str_contains($type, 'cancelled')
            ) && $participant->payment_status === 'unpaid') {
                $participant->markCancelled();
            }

            return response()->json(['received' => true]);
        }

        if ($paidEvent || ($checkoutId && $this->sessionIsPaid($checkoutId))) {
            $this->markCheckoutPaid($order, $this->paymongo->paymentId(['data' => $session ?? []]));
        }

        return response()->json(['received' => true]);
    }

    public function returnPage(Request $request)
    {
        $ref = (string) $request->query('ref', '');
        $status = (string) $request->query('status', 'success');
        $order = $ref !== ''
            ? ProductOrder::where('reference_number', $ref)->first()
            : null;

        if ($order && $status === 'success') {
            $this->refreshFromPayMongo($order);
            $order->refresh();
        }

        if ($order && $status === 'cancel' && $order->status === 'pending') {
            $this->markCheckoutCancelled($order);
        }

        $scheme = $status === 'cancel' ? 'bingwitapp://checkout/cancel' : 'bingwitapp://checkout/success';
        $deep = $scheme.($ref !== '' ? '?ref='.urlencode($ref) : '');
        $paid = $order?->isPaid();
        $title = $paid ? 'Payment received' : ($status === 'cancel' ? 'Checkout cancelled' : 'Return to Bingwit');
        $body = $paid
            ? 'You can close this page and go back to Bingwit.'
            : ($status === 'cancel'
                ? 'No charge was made.'
                : 'If you already paid, your order will update in the app.');

        return response(
            '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            .'<title>'.e($title).'</title>'
            .'<style>body{font-family:system-ui,sans-serif;background:#F5F1E8;color:#013055;display:flex;min-height:100vh;align-items:center;justify-content:center;margin:0}'
            .'.card{background:#fff;border-radius:20px;padding:28px 24px;max-width:360px;text-align:center;box-shadow:0 10px 30px rgba(1,48,85,.08)}'
            .'h1{font-size:22px;margin:0 0 8px}p{color:#64748B;line-height:1.5}a{display:inline-block;margin-top:16px;background:#013055;color:#fff;text-decoration:none;padding:12px 18px;border-radius:999px;font-weight:600}</style>'
            .'<meta http-equiv="refresh" content="0;url='.e($deep).'"></head><body><div class="card">'
            .'<h1>'.e($title).'</h1><p>'.e($body).'</p>'
            .'<a href="'.e($deep).'">Back to Bingwit</a></div>'
            .'<script>location.replace('.json_encode($deep).');</script></body></html>',
            200,
            ['Content-Type' => 'text/html; charset=UTF-8']
        );
    }

    private function refreshFromPayMongo(ProductOrder $order): void
    {
        if ($order->isPaid() || $order->isCod() || ! $order->paymongo_checkout_id) {
            return;
        }

        try {
            $session = $this->paymongo->retrieveCheckout($order->paymongo_checkout_id);
        } catch (RuntimeException) {
            return;
        }

        if ($this->paymongo->isPaid($session)) {
            $this->markCheckoutPaid($order, $this->paymongo->paymentId($session));

            return;
        }

        if ($this->paymongo->isExpired($session)) {
            $this->markCheckoutCancelled($order);
        }
    }

    public function markCheckoutPaid(ProductOrder $order, ?string $paymentId = null): void
    {
        foreach ($order->checkoutSiblings()->get() as $sibling) {
            $sibling->markPaid($paymentId);
        }
    }

    public function markCheckoutCancelled(ProductOrder $order): void
    {
        foreach ($order->checkoutSiblings()->get() as $sibling) {
            $sibling->markCancelled();
        }
    }

    private function sessionIsPaid(string $checkoutId): bool
    {
        try {
            return $this->paymongo->isPaid($this->paymongo->retrieveCheckout($checkoutId));
        } catch (RuntimeException) {
            return false;
        }
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

    private function withRef(string $url, string $ref): string
    {
        $join = str_contains($url, '?') ? '&' : '?';

        return $url.$join.'ref='.urlencode($ref);
    }

}
