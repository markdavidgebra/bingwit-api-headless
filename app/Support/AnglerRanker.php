<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class AnglerRanker
{
    /**
     * Score = followers*3 + catches*2 + location match bonus.
     * Instagram-style: more social proof + activity ranks higher;
     * shared location / searched place boosts relevance.
     */
    public static function applyRanking(Builder $query, ?string $locationHint = null): Builder
    {
        return $query
            ->withCount(['followers', 'catches'])
            ->when($locationHint, function (Builder $q) use ($locationHint) {
                $term = '%' . $locationHint . '%';
                $q->orderByRaw(
                    'CASE WHEN location LIKE ? THEN 1 ELSE 0 END DESC',
                    [$term]
                );
            })
            ->orderByDesc('followers_count')
            ->orderByDesc('catches_count')
            ->orderBy('name');
    }

    public static function format(User $user, ?int $viewerId = null): array
    {
        $isFollowing = false;
        if ($viewerId) {
            $isFollowing = $user->followers()
                ->where('users.id', $viewerId)
                ->exists();
        }

        return [
            'id' => $user->id,
            'name' => $user->name,
            'profile_picture' => $user->profile_picture,
            'location' => $user->location,
            'fishing_style' => $user->fishing_style,
            'bio' => $user->bio,
            'followers_count' => (int) ($user->followers_count ?? $user->followers()->count()),
            'catches_count' => (int) ($user->catches_count ?? $user->catches()->count()),
            'is_following' => $isFollowing,
            'rank_score' => ((int) ($user->followers_count ?? 0) * 3)
                + ((int) ($user->catches_count ?? 0) * 2),
        ];
    }

    public static function formatMany(Collection $users, ?int $viewerId = null): array
    {
        return $users->map(fn (User $u) => self::format($u, $viewerId))->values()->all();
    }
}
