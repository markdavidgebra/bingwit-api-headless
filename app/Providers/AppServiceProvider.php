<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Models\Like;
use App\Models\Comment;
use App\Models\Follow;
use App\Observers\LikeObserver;
use App\Observers\CommentObserver;
use App\Observers\FollowObserver;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Register observers
        Like::observe(LikeObserver::class);
        Comment::observe(CommentObserver::class);
        Follow::observe(FollowObserver::class);
    }
}