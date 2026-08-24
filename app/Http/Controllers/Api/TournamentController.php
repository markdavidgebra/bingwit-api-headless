<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tournament;
use App\Models\TournamentParticipant;
use App\Models\TournamentPost;
use App\Services\TournamentRankingService;
use App\Services\TournamentRegistrationService;
use App\Support\OptionalBearerUser;
use Illuminate\Http\Request;
use RuntimeException;

class TournamentController extends Controller
{
    public function __construct(
        private TournamentRankingService $ranking,
        private TournamentRegistrationService $registration,
    ) {
    }

    /**
     * GET /api/tournaments  (public)
     * List tournaments. Supports optional ?status filter.
     */
    public function index(Request $request)
    {
        $query = Tournament::with('media')
                            ->withCount([
                                'participants as participants_count' => fn ($q) => $q->active(),
                                'posts',
                            ]);

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        $tournaments = $query->latest('starts_at')
                             ->latest('id')
                             ->paginate(15);

        // Annotate whether the current authenticated user (if any) is registered.
        $userId = OptionalBearerUser::id($request);
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
                                ->withCount([
                                    'participants as participants_count' => fn ($q) => $q->active(),
                                    'posts',
                                ])
                                ->findOrFail($id);

        $userId = OptionalBearerUser::id($request);
        $mine = $userId
            ? $tournament->participants()->where('user_id', $userId)->first()
            : null;
        $tournament->is_registered = $mine?->isSettled() ?? false;
        $tournament->payment_status = $mine?->payment_status;

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

        try {
            $payload = $this->registration->register($request, $tournament);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $status = ! empty($payload['already_registered']) ? 200 : 201;

        return response()->json($payload, $status);
    }

    /**
     * POST /api/tournaments/{id}/register/sync  (auth)
     */
    public function syncRegister(Request $request, $id)
    {
        $tournament = Tournament::findOrFail($id);

        try {
            return response()->json(
                $this->registration->sync($request->user(), $tournament)
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * GET /api/tournaments/checkout/return  (public)
     */
    public function checkoutReturn(Request $request)
    {
        $ref = (string) $request->query('ref', '');
        $status = (string) $request->query('status', 'success');
        $participant = $ref !== ''
            ? TournamentParticipant::where('reference_number', $ref)->first()
            : null;

        if ($participant && $status === 'success') {
            $this->registration->refreshFromPayMongo($participant);
            $participant->refresh();
        }

        if ($participant && $status === 'cancel' && $participant->payment_status === 'unpaid') {
            $this->registration->markCancelled($participant);
            $participant->refresh();
        }

        $scheme = $status === 'cancel'
            ? 'bingwitapp://tournament/cancel'
            : 'bingwitapp://tournament/success';
        $deep = $scheme.($ref !== '' ? '?ref='.urlencode($ref) : '');
        $paid = $participant?->isSettled();
        $title = $paid ? "You're in" : ($status === 'cancel' ? 'Checkout cancelled' : 'Return to Bingwit');
        $body = $paid
            ? 'You can close this page and go back to Bingwit.'
            : ($status === 'cancel'
                ? 'No charge was made.'
                : 'If you already paid, your registration will update in the app.');

        return response(
            '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            .'<title>'.e($title).'</title>'
            .'<style>body{font-family:system-ui,sans-serif;background:#F5F1E8;color:#013055;display:flex;min-height:100vh;align-items:center;justify-content:center;margin:0}'
            .'.card{background:#fff;border-radius:20px;padding:28px 24px;max-width:360px;text-align:center;box-shadow:0 10px 30px rgba(1,48,85,.08)}'
            .'h1{font-size:22px;margin:0 0 8px}p{color:#64748B;line-height:1.5}a{display:inline-block;margin-top:16px;background:#013055;color:#fff;text-decoration:none;padding:12px 18px;border-radius:999px;font-weight:600}</style>'
            .'<meta http-equiv="refresh" content="0;url='.e($deep).'"></head><body><div class="card">'
            .'<h1>'.e($title).'</h1><p>'.e($body).'</p>'
            .'<a href="'.e($deep).'">Back to Bingwit</a></div>'
            .'<script>location.replace('.json_encode($deep).');</script></body></html>',
            200,
            ['Content-Type' => 'text/html; charset=UTF-8']
        );
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
     * Cross-posted tournament posts shown as Join Challenge cards in the app feed.
     */
    public function announcements(Request $request)
    {
        $userId = OptionalBearerUser::id($request);

        $posts = TournamentPost::announcements()
                                ->whereHas('tournament', function ($q) {
                                    $q->whereIn('status', ['upcoming', 'open', 'active'])
                                      ->where(function ($inner) {
                                          $inner->whereNull('ends_at')
                                                ->orWhere('ends_at', '>=', now());
                                      });
                                })
                                ->with(['tournament.media'])
                                ->latest()
                                ->limit(40)
                                ->get()
                                ->unique('tournament_id')
                                ->take(5)
                                ->values()
                                ->map(function (TournamentPost $post) use ($userId) {
                                    $tournament = $post->tournament;

                                    return [
                                        'id'            => $post->id,
                                        'tournament_id' => $post->tournament_id,
                                        'title'         => $post->title ?: $tournament?->name,
                                        'body'          => $post->body,
                                        'created_at'    => $post->created_at,
                                        'tournament'    => $tournament ? [
                                            'id'            => $tournament->id,
                                            'name'          => $tournament->name,
                                            'slug'          => $tournament->slug,
                                            'location'      => $tournament->location,
                                            'cover_url'     => $tournament->cover_url,
                                            'status'        => $tournament->status,
                                            'starts_at'     => $tournament->starts_at,
                                            'ends_at'       => $tournament->ends_at,
                                            'prize_pool'    => $tournament->prize_pool,
                                            'entry_fee'     => $tournament->entry_fee,
                                            'max_participants' => $tournament->max_participants,
                                            'participants_count' => $tournament->participants()
                                                ->active()
                                                ->count(),
                                            'is_registered' => $userId
                                                ? $tournament->isParticipant($userId)
                                                : false,
                                        ] : null,
                                    ];
                                });

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
