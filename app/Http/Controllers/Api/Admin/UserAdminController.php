<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Tournament;
use App\Models\TournamentDay;
use App\Models\TournamentDayParticipant;
use App\Models\TournamentParticipant;
use App\Models\User;
use App\Services\TournamentRankingService;
use Illuminate\Http\Request;

class UserAdminController extends Controller
{
    public function __construct(private TournamentRankingService $ranking)
    {
    }

    // GET /api/admin/users
    public function index(Request $request)
    {
        $query = User::query()
            ->select('id', 'name', 'email', 'location', 'fishing_style', 'profile_picture', 'created_at')
            ->orderBy('name');

        if ($request->filled('search')) {
            $term = '%' . $request->search . '%';
            $query->where(function ($q) use ($term) {
                $q->where('name', 'like', $term)
                  ->orWhere('email', 'like', $term);
            });
        }

        return response()->json($query->paginate(25));
    }

    // GET /api/admin/users/{id}
    public function show($id)
    {
        $user = User::select(
            'id',
            'name',
            'email',
            'location',
            'fishing_style',
            'profile_picture',
            'bio',
            'created_at'
        )->findOrFail($id);

        return response()->json(['user' => $user]);
    }

    // GET /api/admin/users/{id}/tournaments
    public function tournaments($id)
    {
        User::findOrFail($id);

        $participations = TournamentParticipant::with([
            'tournament:id,name,status,starts_at,ends_at,location',
        ])
            ->where('user_id', $id)
            ->whereIn('status', ['registered', 'confirmed'])
            ->latest('registered_at')
            ->get();

        return response()->json(['data' => $participations]);
    }

    // POST /api/admin/users/{id}/tournaments
    public function joinTournament(Request $request, $id)
    {
        $user = User::findOrFail($id);

        $data = $request->validate([
            'tournament_id'   => 'required|integer|exists:tournaments,id',
            'status'          => 'nullable|in:registered,confirmed',
            'assign_today'    => 'nullable|boolean',
            'tournament_day_id' => 'nullable|integer|exists:tournament_days,id',
        ]);

        $tournament = Tournament::findOrFail($data['tournament_id']);

        if (in_array($tournament->status, ['completed', 'cancelled'], true)) {
            return response()->json([
                'message' => 'This tournament is no longer accepting participants.',
            ], 422);
        }

        $alreadyJoined = TournamentParticipant::where('tournament_id', $tournament->id)
            ->where('user_id', $user->id)
            ->whereIn('status', ['registered', 'confirmed'])
            ->exists();

        $participant = $this->ranking->addTournamentParticipant(
            $tournament,
            (int) $user->id,
            $data['status'] ?? 'confirmed'
        );

        $dayAssignment = null;

        if (! empty($data['tournament_day_id'])) {
            $day = TournamentDay::where('tournament_id', $tournament->id)
                ->findOrFail($data['tournament_day_id']);

            $this->ranking->assignUserToDay($tournament, (int) $user->id, $day);
            $dayAssignment = $day;
        } elseif ($request->boolean('assign_today', true)) {
            $day = $this->ranking->resolveDayForAssignment($tournament);

            if ($day) {
                $this->ranking->assignUserToDay($tournament, (int) $user->id, $day);
                $dayAssignment = $day;
            }
        }

        if ($dayAssignment) {
            $message = $alreadyJoined
                ? 'Angler is already in this tournament. Day assignment updated.'
                : 'Angler joined the tournament and assigned to ' . ($dayAssignment->label ?? $dayAssignment->day_date->format('M j, Y')) . '.';
        } elseif ($alreadyJoined) {
            $message = 'Angler is already in this tournament.';
        } else {
            $message = 'Angler joined the tournament.';
        }

        return response()->json([
            'message'        => $message,
            'participant'    => $participant->load('tournament:id,name,status'),
            'assigned_day'   => $dayAssignment,
        ], 201);
    }

    // DELETE /api/admin/users/{id}/tournaments/{tournamentId}
    public function leaveTournament($id, $tournamentId)
    {
        User::findOrFail($id);

        $participant = TournamentParticipant::where('user_id', $id)
            ->where('tournament_id', $tournamentId)
            ->firstOrFail();

        $participant->update(['status' => 'withdrawn']);

        TournamentDayParticipant::where('user_id', $id)
            ->whereHas('tournamentDay', fn ($q) => $q->where('tournament_id', $tournamentId))
            ->delete();

        return response()->json(['message' => 'Angler removed from tournament.']);
    }
}
