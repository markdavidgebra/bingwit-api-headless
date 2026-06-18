<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FishCatch;
use App\Support\CatchEconomyPresenter;
use App\Support\OptionalBearerUser;
use Illuminate\Http\Request;

class FeedController extends Controller
{
    // GLOBAL FEED — all catches, newest first
    public function global(Request $request)
    {
        $catches = FishCatch::with([
                                'user' => function ($q) {
                                    $q->select(
                                        'id',
                                        'name',
                                        'profile_picture',
                                        'fishing_style'
                                    );
                                },
                                'media',
                            ])
                            ->withReactionCounts()
                            ->latest()
                            ->paginate(15);

        CatchEconomyPresenter::enrich(
            $catches->getCollection(),
            OptionalBearerUser::id($request)
        );

        return response()->json($catches);
    }

    // PERSONAL FEED — only from people you follow
    public function personal(Request $request)
    {
        $followingIds = $request->user()
                                ->following()
                                ->pluck('users.id');

        if ($followingIds->isEmpty()) {
            return response()->json([
                'message' => 'You are not following anyone yet!',
                'data'    => [],
            ]);
        }

        $catches = FishCatch::with([
                                'user' => function ($q) {
                                    $q->select(
                                        'id',
                                        'name',
                                        'profile_picture',
                                        'fishing_style'
                                    );
                                },
                                'media',
                            ])
                            ->withReactionCounts()
                            ->whereIn('user_id', $followingIds)
                            ->latest()
                            ->paginate(15);

        CatchEconomyPresenter::enrich(
            $catches->getCollection(),
            $request->user()->id
        );

        return response()->json($catches);
    }

    // SINGLE CATCH DETAIL — full info with comments
    public function detail(Request $request, $id)
    {
        $catch = FishCatch::with([
                        'user' => function ($q) {
                            $q->select(
                                'id',
                                'name',
                                'profile_picture',
                                'fishing_style',
                                'location'
                            );
                        },
                        'comments.user' => function ($q) {
                            $q->select(
                                'id',
                                'name',
                                'profile_picture'
                            );
                        },
                        'media',
                    ])
                    ->withReactionCounts()
                    ->findOrFail($id);

        CatchEconomyPresenter::enrich(
            $catch,
            $request->user()?->id
        );

        return response()->json([
            'catch'       => $catch,
            'media_url'   => $catch->media_url,
            'media_urls'  => $catch->media_urls,
            'liked_by_me' => (bool) $catch->liked_by_me,
            'loved_by_me' => (bool) $catch->loved_by_me,
        ]);
    }

    // SEARCH — by fish species, location, or caption
    public function search(Request $request)
    {
        $request->validate([
            'query' => 'required|string|min:1|max:100',
        ]);

        $keyword = $request->query('query');

        $catches = FishCatch::with([
                                'user' => function ($q) {
                                    $q->select(
                                        'id',
                                        'name',
                                        'profile_picture'
                                    );
                                },
                                'media',
                            ])
                            ->withReactionCounts()
                            ->where('fish_species', 'like', "%{$keyword}%")
                            ->orWhere('location',    'like', "%{$keyword}%")
                            ->orWhere('caption',     'like', "%{$keyword}%")
                            ->latest()
                            ->paginate(15);

        CatchEconomyPresenter::enrich(
            $catches->getCollection(),
            OptionalBearerUser::id($request)
        );

        return response()->json($catches);
    }
}
