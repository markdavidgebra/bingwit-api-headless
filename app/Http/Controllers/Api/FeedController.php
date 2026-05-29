<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FishCatch;
use Illuminate\Http\Request;

class FeedController extends Controller
{
    // GLOBAL FEED — all catches, newest first
    public function global(Request $request)
    {
        $catches = FishCatch::with(['user' => function ($q) {
                                $q->select(
                                    'id',
                                    'name',
                                    'profile_picture',
                                    'fishing_style'
                                );
                            }])
                            ->withCount(['likes', 'comments'])
                            ->latest()
                            ->paginate(15);

        // Add media URL to each catch
        $catches->getCollection()->transform(function ($catch) {
            $catch->media_url = $catch->getFirstMediaUrl('catch_media');
            return $catch;
        });

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

        $catches = FishCatch::with(['user' => function ($q) {
                                $q->select(
                                    'id',
                                    'name',
                                    'profile_picture',
                                    'fishing_style'
                                );
                            }])
                            ->withCount(['likes', 'comments'])
                            ->whereIn('user_id', $followingIds)
                            ->latest()
                            ->paginate(15);

        // Add media URL to each catch
        $catches->getCollection()->transform(function ($catch) {
            $catch->media_url = $catch->getFirstMediaUrl('catch_media');
            return $catch;
        });

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
                        }
                    ])
                    ->withCount(['likes', 'comments'])
                    ->findOrFail($id);

        // Check if current user liked this catch
        $likedByMe = false;
        if ($request->user()) {
            $likedByMe = $catch->likes()
                               ->where('user_id', $request->user()->id)
                               ->exists();
        }

        return response()->json([
            'catch'       => $catch,
            'media_url'   => $catch->getFirstMediaUrl('catch_media'),
            'liked_by_me' => $likedByMe,
        ]);
    }

    // SEARCH — by fish species, location, or caption
    public function search(Request $request)
    {
        $request->validate([
            'query' => 'required|string|min:1|max:100',
        ]);

        $keyword = $request->query('query');

        $catches = FishCatch::with(['user' => function ($q) {
                                $q->select(
                                    'id',
                                    'name',
                                    'profile_picture'
                                );
                            }])
                            ->withCount(['likes', 'comments'])
                            ->where('fish_species', 'like', "%{$keyword}%")
                            ->orWhere('location',    'like', "%{$keyword}%")
                            ->orWhere('caption',     'like', "%{$keyword}%")
                            ->latest()
                            ->paginate(15);

        // Add media URL to each catch
        $catches->getCollection()->transform(function ($catch) {
            $catch->media_url = $catch->getFirstMediaUrl('catch_media');
            return $catch;
        });

        return response()->json($catches);
    }
}