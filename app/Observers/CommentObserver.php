<?php

namespace App\Observers;

use App\Models\Comment;
use App\Models\Notification;
use App\Models\FishCatch;

class CommentObserver
{
    // Runs every time a Comment is created
    public function created(Comment $comment): void
    {
        $catch = FishCatch::find($comment->catch_id);

        // Don't notify yourself
        if (!$catch || $catch->user_id === $comment->user_id) {
            return;
        }

        $commenter = \App\Models\User::find($comment->user_id);

        Notification::create([
            'user_id'        => $catch->user_id,
            'type'           => 'comment',
            'title'          => $commenter->name . ' commented on your catch!',
            'body'           => $commenter->name . ' said: "' . \Str::limit($comment->body, 50) . '"',
            'reference_id'   => $catch->id,
            'reference_type' => 'catch',
        ]);
    }
}