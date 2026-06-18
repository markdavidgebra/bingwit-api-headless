<?php

namespace App\Observers;

use App\Models\Like;
use App\Models\Notification;
use App\Models\FishCatch;

class LikeObserver
{
    public function created(Like $like): void
    {
        $catch = FishCatch::find($like->catch_id);

        if (!$catch || $catch->user_id === $like->user_id) {
            return;
        }

        $liker = $like->user ?? \App\Models\User::find($like->user_id);
        $isLove = $like->type === Like::TYPE_LOVE;

        Notification::create([
            'user_id'        => $catch->user_id,
            'type'           => $isLove ? 'love' : 'like',
            'title'          => $liker->name . ($isLove ? ' loved your catch!' : ' liked your catch!'),
            'body'           => $liker->name . ($isLove ? ' loved your ' : ' liked your ') . $catch->fish_species . ' catch.',
            'reference_id'   => $catch->id,
            'reference_type' => 'catch',
        ]);
    }

    public function deleted(Like $like): void
    {
        $notificationType = $like->type === Like::TYPE_LOVE ? 'love' : 'like';

        Notification::where('type', $notificationType)
                    ->where('reference_id', $like->catch_id)
                    ->where('user_id', function ($query) use ($like) {
                        $query->select('user_id')
                              ->from('catches')
                              ->where('id', $like->catch_id);
                    })
                    ->delete();
    }
}
