<?php

namespace App\Support;

use App\Models\CatchLessonConfirmation;
use App\Models\FishCatch;
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
        if ($viewerId) {
            $confirmedByMe = CatchLessonConfirmation::where('user_id', $viewerId)
                ->whereIn('catch_id', $ids)
                ->pluck('catch_id')
                ->flip()
                ->all();
        }

        foreach ($items as $catch) {
            $catch->setAttribute('has_fishing_lesson', ! empty($catch->fishing_lesson));
            $catch->setAttribute(
                'confirmed_by_me',
                isset($confirmedByMe[$catch->id])
            );
        }
    }
}
