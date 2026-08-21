<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ContentReport;
use App\Models\DirectMessage;
use App\Models\FishCatch;
use App\Models\Follow;
use App\Models\User;
use App\Models\UserBlock;
use App\Support\UserBlockGuard;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ModerationController extends Controller
{
    public const REASONS = [
        'spam',
        'harassment',
        'hate',
        'sexual_content',
        'violence',
        'scam',
        'impersonation',
        'illegal',
        'other',
    ];

    public function report(Request $request)
    {
        $data = $request->validate([
            'reportable_type' => ['required', Rule::in([
                ContentReport::TYPE_USER,
                ContentReport::TYPE_CATCH,
                ContentReport::TYPE_MESSAGE,
            ])],
            'reportable_id' => 'required|integer|min:1',
            'reason' => ['required', Rule::in(self::REASONS)],
            'details' => 'nullable|string|max:1000',
        ]);

        $reporter = $request->user();
        $reportedUserId = null;

        if ($data['reportable_type'] === ContentReport::TYPE_USER) {
            $target = User::findOrFail($data['reportable_id']);
            if ((int) $target->id === (int) $reporter->id) {
                return response()->json(['message' => 'You cannot report yourself.'], 422);
            }
            $reportedUserId = $target->id;
        } elseif ($data['reportable_type'] === ContentReport::TYPE_CATCH) {
            $catch = FishCatch::findOrFail($data['reportable_id']);
            if ((int) $catch->user_id === (int) $reporter->id) {
                return response()->json(['message' => 'You cannot report your own catch.'], 422);
            }
            $reportedUserId = $catch->user_id;
        } else {
            $message = DirectMessage::findOrFail($data['reportable_id']);
            if ((int) $message->sender_id !== (int) $reporter->id
                && (int) $message->recipient_id !== (int) $reporter->id) {
                return response()->json(['message' => 'You can only report messages in your conversations.'], 403);
            }
            $reportedUserId = (int) $message->sender_id === (int) $reporter->id
                ? $message->recipient_id
                : $message->sender_id;
        }

        $duplicate = ContentReport::where('reporter_id', $reporter->id)
            ->where('reportable_type', $data['reportable_type'])
            ->where('reportable_id', $data['reportable_id'])
            ->where('status', ContentReport::STATUS_OPEN)
            ->exists();

        if ($duplicate) {
            return response()->json([
                'message' => 'You already reported this. Our team will review it.',
            ], 200);
        }

        $report = ContentReport::create([
            'reporter_id' => $reporter->id,
            'reportable_type' => $data['reportable_type'],
            'reportable_id' => $data['reportable_id'],
            'reported_user_id' => $reportedUserId,
            'reason' => $data['reason'],
            'details' => $data['details'] ?? null,
            'status' => ContentReport::STATUS_OPEN,
        ]);

        return response()->json([
            'message' => 'Thanks — we received your report and will review it.',
            'report' => $report,
        ], 201);
    }

    public function block(Request $request, $id)
    {
        $blocker = $request->user();
        $blockedId = (int) $id;

        if ((int) $blocker->id === $blockedId) {
            return response()->json(['message' => 'You cannot block yourself.'], 422);
        }

        User::findOrFail($blockedId);

        UserBlock::firstOrCreate([
            'blocker_id' => $blocker->id,
            'blocked_id' => $blockedId,
        ]);

        // Remove follow relationships both ways so blocked users leave social graphs.
        Follow::where(function ($q) use ($blocker, $blockedId) {
            $q->where('follower_id', $blocker->id)->where('following_id', $blockedId);
        })->orWhere(function ($q) use ($blocker, $blockedId) {
            $q->where('follower_id', $blockedId)->where('following_id', $blocker->id);
        })->delete();

        return response()->json(['message' => 'User blocked.', 'blocked' => true]);
    }

    public function unblock(Request $request, $id)
    {
        UserBlock::where('blocker_id', $request->user()->id)
            ->where('blocked_id', (int) $id)
            ->delete();

        return response()->json(['message' => 'User unblocked.', 'blocked' => false]);
    }

    public function blockedUsers(Request $request)
    {
        $ids = UserBlock::where('blocker_id', $request->user()->id)->pluck('blocked_id');
        $users = User::whereIn('id', $ids)
            ->get(['id', 'name', 'profile_picture', 'location']);

        return response()->json(['users' => $users]);
    }

    public function blockStatus(Request $request, $id)
    {
        $me = $request->user()->id;
        $other = (int) $id;

        return response()->json([
            'blocked_by_me' => UserBlock::where('blocker_id', $me)->where('blocked_id', $other)->exists(),
            'blocked_me' => UserBlock::where('blocker_id', $other)->where('blocked_id', $me)->exists(),
            'either_way' => UserBlockGuard::isBlockedEitherWay($me, $other),
        ]);
    }
}
