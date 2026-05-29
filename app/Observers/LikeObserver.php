<?php

namespace App\Observers;

use App\Models\Like;
use App\Models\Notification;
use App\Models\FishCatch;

class LikeObserver
{
    // Runs every time a Like is created
    public function created(Like $like): void
    {
        $catch = FishCatch::find($like->catch_id);

        // Don't notify yourself
        if (!$catch || $catch->user_id === $like->user_id) {
            return;
        }

        // Get the name of the person who liked
        $liker = $like->user ?? \App\Models\User::find($like->user_id);

        Notification::create([
            'user_id'        => $catch->user_id,
            'type'           => 'like',
            'title'          => $liker->name . ' liked your catch!',
            'body'           => $liker->name . ' liked your ' . $catch->fish_species . ' catch.',
            'reference_id'   => $catch->id,
            'reference_type' => 'catch',
        ]);
    }

    // Runs every time a Like is deleted (unlike)
    public function deleted(Like $like): void
    {
        // Remove the like notification when unliked
        Notification::where('type', 'like')
                    ->where('reference_id', $like->catch_id)
                    ->where('user_id', function ($query) use ($like) {
                        $query->select('user_id')
                              ->from('catches')
                              ->where('id', $like->catch_id);
                    })
                    ->delete();
    }
}