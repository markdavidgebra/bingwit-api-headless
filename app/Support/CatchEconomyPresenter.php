<?php

namespace App\Support;

use App\Models\CatchLessonConfirmation;
use App\Models\FishCatch;
use App\Models\Like;
use Illuminate\Support\Collection;

class CatchEconomyPresenter
{
    public static function enrich(Collection|FishCatch $catches, ?int $viewerId = null): void
    {
        $items = $catches instanceof FishCatch
            ? collect([$catches])
            : $catches;

        if ($items->isEmpty()) {
            return;
        }

        $ids = $items->pluck('id')->all();

        $confirmedByMe = [];
        $likedByMe = [];
        $lovedByMe = [];

        if ($viewerId) {
            $confirmedByMe = CatchLessonConfirmation::where('user_id', $viewerId)
                ->whereIn('catch_id', $ids)
                ->pluck('catch_id')
                ->flip()
                ->all();

            $reactions = Like::where('user_id', $viewerId)
                ->whereIn('catch_id', $ids)
                ->get(['catch_id', 'type']);

            foreach ($reactions as $reaction) {
                if ($reaction->type === Like::TYPE_LIKE) {
                    $likedByMe[$reaction->catch_id] = true;
                }
                if ($reaction->type === Like::TYPE_LOVE) {
                    $lovedByMe[$reaction->catch_id] = true;
                }
            }

            // Prefer love if legacy rows still have both.
            foreach (array_keys($lovedByMe) as $catchId) {
                unset($likedByMe[$catchId]);
            }
        }

        foreach ($items as $catch) {
            $catch->setAttribute('has_fishing_lesson', ! empty($catch->fishing_lesson));
            $catch->setAttribute(
                'confirmed_by_me',
                isset($confirmedByMe[$catch->id])
            );
            $catch->setAttribute('liked_by_me', isset($likedByMe[$catch->id]));
            $catch->setAttribute('loved_by_me', isset($lovedByMe[$catch->id]));
        }
    }

    public static function enrichAll(Collection|FishCatch $catches, ?int $viewerId = null): void
    {
        self::enrich($catches, $viewerId);

        $items = $catches instanceof FishCatch
            ? collect([$catches])
            : $catches;

        app(\App\Services\TournamentRankingService::class)
            ->enrichCatchTournamentRanks($items);
    }
}
