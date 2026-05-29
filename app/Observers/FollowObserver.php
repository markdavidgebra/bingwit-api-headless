<?php

namespace App\Observers;

use App\Models\Follow;
use App\Models\Notification;

class FollowObserver
{
    // Runs every time someone follows another user
    public function created(Follow $follow): void
    {
        $follower = \App\Models\User::find($follow->follower_id);

        Notification::create([
            'user_id'        => $follow->following_id,
            'type'           => 'follow',
            'title'          => $follower->name . ' started following you!',
            'body'           => $follower->name . ' is now following you.',
            'reference_id'   => $follow->follower_id,
            'reference_type' => 'user',
        ]);
    }
}