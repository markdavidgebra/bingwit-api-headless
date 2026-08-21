<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CatchLessonConfirmation;
use App\Models\CatchStarGift;
use App\Models\FishCatch;
use App\Models\Notification;
use App\Services\WalletService;
use Illuminate\Http\Request;

class CatchEconomyController extends Controller
{
    public function __construct(private WalletService $wallet)
    {
    }

    /** Gift Fish Points to the catch author (Stars are only for redeem after conversion). */
    public function giftFishPoints(Request $request, $catchId)
    {
        $request->validate([
            'fish_points' => 'required|integer|min:1|max:500',
            'message'     => 'nullable|string|max:500',
        ]);

        $catch = FishCatch::with('user')->findOrFail($catchId);
        $giver = $request->user();
        $points = (int) $request->fish_points;

        if ($catch->user_id === $giver->id) {
            return response()->json(['message' => 'You cannot gift Fish Points to your own catch.'], 422);
        }

        try {
            $this->wallet->transferFishPoints(
                $giver,
                $catch->user,
                $points,
                'catch_fish_points_gift',
                'catch',
                (int) $catch->id,
                'Fish Points gifted on catch'
            );
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $comment = trim((string) $request->message);

        CatchStarGift::create([
            'giver_id'     => $giver->id,
            'catch_id'     => $catch->id,
            'fish_points'  => $points,
            'message'      => $comment !== '' ? $comment : null,
        ]);

        $catch->increment('fish_points_received', $points);

        $notifBody = $comment !== ''
            ? $comment
            : $giver->name . ' gifted you ' . $points . ' Fish Points on your ' . $catch->fish_species . ' catch.';

        Notification::create([
            'user_id'        => $catch->user_id,
            'type'           => 'fish_points_gift',
            'title'          => $giver->name . ' gave you ' . $points . ' Fish Points!',
            'body'           => $notifBody,
            'reference_id'   => $catch->id,
            'reference_type' => 'catch',
        ]);

        return response()->json([
            'message'                => 'Fish Points sent!',
            'fish_points_received'   => $catch->fresh()->fish_points_received,
            'your_fish_points'       => $giver->fresh()->fish_points,
        ]);
    }

    /** Donate Stars from one angler to another on a catch (requirement a). */
    public function giftStars(Request $request, $catchId)
    {
        // Older clients that still send fish_points on this route.
        if ($request->has('fish_points') && ! $request->has('stars')) {
            return $this->giftFishPoints($request, $catchId);
        }

        $request->validate([
            'stars'   => 'required|integer|min:1|max:100',
            'message' => 'nullable|string|max:500',
        ]);

        $catch = FishCatch::with('user')->findOrFail($catchId);
        $giver = $request->user();
        $stars = (int) $request->stars;

        if ($catch->user_id === $giver->id) {
            return response()->json(['message' => 'You cannot gift Stars to your own catch.'], 422);
        }

        try {
            $this->wallet->transferStars(
                $giver,
                $catch->user,
                $stars,
                'catch_star_gift',
                'catch',
                (int) $catch->id,
                'Stars gifted on catch'
            );
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $comment = trim((string) $request->message);

        CatchStarGift::create([
            'giver_id'    => $giver->id,
            'catch_id'    => $catch->id,
            'fish_points' => 0,
            'stars'       => $stars,
            'message'     => $comment !== '' ? $comment : null,
        ]);

        if (\Illuminate\Support\Facades\Schema::hasColumn('catches', 'stars_received')) {
            $catch->increment('stars_received', $stars);
        }

        $notifBody = $comment !== ''
            ? $comment
            : $giver->name . ' gifted you ' . $stars . ' Stars on your ' . $catch->fish_species . ' catch.';

        Notification::create([
            'user_id'        => $catch->user_id,
            'type'           => 'star_gift',
            'title'          => $giver->name . ' gave you ' . $stars . ' Stars!',
            'body'           => $notifBody,
            'reference_id'   => $catch->id,
            'reference_type' => 'catch',
        ]);

        return response()->json([
            'message'         => 'Stars sent!',
            'stars_received'  => $catch->fresh()->stars_received ?? $stars,
            'your_stars'      => $giver->fresh()->stars,
        ]);
    }

    public function confirmLesson(Request $request, $catchId)
    {
        $request->validate([
            'note' => 'nullable|string|max:500',
        ]);

        $catch = FishCatch::findOrFail($catchId);

        if (empty($catch->fishing_lesson)) {
            return response()->json(['message' => 'This catch has no fishing lesson to confirm.'], 422);
        }

        if ($catch->user_id === $request->user()->id) {
            return response()->json(['message' => 'You cannot confirm your own lesson.'], 422);
        }

        $existing = CatchLessonConfirmation::where('user_id', $request->user()->id)
            ->where('catch_id', $catch->id)
            ->first();

        if ($existing) {
            return response()->json(['message' => 'You already confirmed this tip.'], 422);
        }

        CatchLessonConfirmation::create([
            'user_id'  => $request->user()->id,
            'catch_id' => $catch->id,
            'note'     => $request->note,
        ]);

        $catch->increment('lesson_confirmations_count');

        $author = $catch->user;
        $this->wallet->creditFishPoints(
            $author,
            $this->wallet->setting('fish_points_lesson_confirmed', '5'),
            'lesson_confirmed',
            'catch',
            (int) $catch->id,
            'Another angler confirmed your fishing lesson'
        );

        Notification::create([
            'user_id'        => $catch->user_id,
            'type'           => 'lesson_confirm',
            'title'          => $request->user()->name . ' confirmed your tip!',
            'body'           => 'They agree with your fishing lesson.',
            'reference_id'   => $catch->id,
            'reference_type' => 'catch',
        ]);

        return response()->json([
            'message'                      => 'Tip confirmed — thanks for sharing your experience!',
            'lesson_confirmations_count'   => $catch->fresh()->lesson_confirmations_count,
            'confirmed_by_me'              => true,
        ]);
    }

    public function unconfirmLesson(Request $request, $catchId)
    {
        $catch = FishCatch::findOrFail($catchId);

        $row = CatchLessonConfirmation::where('user_id', $request->user()->id)
            ->where('catch_id', $catch->id)
            ->first();

        if (! $row) {
            return response()->json(['message' => 'You have not confirmed this tip.'], 422);
        }

        $row->delete();
        $catch->decrement('lesson_confirmations_count');

        return response()->json([
            'message'                    => 'Confirmation removed.',
            'lesson_confirmations_count' => $catch->fresh()->lesson_confirmations_count,
            'confirmed_by_me'            => false,
        ]);
    }
}
