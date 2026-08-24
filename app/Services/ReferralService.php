<?php

namespace App\Services;

use App\Models\Notification;
use App\Models\User;
use App\Models\WalletTransaction;

class ReferralService
{
    public function __construct(private WalletService $wallet)
    {
    }

    public function applyOnRegister(User $newUser, ?string $code): void
    {
        $code = strtoupper(preg_replace('/[^A-Z0-9]/', '', (string) $code) ?? '');
        if ($code === '' || $newUser->referred_by_user_id) {
            return;
        }

        $referrer = User::query()
            ->whereRaw('UPPER(referral_code) = ?', [$code])
            ->first();

        if (! $referrer || $referrer->id === $newUser->id) {
            return;
        }

        $newUser->referred_by_user_id = $referrer->id;
        $newUser->save();

        $bonus = $this->wallet->setting('fish_points_referral_bonus', '25');
        if ($bonus < 1) {
            return;
        }

        $already = WalletTransaction::where('user_id', $referrer->id)
            ->where('type', 'referral_signup')
            ->where('reference_type', 'user')
            ->where('reference_id', $newUser->id)
            ->exists();

        if ($already) {
            return;
        }

        $this->wallet->creditFishPoints(
            $referrer,
            $bonus,
            'referral_signup',
            'user',
            (int) $newUser->id,
            'Referral signup: ' . $newUser->name
        );

        Notification::create([
            'user_id'        => $referrer->id,
            'type'           => 'referral_signup',
            'title'          => "You earned {$bonus} Fish Points!",
            'body'           => $newUser->name . ' joined Bingwit with your affiliate link.',
            'reference_id'   => $newUser->id,
            'reference_type' => 'user',
        ]);
    }
}
