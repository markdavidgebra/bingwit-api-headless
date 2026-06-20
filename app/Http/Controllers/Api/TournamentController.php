<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tournament;
use App\Models\TournamentParticipant;
use App\Models\TournamentPost;
use App\Services\TournamentRankingService;
use Illuminate\Http\Request;

class TournamentController extends Controller
{
    public function __construct(private TournamentRankingService $ranking)
    {
    }

    /**
     * GET /api/tournaments  (public)
     * List tournaments. Supports optional ?status filter.
     */
    public function index(Request $request)
    {
        $query = Tournament::with('media')
                            ->withCount(['participants', 'posts']);

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        $tournaments = $query->latest('starts_at')
                             ->latest('id')
                             ->paginate(15);

        // Annotate whether the current authenticated user (if any) is registered.
        $userId = optional($request->user())->id;
        $tournaments->getCollection()->transform(function ($t) use ($userId) {
            $t->is_registered = $userId ? $t->isParticipant($userId) : false;
            return $t;
        });

        return response()->json($tournaments);
    }

    /**
     * GET /api/tournaments/{id}  (public)
     */
    public function show(Request $request, $id)
    {
        $tournament = Tournament::with(['media'])
                                ->withCount(['participants', 'posts'])
                                ->findOrFail($id);

        $userId = optional($request->user())->id;
        $tournament->is_registered = $userId ? $tournament->isParticipant($userId) : false;

        return response()->json([
            'tournament' => $tournament,
        ]);
    }

    /**
     * GET /api/tournaments/{id}/posts  (public)
     * Tournament feed. NOT included in global feed unless the post itself
     * is flagged cross_post_to_feed = true.
     */
    public function posts($id)
    {
        $tournament = Tournament::findOrFail($id);

        $posts = TournamentPost::with(['admin:id,name,profile_picture'])
                               ->where('tournament_id', $tournament->id)
                               ->latest()
                               ->paginate(20);

        return response()->json($posts);
    }

    /**
     * POST /api/tournaments/{id}/register  (auth)
     */
    public function register(Request $request, $id)
    {
        $tournament = Tournament::findOrFail($id);
        $userId = $request->user()->id;

        if (in_array($tournament->status, ['completed', 'cancelled'], true)) {
            return response()->json([
                'message' => 'Registration is closed for this tournament.',
            ], 422);
        }

        if ($tournament->registration_deadline
            && now()->greaterThan($tournament->registration_deadline)
        ) {
            return response()->json([
                'message' => 'The registration deadline has passed.',
            ], 422);
        }

        if ($tournament->max_participants) {
            $current = $tournament->participants()
                                  ->whereIn('status', ['registered', 'confirmed'])
                                  ->count();
            if ($current >= $tournament->max_participants) {
                return response()->json([
                    'message' => 'This tournament is full.',
                ], 422);
            }
        }

        $existing = TournamentParticipant::where('tournament_id', $tournament->id)
                                          ->where('user_id', $userId)
                                          ->first();

        if ($existing) {
            if ($existing->status === 'withdrawn') {
                $existing->update([
                    'status'        => 'registered',
                    'registered_at' => now(),
                ]);
            }
            return response()->json([
                'message'     => 'You are registered for this tournament!',
                'participant' => $existing->fresh(),
            ]);
        }

        $participant = TournamentParticipant::create([
            'tournament_id' => $tournament->id,
            'user_id'       => $userId,
            'status'        => 'registered',
            'registered_at' => now(),
        ]);

        return response()->json([
            'message'     => 'You are registered for this tournament!',
            'participant' => $participant,
        ], 201);
    }

    /**
     * DELETE /api/tournaments/{id}/register  (auth) — withdraw
     */
    public function unregister(Request $request, $id)
    {
        $userId = $request->user()->id;

        $participant = TournamentParticipant::where('tournament_id', $id)
                                              ->where('user_id', $userId)
                                              ->first();

        if (! $participant) {
            return response()->json([
                'message' => 'You are not registered for this tournament.',
            ], 404);
        }

        $participant->update(['status' => 'withdrawn']);

        return response()->json([
            'message' => 'You have withdrawn from the tournament.',
        ]);
    }

    /**
     * GET /api/feed/announcements  (public)
     * Cross-posted tournament posts that leak into the global feed.
     * Returned separately from the catch feed so the PWA can render
     * them as an announcement banner above the main feed.
     */
    public function announcements()
    {
        $posts = TournamentPost::announcements()
                                ->with([
                                    'tournament:id,name,slug,cover_image,status',
                                    'admin:id,name,profile_picture',
                                ])
                                ->latest()
                                ->limit(10)
                                ->get()
                                ->map(fn (TournamentPost $post) => [
                                    'id'                 => $post->id,
                                    'tournament_id'      => $post->tournament_id,
                                    'title'              => $post->title,
                                    'body'               => $post->body,
                                    'cross_post_to_feed' => $post->cross_post_to_feed,
                                    'created_at'         => $post->created_at,
                                    'updated_at'         => $post->updated_at,
                                    'tournament'         => $post->tournament,
                                    'admin'              => $post->admin,
                                ]);

        return response()->json([
            'data' => $posts,
        ]);
    }

    /**
     * GET /api/tournaments/{id}/days
     */
    public function days($id)
    {
        $tournament = Tournament::findOrFail($id);
        $days = $this->ranking->syncDays($tournament)
            ->loadCount('dayParticipants');

        return response()->json(['data' => $days]);
    }

    /**
     * GET /api/tournaments/{id}/days/{dayId}/leaderboard
     */
    public function dayLeaderboard(Request $request, $id, $dayId)
    {
        $tournament = Tournament::findOrFail($id);
        $day = $tournament->days()->findOrFail($dayId);
        $type = $request->query('type', 'biggest');

        if (! in_array($type, ['biggest', 'most'], true)) {
            $type = 'biggest';
        }

        $board = $this->ranking->dayLeaderboard($day, $type);

        return response()->json(array_merge([
            'day'        => $day,
            'tournament' => $tournament->only(['id', 'name', 'status']),
            'label'      => $day->day_date->format('M j, Y'),
        ], $board));
    }

    /**
     * GET /api/tournaments/my-active-days  (auth)
     */
    public function myActiveDays(Request $request)
    {
        $days = $this->ranking->activeDaysForUser($request->user()->id);

        return response()->json(['data' => $days]);
    }
}
