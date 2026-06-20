<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FishCatch;
use App\Models\TournamentDay;
use App\Models\User;
use App\Services\TournamentRankingService;
use Illuminate\Http\Request;

class LeaderboardController extends Controller
{
    public function __construct(private TournamentRankingService $tournamentRanking)
    {
    }

    // GET /api/leaderboard/tournament-days/{dayId}
    public function tournamentDay(Request $request, $dayId)
    {
        $day = TournamentDay::with('tournament:id,name,status')->findOrFail($dayId);
        $type = $request->query('type', 'biggest');

        if (! in_array($type, ['biggest', 'most'], true)) {
            $type = 'biggest';
        }

        $board = $this->tournamentRanking->dayLeaderboard($day, $type);

        return response()->json(array_merge([
            'day'        => $day,
            'tournament' => $day->tournament?->only(['id', 'name', 'status']),
            'label'      => $day->day_date->format('M j, Y'),
        ], $board));
    }

    // GET /api/leaderboard/tournament-active
    public function activeTournamentBoard(Request $request)
    {
        $type = $request->query('type', 'biggest');
        $tournamentId = $request->query('tournament_id');
        $dayId = $request->query('day_id');

        if ($dayId) {
            return $this->tournamentDay($request, $dayId);
        }

        $query = TournamentDay::with('tournament:id,name,status')
            ->withCount('dayParticipants')
            ->whereHas('tournament', fn ($q) => $q->whereIn('status', ['open', 'active']));

        if ($tournamentId) {
            $query->where('tournament_id', $tournamentId);
        }

        $day = (clone $query)->where('day_date', now()->toDateString())->first()
            ?? $query->orderByDesc('day_date')->first();

        if (! $day) {
            return response()->json([
                'day'     => null,
                'type'    => $type,
                'ranking' => [],
                'label'   => null,
            ]);
        }

        return $this->tournamentDay($request, $day->id);
    }

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