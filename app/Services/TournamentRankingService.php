<?php

namespace App\Services;

use App\Models\FishCatch;
use App\Models\Tournament;
use App\Models\TournamentDay;
use App\Models\TournamentDayParticipant;
use App\Models\TournamentParticipant;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Support\Collection;

class TournamentRankingService
{
    public function syncDays(Tournament $tournament): Collection
    {
        if (! $tournament->starts_at || ! $tournament->ends_at) {
            return $tournament->days()->orderBy('day_date')->get();
        }

        $start = $tournament->starts_at->copy()->startOfDay();
        $end   = $tournament->ends_at->copy()->startOfDay();

        if ($end->lessThan($start)) {
            return $tournament->days()->orderBy('day_date')->get();
        }

        $period = CarbonPeriod::create($start, '1 day', $end);
        $dayNum = 1;

        foreach ($period as $date) {
            $dayDate = $date->toDateString();

            TournamentDay::firstOrCreate(
                [
                    'tournament_id' => $tournament->id,
                    'day_date'      => $dayDate,
                ],
                [
                    'label' => 'Day ' . $dayNum,
                ]
            );

            $dayNum++;
        }

        return $tournament->days()->orderBy('day_date')->get();
    }

    public function addTournamentParticipant(
        Tournament $tournament,
        int $userId,
        string $status = 'confirmed'
    ): TournamentParticipant {
        $participant = TournamentParticipant::firstOrCreate(
            [
                'tournament_id' => $tournament->id,
                'user_id'       => $userId,
            ],
            [
                'status'          => $status,
                'registered_at'   => now(),
                'payment_status'  => 'free',
                'payment_method'  => 'complimentary',
                'paid_at'         => now(),
            ]
        );

        if ($participant->status === 'withdrawn') {
            $participant->update([
                'status'        => $status,
                'registered_at' => now(),
            ]);
        } elseif ($status === 'confirmed' && $participant->status === 'registered') {
            $participant->update(['status' => 'confirmed']);
        }

        if (! $participant->fresh()->isSettled()) {
            $participant->markFree();
        }

        return $participant->fresh();
    }

    public function syncDayParticipants(TournamentDay $day, array $userIds): Collection
    {
        $tournament = $day->tournament;
        $userIds = array_values(array_unique(array_map('intval', $userIds)));

        $allowed = TournamentParticipant::where('tournament_id', $tournament->id)
            ->whereIn('user_id', $userIds)
            ->active()
            ->get()
            ->keyBy('user_id');

        $syncIds = [];

        foreach ($userIds as $userId) {
            if (! isset($allowed[$userId])) {
                continue;
            }

            $row = TournamentDayParticipant::updateOrCreate(
                [
                    'tournament_day_id' => $day->id,
                    'user_id'           => $userId,
                ],
                [
                    'tournament_participant_id' => $allowed[$userId]->id,
                ]
            );

            $syncIds[] = $row->id;
        }

        TournamentDayParticipant::where('tournament_day_id', $day->id)
            ->when(count($syncIds) > 0, fn ($q) => $q->whereNotIn('id', $syncIds))
            ->delete();

        return $day->dayParticipants()
            ->with('user:id,name,email,profile_picture')
            ->orderBy('id')
            ->get();
    }

    public function resolveDayForAssignment(Tournament $tournament, ?string $preferDate = null): ?TournamentDay
    {
        $this->syncDays($tournament);

        $date = $preferDate ?? now()->toDateString();

        return TournamentDay::where('tournament_id', $tournament->id)
            ->where('day_date', $date)
            ->first()
            ?? TournamentDay::where('tournament_id', $tournament->id)
                ->where('day_date', '>=', $date)
                ->orderBy('day_date')
                ->first()
            ?? TournamentDay::where('tournament_id', $tournament->id)
                ->orderBy('day_date')
                ->first();
    }

    public function assignUserToDay(Tournament $tournament, int $userId, TournamentDay $day): TournamentDayParticipant
    {
        $participant = $this->addTournamentParticipant($tournament, $userId);

        return TournamentDayParticipant::updateOrCreate(
            [
                'tournament_day_id' => $day->id,
                'user_id'           => $userId,
            ],
            [
                'tournament_participant_id' => $participant->id,
            ]
        );
    }

    public function resolveTournamentDayId(User $user, ?int $requestedDayId = null): ?int
    {
        if ($requestedDayId) {
            $day = TournamentDay::with('tournament')->find($requestedDayId);

            if (! $day || ! $this->userCanScoreOnDay($user->id, $day)) {
                return null;
            }

            return $day->id;
        }

        return $this->resolveAssignedDayForUser($user->id)?->id;
    }

    public function resolveAssignedDayForUser(int $userId): ?TournamentDay
    {
        $days = TournamentDay::with('tournament')
            ->whereHas('tournament', fn ($q) => $q->whereIn('status', ['open', 'active']))
            ->whereHas('dayParticipants', fn ($q) => $q->where('user_id', $userId))
            ->orderBy('day_date')
            ->get();

        if ($days->isEmpty()) {
            return null;
        }

        $today = now()->toDateString();
        $todayDay = $days->first(fn (TournamentDay $day) => $day->day_date->toDateString() === $today);

        if ($todayDay) {
            return $todayDay;
        }

        if ($days->count() === 1) {
            return $days->first();
        }

        $upcoming = $days->first(fn (TournamentDay $day) => $day->day_date->toDateString() >= $today);

        return $upcoming ?? $days->last();
    }

    public function userCanScoreOnDay(int $userId, TournamentDay $day): bool
    {
        if (! in_array($day->tournament->status, ['open', 'active'], true)) {
            return false;
        }

        return TournamentDayParticipant::where('tournament_day_id', $day->id)
            ->where('user_id', $userId)
            ->exists();
    }

    public function activeDaysForUser(int $userId): Collection
    {
        return TournamentDay::with(['tournament:id,name,status'])
            ->whereHas('tournament', fn ($q) => $q->whereIn('status', ['open', 'active']))
            ->whereHas('dayParticipants', fn ($q) => $q->where('user_id', $userId))
            ->orderBy('day_date')
            ->get();
    }

    /**
     * Link feed catches from day participants that were never tied to a tournament day.
     */
    public function syncParticipantCatchesForDay(TournamentDay $day): void
    {
        $tournament = $day->tournament;

        if (! $tournament || ! in_array($tournament->status, ['open', 'active', 'completed'], true)) {
            return;
        }

        $since = $tournament->created_at ?? $day->day_date->copy()->startOfDay();

        $participants = TournamentDayParticipant::where('tournament_day_id', $day->id)->get();

        foreach ($participants as $participant) {
            FishCatch::where('user_id', $participant->user_id)
                ->whereNull('tournament_day_id')
                ->where('created_at', '>=', $since)
                ->update(['tournament_day_id' => $day->id]);
        }
    }

    public function dayLeaderboard(TournamentDay $day, string $type = 'biggest'): array
    {
        $day->loadMissing('tournament');
        $this->syncParticipantCatchesForDay($day);

        $participants = TournamentDayParticipant::where('tournament_day_id', $day->id)
            ->with('user:id,name,profile_picture,location,fishing_style')
            ->orderBy('id')
            ->get();

        $participantUserIds = $participants->pluck('user_id');

        if ($type === 'most') {
            $users = User::whereIn('id', $participantUserIds)
                ->withCount(['catches as tournament_catches_count' => function ($q) use ($day) {
                    $q->where('tournament_day_id', $day->id);
                }])
                ->orderByDesc('tournament_catches_count')
                ->orderBy('name')
                ->limit(50)
                ->get(['id', 'name', 'profile_picture', 'location', 'fishing_style'])
                ->map(function ($user, $index) {
                    $user->rank = $index + 1;
                    $user->catches_count = $user->tournament_catches_count;

                    return $user;
                });

            return [
                'type'                => 'most',
                'participants_count'  => $participants->count(),
                'ranking'             => $users,
            ];
        }

        $bestByUser = FishCatch::with(['user' => function ($q) {
            $q->select('id', 'name', 'profile_picture', 'location', 'fishing_style');
        }])
            ->where('tournament_day_id', $day->id)
            ->whereIn('user_id', $participantUserIds)
            ->whereNotNull('weight_kg')
            ->orderByDesc('weight_kg')
            ->get()
            ->groupBy('user_id')
            ->map(fn ($catches) => $catches->first());

        $withCatches = $bestByUser
            ->sortByDesc('weight_kg')
            ->values();

        $withoutCatches = $participants
            ->filter(fn ($participant) => ! $bestByUser->has($participant->user_id))
            ->map(function ($participant) {
                return (object) [
                    'id'           => null,
                    'user_id'      => $participant->user_id,
                    'user'         => $participant->user,
                    'fish_species' => null,
                    'weight_kg'    => null,
                    'media_url'    => null,
                ];
            });

        $ranking = $withCatches
            ->values()
            ->map(function ($entry, $index) {
                $entry->rank = $index + 1;
                $entry->media_url = $entry->getFirstMediaUrl('catch_media');

                return $entry;
            })
            ->concat(
                $withoutCatches->map(function ($entry) {
                    $entry->rank = null;

                    return $entry;
                })
            )
            ->take(50)
            ->values();

        return [
            'type'               => 'biggest',
            'participants_count' => $participants->count(),
            'ranking'            => $ranking,
        ];
    }

    public function enrichCatchTournamentRanks(Collection $catches): void
    {
        if ($catches->isEmpty()) {
            return;
        }

        $userDayMap = [];
        $dayIdSet = $catches->pluck('tournament_day_id')->filter()->unique()->all();

        foreach ($catches as $catch) {
            if ($catch->tournament_day_id || isset($userDayMap[$catch->user_id])) {
                continue;
            }

            $day = $this->resolveAssignedDayForUser((int) $catch->user_id);

            if ($day) {
                $userDayMap[$catch->user_id] = $day->id;
                $dayIdSet[] = $day->id;
            }
        }

        $dayIdSet = array_values(array_unique(array_filter($dayIdSet)));

        if ($dayIdSet === []) {
            foreach ($catches as $catch) {
                $catch->setAttribute('tournament_rank', null);
            }

            return;
        }

        $days = TournamentDay::with('tournament:id,name,status')
            ->whereIn('id', $dayIdSet)
            ->whereHas('tournament', fn ($q) => $q->whereIn('status', ['open', 'active']))
            ->get()
            ->keyBy('id');

        $rankMaps = [];

        foreach ($days as $dayId => $day) {
            $this->syncParticipantCatchesForDay($day);
            $board = $this->dayLeaderboard($day, 'biggest');
            $map = [];

            foreach ($board['ranking'] as $entry) {
                $uid = $entry->user_id ?? $entry->user?->id ?? null;

                if ($uid && $entry->rank !== null) {
                    $map[(int) $uid] = [
                        'rank'            => (int) $entry->rank,
                        'tournament_id'   => $day->tournament_id,
                        'tournament_name' => $day->tournament?->name,
                        'day_date'        => $day->day_date?->format('M j, Y'),
                        'day_label'       => $day->label,
                    ];
                }
            }

            $rankMaps[$dayId] = $map;
        }

        foreach ($catches as $catch) {
            $dayId = $catch->tournament_day_id ?? ($userDayMap[$catch->user_id] ?? null);

            if (! $dayId || ! isset($rankMaps[$dayId])) {
                $catch->setAttribute('tournament_rank', null);
                continue;
            }

            $catch->setAttribute(
                'tournament_rank',
                $rankMaps[$dayId][(int) $catch->user_id] ?? null
            );
        }
    }
}
