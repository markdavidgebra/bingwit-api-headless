<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FishCatch;
use App\Models\User;
use Illuminate\Http\Request;

class LeaderboardController extends Controller
{
    // BIGGEST CATCH THIS WEEK
    public function biggestCatch()
    {
        $catches = FishCatch::with(['user' => function ($q) {
                                $q->select(
                                    'id',
                                    'name',
                                    'profile_picture',
                                    'location',
                                    'fishing_style'
                                );
                            }])
                            ->whereNotNull('weight_kg')
                            ->whereBetween('created_at', [
                                now()->startOfWeek(),
                                now()->endOfWeek(),
                            ])
                            ->orderByDesc('weight_kg')
                            ->limit(10)
                            ->get()
                            ->map(function ($catch, $index) {
                                $catch->rank      = $index + 1;
                                $catch->media_url = $catch->getFirstMediaUrl('catch_media');
                                return $catch;
                            });

        return response()->json([
            'week'    => now()->startOfWeek()->format('M d') .
                         ' - ' .
                         now()->endOfWeek()->format('M d, Y'),
            'ranking' => $catches,
        ]);
    }

    // MOST CATCHES THIS WEEK
    public function mostCatches()
    {
        $users = User::withCount(['catches' => function ($q) {
                            $q->whereBetween('created_at', [
                                now()->startOfWeek(),
                                now()->endOfWeek(),
                            ]);
                        }])
                        ->having('catches_count', '>', 0)
                        ->orderByDesc('catches_count')
                        ->limit(10)
                        ->get([
                            'id',
                            'name',
                            'profile_picture',
                            'location',
                            'fishing_style',
                        ])
                        ->map(function ($user, $index) {
                            $user->rank = $index + 1;
                            return $user;
                        });

        return response()->json([
            'week'    => now()->startOfWeek()->format('M d') .
                         ' - ' .
                         now()->endOfWeek()->format('M d, Y'),
            'ranking' => $users,
        ]);
    }

    // ALL TIME BIGGEST CATCH
    public function allTimeBiggest()
    {
        $catches = FishCatch::with(['user' => function ($q) {
                                $q->select(
                                    'id',
                                    'name',
                                    'profile_picture',
                                    'location'
                                );
                            }])
                            ->whereNotNull('weight_kg')
                            ->orderByDesc('weight_kg')
                            ->limit(10)
                            ->get()
                            ->map(function ($catch, $index) {
                                $catch->rank      = $index + 1;
                                $catch->media_url = $catch->getFirstMediaUrl('catch_media');
                                return $catch;
                            });

        return response()->json([
            'ranking' => $catches,
        ]);
    }

    // ALL TIME MOST CATCHES
    public function allTimeMost()
    {
        $users = User::withCount('catches')
                        ->having('catches_count', '>', 0)
                        ->orderByDesc('catches_count')
                        ->limit(10)
                        ->get([
                            'id',
                            'name',
                            'profile_picture',
                            'location',
                            'fishing_style',
                        ])
                        ->map(function ($user, $index) {
                            $user->rank = $index + 1;
                            return $user;
                        });

        return response()->json([
            'ranking' => $users,
        ]);
    }
}