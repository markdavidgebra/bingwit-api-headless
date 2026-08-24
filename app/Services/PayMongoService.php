<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductOrder;
use App\Models\User;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class PayMongoService
{
    private const API = 'https://api.paymongo.com';

    public function secretKey(): string
    {
        $key = (string) config('services.paymongo.secret_key');
        if ($key === '') {
            throw new RuntimeException('PayMongo secret key is not configured.');
        }

        return $key;
    }

    public function createCheckout(ProductOrder $order, Product $product, User $user, string $successUrl, string $cancelUrl): array
    {
        $lineItems = [[
            'name'        => mb_substr($product->name, 0, 50),
            'amount'      => (int) round(((float) $order->unit_price) * 100),
            'currency'    => 'PHP',
            'quantity'    => (int) $order->quantity,
            'description' => mb_substr(trim((string) $product->description) ?: 'Bingwit marketplace', 0, 80),
        ]];

        $deliveryFee = (float) ($order->delivery_fee ?? 0);
        if ($deliveryFee >= 1) {
            $lineItems[] = [
                'name'        => 'Delivery',
                'amount'      => (int) round($deliveryFee * 100),
                'currency'    => 'PHP',
                'quantity'    => 1,
                'description' => $order->fulfillment === 'delivery'
                    ? 'Shipping to '.trim(($order->ship_city ?? '').' '.($order->ship_province ?? ''))
                    : 'Delivery',
            ];
        }

        return $this->createCheckoutSession(
            $user,
            $order->reference_number,
            'Bingwit · '.$product->name,
            $lineItems,
            $successUrl,
            $cancelUrl,
            [
                'order_id'   => (string) $order->id,
                'product_id' => (string) $product->id,
                'user_id'    => (string) $user->id,
            ],
        );
    }

    public function createCheckoutSession(
        User $user,
        string $reference,
        string $description,
        array $lineItems,
        string $successUrl,
        string $cancelUrl,
        array $metadata = [],
    ): array {
        $amount = 0;
        foreach ($lineItems as &$item) {
            $amount += ((int) ($item['amount'] ?? 0)) * ((int) ($item['quantity'] ?? 1));
            unset($item['images']);
        }
        unset($item);
        if ($amount < 100) {
            throw new RuntimeException('PayMongo requires a minimum of ₱1.00.');
        }

        $payload = [
            'data' => [
                'attributes' => [
                    'line_items'           => $lineItems,
                    'payment_method_types' => config('services.paymongo.payment_methods'),
                    'success_url'          => $successUrl,
                    'cancel_url'           => $cancelUrl,
                    'reference_number'     => $reference,
                    'description'          => mb_substr($description, 0, 255),
                    'send_email_receipt'   => true,
                    'show_description'     => true,
                    'show_line_items'      => true,
                    'metadata'             => $metadata,
                ],
            ],
        ];

        if ($user->email) {
            $payload['data']['attributes']['billing'] = [
                'name'  => $user->name ?: 'Bingwit angler',
                'email' => $user->email,
            ];
        }

        return $this->request('POST', '/v1/checkout_sessions', $payload);
    }

    public function retrieveCheckout(string $checkoutId): array
    {
        return $this->request('GET', '/v1/checkout_sessions/'.$checkoutId);
    }

    public function checkoutUrl(array $session): ?string
    {
        return $session['data']['attributes']['checkout_url'] ?? null;
    }

    public function checkoutStatus(array $session): string
    {
        return (string) ($session['data']['attributes']['status'] ?? '');
    }

    public function paymentId(array $session): ?string
    {
        $payments = $session['data']['attributes']['payments'] ?? [];
        if (! is_array($payments) || $payments === []) {
            return null;
        }

        $first = $payments[0]['id'] ?? $payments[0]['data']['id'] ?? null;

        return is_string($first) ? $first : null;
    }

    public function isPaid(array $session): bool
    {
        $status = strtolower($this->checkoutStatus($session));
        if (in_array($status, ['paid', 'active'], true) && $this->paymentId($session)) {
            return true;
        }

        $payments = $session['data']['attributes']['payments'] ?? [];
        foreach (is_array($payments) ? $payments : [] as $payment) {
            $paymentStatus = strtolower((string) (
                $payment['attributes']['status']
                ?? $payment['data']['attributes']['status']
                ?? ''
            ));
            if (in_array($paymentStatus, ['paid', 'succeeded'], true)) {
                return true;
            }
        }

        return $status === 'paid';
    }

    public function isExpired(array $session): bool
    {
        return in_array(strtolower($this->checkoutStatus($session)), ['expired', 'cancelled'], true);
    }

    public function verifyWebhookSignature(string $payload, ?string $header): bool
    {
        $secret = (string) config('services.paymongo.webhook_secret');
        if ($secret === '' || ! $header) {
            return $secret === '';
        }

        $parts = [];
        foreach (explode(',', $header) as $chunk) {
            [$key, $value] = array_pad(explode('=', trim($chunk), 2), 2, '');
            $parts[$key] = $value;
        }

        $timestamp = $parts['t'] ?? '';
        $testSig = $parts['te'] ?? '';
        $liveSig = $parts['li'] ?? '';
        $expected = hash_hmac('sha256', $timestamp.'.'.$payload, $secret);

        return hash_equals($expected, $testSig) || hash_equals($expected, $liveSig);
    }

    private function request(string $method, string $path, array $payload = []): array
    {
        $http = Http::withBasicAuth($this->secretKey(), '')
            ->acceptJson()
            ->asJson()
            ->timeout(20);

        $response = $method === 'GET'
            ? $http->get(self::API.$path)
            : $http->send($method, self::API.$path, ['json' => $payload]);

        if ($response->failed()) {
            $detail = $response->json('errors.0.detail')
                ?? $response->json('errors.0.title')
                ?? $response->body();

            throw new RuntimeException(
                is_string($detail) && $detail !== ''
                    ? $detail
                    : 'PayMongo request failed.',
                $response->status()
            );
        }

        try {
            return $response->json() ?? [];
        } catch (RequestException $e) {
            throw new RuntimeException('PayMongo returned an invalid response.');
        }
    }
}
