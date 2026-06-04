<?php

namespace App\Services;

use App\Models\EconomySetting;
use App\Models\User;
use App\Models\WalletTransaction;
use Illuminate\Support\Facades\DB;

class WalletService
{
    public function setting(string $key, string $default = '0'): int
    {
        $row = EconomySetting::where('key', $key)->first();

        return (int) ($row?->value ?? $default);
    }

    public function creditFishPoints(
        User $user,
        int $amount,
        string $type,
        ?string $referenceType = null,
        ?int $referenceId = null,
        ?string $note = null
    ): void {
        if ($amount <= 0) {
            return;
        }

        DB::transaction(function () use ($user, $amount, $type, $referenceType, $referenceId, $note) {
            $user->increment('fish_points', $amount);

            WalletTransaction::create([
                'user_id'            => $user->id,
                'type'               => $type,
                'fish_points_delta'  => $amount,
                'stars_delta'        => 0,
                'reference_type'     => $referenceType,
                'reference_id'       => $referenceId,
                'note'               => $note,
            ]);
        });
    }

    public function transferFishPoints(
        User $from,
        User $to,
        int $fishPoints,
        string $type,
        ?string $referenceType = null,
        ?int $referenceId = null,
        ?string $note = null
    ): void {
        if ($fishPoints <= 0) {
            throw new \InvalidArgumentException('Fish Points must be positive.');
        }

        DB::transaction(function () use ($from, $to, $fishPoints, $type, $referenceType, $referenceId, $note) {
            $fromLocked = User::where('id', $from->id)->lockForUpdate()->first();
            $toLocked   = User::where('id', $to->id)->lockForUpdate()->first();

            if ($fromLocked->fish_points < $fishPoints) {
                throw new \RuntimeException('Not enough Fish Points in your wallet.');
            }

            $fromLocked->decrement('fish_points', $fishPoints);
            $toLocked->increment('fish_points', $fishPoints);

            WalletTransaction::create([
                'user_id'           => $fromLocked->id,
                'type'              => $type,
                'fish_points_delta' => -$fishPoints,
                'stars_delta'       => 0,
                'reference_type'    => $referenceType,
                'reference_id'      => $referenceId,
                'note'              => $note,
            ]);

            WalletTransaction::create([
                'user_id'           => $toLocked->id,
                'type'              => $type . '_received',
                'fish_points_delta' => $fishPoints,
                'stars_delta'       => 0,
                'reference_type'    => $referenceType,
                'reference_id'      => $referenceId,
                'note'              => $note,
            ]);
        });
    }

    public function spendFishPoints(
        User $user,
        int $fishPoints,
        string $type,
        ?string $referenceType = null,
        ?int $referenceId = null,
        ?string $note = null
    ): void {
        if ($fishPoints <= 0) {
            throw new \InvalidArgumentException('Fish Points must be positive.');
        }

        DB::transaction(function () use ($user, $fishPoints, $type, $referenceType, $referenceId, $note) {
            $locked = User::where('id', $user->id)->lockForUpdate()->first();

            if ($locked->fish_points < $fishPoints) {
                throw new \RuntimeException('Not enough Fish Points in your wallet.');
            }

            $locked->decrement('fish_points', $fishPoints);

            WalletTransaction::create([
                'user_id'           => $locked->id,
                'type'              => $type,
                'fish_points_delta' => -$fishPoints,
                'stars_delta'       => 0,
                'reference_type'    => $referenceType,
                'reference_id'      => $referenceId,
                'note'              => $note,
            ]);
        });
    }

    public function transferStars(
        User $from,
        User $to,
        int $stars,
        string $type,
        ?string $referenceType = null,
        ?int $referenceId = null,
        ?string $note = null
    ): void {
        if ($stars <= 0) {
            throw new \InvalidArgumentException('Stars must be positive.');
        }

        DB::transaction(function () use ($from, $to, $stars, $type, $referenceType, $referenceId, $note) {
            $fromLocked = User::where('id', $from->id)->lockForUpdate()->first();
            $toLocked   = User::where('id', $to->id)->lockForUpdate()->first();

            if ($fromLocked->stars < $stars) {
                throw new \RuntimeException('Not enough stars in your wallet.');
            }

            $fromLocked->decrement('stars', $stars);
            $toLocked->increment('stars', $stars);

            WalletTransaction::create([
                'user_id'           => $fromLocked->id,
                'type'              => $type,
                'fish_points_delta' => 0,
                'stars_delta'       => -$stars,
                'reference_type'    => $referenceType,
                'reference_id'      => $referenceId,
                'note'              => $note,
            ]);

            WalletTransaction::create([
                'user_id'           => $toLocked->id,
                'type'              => $type . '_received',
                'fish_points_delta' => 0,
                'stars_delta'       => $stars,
                'reference_type'    => $referenceType,
                'reference_id'      => $referenceId,
                'note'              => $note,
            ]);
        });
    }

    public function spendStars(
        User $user,
        int $stars,
        string $type,
        ?string $referenceType = null,
        ?int $referenceId = null,
        ?string $note = null
    ): void {
        if ($stars <= 0) {
            throw new \InvalidArgumentException('Stars must be positive.');
        }

        DB::transaction(function () use ($user, $stars, $type, $referenceType, $referenceId, $note) {
            $locked = User::where('id', $user->id)->lockForUpdate()->first();

            if ($locked->stars < $stars) {
                throw new \RuntimeException('Not enough stars in your wallet.');
            }

            $locked->decrement('stars', $stars);

            WalletTransaction::create([
                'user_id'           => $locked->id,
                'type'              => $type,
                'fish_points_delta' => 0,
                'stars_delta'       => -$stars,
                'reference_type'    => $referenceType,
                'reference_id'      => $referenceId,
                'note'              => $note,
            ]);
        });
    }

    public function convertFishPointsToStars(User $user, int $fishPoints): int
    {
        $rate = $this->setting('fish_points_per_star', '10');
        if ($rate < 1) {
            throw new \RuntimeException('Conversion rate is not configured.');
        }

        if ($fishPoints < $rate) {
            throw new \RuntimeException("You need at least {$rate} Fish Points for 1 Star.");
        }

        if ($fishPoints % $rate !== 0) {
            throw new \RuntimeException("Fish Points must be a multiple of {$rate}.");
        }

        $starsGained = (int) ($fishPoints / $rate);

        DB::transaction(function () use ($user, $fishPoints, $starsGained) {
            $locked = User::where('id', $user->id)->lockForUpdate()->first();

            if ($locked->fish_points < $fishPoints) {
                throw new \RuntimeException('Not enough Fish Points.');
            }

            $locked->decrement('fish_points', $fishPoints);
            $locked->increment('stars', $starsGained);

            WalletTransaction::create([
                'user_id'           => $locked->id,
                'type'              => 'convert',
                'fish_points_delta' => -$fishPoints,
                'stars_delta'       => $starsGained,
                'note'              => 'Converted Fish Points to Stars',
            ]);
        });

        return $starsGained;
    }
}
