<?php

namespace App\Support;

use App\Models\UserBlock;
use Illuminate\Support\Collection;

class UserBlockGuard
{
    /** User IDs this viewer has blocked or who blocked this viewer. */
    public static function excludedUserIds(?int $viewerId): Collection
    {
        if (! $viewerId) {
            return collect();
        }

        $blockedByMe = UserBlock::where('blocker_id', $viewerId)->pluck('blocked_id');
        $blockedMe = UserBlock::where('blocked_id', $viewerId)->pluck('blocker_id');

        return $blockedByMe->merge($blockedMe)->unique()->values();
    }

    public static function isBlockedEitherWay(int $a, int $b): bool
    {
        return UserBlock::where(function ($q) use ($a, $b) {
            $q->where('blocker_id', $a)->where('blocked_id', $b);
        })->orWhere(function ($q) use ($a, $b) {
            $q->where('blocker_id', $b)->where('blocked_id', $a);
        })->exists();
    }
}
