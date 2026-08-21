<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DirectMessage;
use App\Models\Notification;
use App\Models\User;
use App\Support\UserBlockGuard;
use Illuminate\Http\Request;

class ChatController extends Controller
{
    public function inbox(Request $request)
    {
        $userId = $request->user()->id;
        $excluded = UserBlockGuard::excludedUserIds($userId);

        $partnerIds = DirectMessage::query()
            ->where('sender_id', $userId)
            ->orWhere('recipient_id', $userId)
            ->get(['sender_id', 'recipient_id'])
            ->flatMap(fn ($m) => [$m->sender_id, $m->recipient_id])
            ->unique()
            ->reject(fn ($id) => (int) $id === (int) $userId || $excluded->contains((int) $id))
            ->values();

        $threads = $partnerIds->map(function ($partnerId) use ($userId) {
            $partner = User::select('id', 'name', 'profile_picture', 'location')->find($partnerId);
            if (! $partner) {
                return null;
            }

            $last = DirectMessage::where(function ($q) use ($userId, $partnerId) {
                $q->where('sender_id', $userId)->where('recipient_id', $partnerId);
            })->orWhere(function ($q) use ($userId, $partnerId) {
                $q->where('sender_id', $partnerId)->where('recipient_id', $userId);
            })->latest()->first();

            $unread = DirectMessage::where('sender_id', $partnerId)
                ->where('recipient_id', $userId)
                ->whereNull('read_at')
                ->count();

            return [
                'user' => $partner,
                'last_message' => $last,
                'unread_count' => $unread,
            ];
        })->filter()->sortByDesc(fn ($t) => $t['last_message']?->created_at)->values();

        return response()->json(['threads' => $threads]);
    }

    public function thread(Request $request, $userId)
    {
        $me = $request->user()->id;
        $other = (int) $userId;

        if ($me === $other) {
            return response()->json(['message' => 'Invalid conversation.'], 422);
        }

        User::findOrFail($other);

        if (UserBlockGuard::isBlockedEitherWay($me, $other)) {
            return response()->json([
                'message' => 'Messaging is unavailable with this user.',
                'blocked' => true,
            ], 403);
        }

        DirectMessage::where('sender_id', $other)
            ->where('recipient_id', $me)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        $messages = DirectMessage::with([
            'sender:id,name,profile_picture',
            'recipient:id,name,profile_picture',
        ])
            ->where(function ($q) use ($me, $other) {
                $q->where('sender_id', $me)->where('recipient_id', $other);
            })
            ->orWhere(function ($q) use ($me, $other) {
                $q->where('sender_id', $other)->where('recipient_id', $me);
            })
            ->orderBy('created_at')
            ->limit(200)
            ->get();

        return response()->json([
            'user' => User::select('id', 'name', 'profile_picture', 'location')->find($other),
            'messages' => $messages,
        ]);
    }

    public function send(Request $request, $userId)
    {
        $request->validate([
            'body' => 'required|string|max:2000',
        ]);

        $me = $request->user();
        $otherId = (int) $userId;

        if ($me->id === $otherId) {
            return response()->json(['message' => 'You cannot message yourself.'], 422);
        }

        $other = User::findOrFail($otherId);

        if (UserBlockGuard::isBlockedEitherWay($me->id, $otherId)) {
            return response()->json([
                'message' => 'You cannot message this user.',
                'blocked' => true,
            ], 403);
        }

        $message = DirectMessage::create([
            'sender_id' => $me->id,
            'recipient_id' => $other->id,
            'body' => trim($request->body),
        ]);

        Notification::create([
            'user_id' => $other->id,
            'type' => 'direct_message',
            'title' => $me->name . ' sent you a message',
            'body' => mb_strimwidth($message->body, 0, 120, '…'),
            'reference_id' => $me->id,
            'reference_type' => 'user',
        ]);

        return response()->json([
            'message' => 'Message sent.',
            'data' => $message->load('sender:id,name,profile_picture'),
        ], 201);
    }
}
