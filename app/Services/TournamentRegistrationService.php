<?php

namespace App\Services;

use App\Models\Tournament;
use App\Models\TournamentParticipant;
use App\Models\User;
use Illuminate\Http\Request;
use RuntimeException;

class TournamentRegistrationService
{
    public function __construct(private PayMongoService $paymongo)
    {
    }

    public function register(Request $request, Tournament $tournament): array
    {
        $user = $request->user();
        $this->assertOpen($tournament);

        $existing = TournamentParticipant::where('tournament_id', $tournament->id)
            ->where('user_id', $user->id)
            ->first();

        if ($existing?->isSettled()) {
            return [
                'message'             => 'You are registered for this tournament!',
                'participant'         => $existing->fresh(),
                'already_registered'  => true,
                'payment_required'    => false,
            ];
        }

        $this->assertCapacity($tournament, $existing);

        $fee = round((float) $tournament->entry_fee, 2);

        if ($fee < 1) {
            $participant = $this->upsertParticipant($tournament, $user, $existing, [
                'status'           => 'registered',
                'payment_status'   => 'free',
                'payment_method'   => 'free',
                'entry_fee_amount' => 0,
                'registered_at'    => now(),
                'paid_at'          => now(),
            ]);

            return [
                'message'          => 'You are registered for this tournament!',
                'participant'      => $participant,
                'payment_required' => false,
            ];
        }

        $data = $request->validate([
            'success_url' => 'nullable|string|max:500',
            'cancel_url'  => 'nullable|string|max:500',
        ]);

        $participant = $this->upsertParticipant($tournament, $user, $existing, [
            'status'           => 'registered',
            'payment_status'   => 'unpaid',
            'payment_method'   => 'paymongo',
            'entry_fee_amount' => $fee,
            'registered_at'    => null,
        ]);

        $reused = $this->reuseCheckout($participant);
        if ($reused) {
            return $reused;
        }

        $successUrl = $this->withRef(
            $this->safeRedirect($data['success_url'] ?? null),
            $participant->reference_number ?: $this->assignReference($participant)
        );
        $cancelUrl = $this->withRef(
            $this->safeRedirect($data['cancel_url'] ?? null, 'cancel'),
            $participant->reference_number
        );

        try {
            $session = $this->paymongo->createCheckoutSession(
                $user,
                $participant->reference_number,
                'Bingwit · '.$tournament->name,
                [[
                    'name'        => mb_substr($tournament->name, 0, 50),
                    'amount'      => (int) round($fee * 100),
                    'currency'    => 'PHP',
                    'quantity'    => 1,
                    'description' => mb_substr('Tournament entry · '.$tournament->name, 0, 80),
                ]],
                $successUrl,
                $cancelUrl,
                [
                    'type'           => 'tournament',
                    'tournament_id'  => (string) $tournament->id,
                    'participant_id' => (string) $participant->id,
                    'user_id'        => (string) $user->id,
                ],
            );
        } catch (RuntimeException $e) {
            $participant->update(['payment_status' => 'failed']);

            throw $e;
        }

        $checkoutId = $session['data']['id'] ?? null;
        $checkoutUrl = $this->paymongo->checkoutUrl($session);

        if (! $checkoutId || ! $checkoutUrl) {
            $participant->update(['payment_status' => 'failed']);

            throw new RuntimeException('PayMongo did not return a checkout URL.');
        }

        $participant->update(['paymongo_checkout_id' => $checkoutId]);

        return [
            'message'          => 'Complete payment to join this tournament.',
            'participant'      => $participant->fresh(),
            'checkout_url'     => $checkoutUrl,
            'checkout_id'      => $checkoutId,
            'payment_required' => true,
        ];
    }

    public function sync(User $user, Tournament $tournament): array
    {
        $participant = TournamentParticipant::where('tournament_id', $tournament->id)
            ->where('user_id', $user->id)
            ->first();

        if (! $participant) {
            throw new RuntimeException('No registration found for this tournament.');
        }

        $this->refreshFromPayMongo($participant);

        $participant->refresh();

        if ($participant->isSettled()) {
            return [
                'message'     => 'You are registered for this tournament!',
                'participant' => $participant,
            ];
        }

        if ($participant->payment_status === 'cancelled') {
            return [
                'message'     => 'Checkout was cancelled. No charge was made.',
                'participant' => $participant,
            ];
        }

        return [
            'message'     => 'If you already paid, your registration will update shortly.',
            'participant' => $participant,
        ];
    }

    public function refreshFromPayMongo(TournamentParticipant $participant): void
    {
        if ($participant->isSettled() || ! $participant->paymongo_checkout_id) {
            return;
        }

        try {
            $session = $this->paymongo->retrieveCheckout($participant->paymongo_checkout_id);
        } catch (RuntimeException) {
            return;
        }

        if ($this->paymongo->isPaid($session)) {
            $participant->markPaid($this->paymongo->paymentId($session));

            return;
        }

        if ($this->paymongo->isExpired($session)) {
            $participant->markCancelled();
        }
    }

    public function confirmPaid(TournamentParticipant $participant, ?string $paymentId = null): void
    {
        $participant->markPaid($paymentId);
    }

    public function markCancelled(TournamentParticipant $participant): void
    {
        $participant->markCancelled();
    }

    private function reuseCheckout(TournamentParticipant $participant): ?array
    {
        if (! $participant->paymongo_checkout_id || $participant->payment_status !== 'unpaid') {
            return null;
        }

        try {
            $session = $this->paymongo->retrieveCheckout($participant->paymongo_checkout_id);
        } catch (RuntimeException) {
            return null;
        }

        if ($this->paymongo->isPaid($session)) {
            $participant->markPaid($this->paymongo->paymentId($session));

            return [
                'message'            => 'You are registered for this tournament!',
                'participant'        => $participant->fresh(),
                'already_registered' => true,
                'payment_required'   => false,
            ];
        }

        if ($this->paymongo->isExpired($session)) {
            return null;
        }

        $checkoutUrl = $this->paymongo->checkoutUrl($session);
        if (! $checkoutUrl) {
            return null;
        }

        return [
            'message'          => 'Complete payment to join this tournament.',
            'participant'      => $participant->fresh(),
            'checkout_url'     => $checkoutUrl,
            'checkout_id'      => $participant->paymongo_checkout_id,
            'payment_required' => true,
        ];
    }

    private function upsertParticipant(
        Tournament $tournament,
        User $user,
        ?TournamentParticipant $existing,
        array $attrs,
    ): TournamentParticipant {
        if ($existing) {
            $existing->update($attrs);

            if (empty($existing->reference_number)) {
                $this->assignReference($existing);
            }

            return $existing->fresh();
        }

        $participant = TournamentParticipant::create([
            'tournament_id' => $tournament->id,
            'user_id'       => $user->id,
            ...$attrs,
        ]);

        $this->assignReference($participant);

        return $participant->fresh();
    }

    private function assignReference(TournamentParticipant $participant): string
    {
        $ref = $participant->reference_number ?: TournamentParticipant::newReference();
        if ($participant->reference_number !== $ref) {
            $participant->update(['reference_number' => $ref]);
            $participant->reference_number = $ref;
        }

        return $ref;
    }

    private function assertOpen(Tournament $tournament): void
    {
        if (in_array($tournament->status, ['completed', 'cancelled'], true)) {
            throw new RuntimeException('Registration is closed for this tournament.');
        }

        if ($tournament->registration_deadline
            && now()->greaterThan($tournament->registration_deadline)
        ) {
            throw new RuntimeException('The registration deadline has passed.');
        }
    }

    private function assertCapacity(Tournament $tournament, ?TournamentParticipant $existing): void
    {
        if (! $tournament->max_participants) {
            return;
        }

        $current = $tournament->occupyingCount();
        if ($existing && in_array($existing->payment_status, ['unpaid', 'paid', 'free'], true)
            && in_array($existing->status, ['registered', 'confirmed'], true)
        ) {
            return;
        }

        if ($current >= $tournament->max_participants) {
            throw new RuntimeException('This tournament is full.');
        }
    }

    private function safeRedirect(?string $url, string $status = 'success'): string
    {
        $fallback = rtrim((string) config('app.url'), '/').'/api/tournaments/checkout/return?status='.$status;
        if (! $url) {
            return $fallback;
        }

        if ($url && preg_match('/^https?:\/\//i', $url)) {
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
