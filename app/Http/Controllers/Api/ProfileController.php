<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Follow;
use App\Models\FishingSpot;
use App\Models\UserBlock;
use App\Support\AnglerRanker;
use App\Support\OptionalBearerUser;
use App\Support\UserBlockGuard;
use App\Services\WalletService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ProfileController extends Controller
{
    // VIEW MY OWN PROFILE
    public function me(Request $request)
    {
        $user = $request->user();
        $code = $user->ensureReferralCode();
        $user->refresh();

        return response()->json([
            'user' => $user,
            'followers_count' => $user->followers()->count(),
            'following_count' => $user->following()->count(),
            'referral' => [
                'code'      => $code,
                'link'      => $user->referralLink(),
                'signups'   => $user->referrals()->count(),
                'bonus_fp'  => app(WalletService::class)->setting('fish_points_referral_bonus', '25'),
            ],
        ]);
    }

    // VIEW SOMEONE ELSE'S PROFILE
    public function show(Request $request, $id)
    {
        $user = User::withCount(['followers', 'following', 'catches'])->findOrFail($id);
        $viewerId = OptionalBearerUser::id($request);

        $isFollowing = false;
        $isBlockedByMe = false;
        if ($viewerId) {
            $isFollowing = Follow::where('follower_id', $viewerId)
                ->where('following_id', $user->id)
                ->exists();
            $isBlockedByMe = UserBlock::where('blocker_id', $viewerId)
                ->where('blocked_id', $user->id)
                ->exists();
        }

        return response()->json([
            'user' => $user->makeHidden(['referral_code']),
            'followers_count' => $user->followers_count,
            'following_count' => $user->following_count,
            'catches_count' => $user->catches_count,
            'is_following' => $isFollowing,
            'is_blocked_by_me' => $isBlockedByMe,
            'blocked_either_way' => $viewerId
                ? UserBlockGuard::isBlockedEitherWay((int) $viewerId, (int) $user->id)
                : false,
        ]);
    }

    /** Search engine for anglers (name, location, fishing style, pin places). */
    public function searchAnglers(Request $request)
    {
        $request->validate([
            'q' => 'nullable|string|max:100',
            'location' => 'nullable|string|max:100',
            'pin' => 'nullable|string|max:100',
        ]);

        $q = trim((string) $request->query('q', ''));
        $location = trim((string) $request->query('location', ''));
        $pin = trim((string) $request->query('pin', ''));

        if ($q === '' && $location === '' && $pin === '') {
            return response()->json([
                'message' => 'Provide q, location, or pin to search.',
                'anglers' => [],
            ], 422);
        }

        $viewerId = $request->user()?->id;
        $query = User::query();

        if ($q !== '') {
            $term = '%' . $q . '%';
            $query->where(function ($builder) use ($term) {
                $builder->where('name', 'like', $term)
                    ->orWhere('location', 'like', $term)
                    ->orWhere('fishing_style', 'like', $term)
                    ->orWhere('bio', 'like', $term);
            });
        }

        if ($location !== '') {
            $query->where('location', 'like', '%' . $location . '%');
        }

        // Anglers who pinned fishing spots matching the place name.
        if ($pin !== '') {
            $userIds = FishingSpot::where('name', 'like', '%' . $pin . '%')
                ->orWhere('description', 'like', '%' . $pin . '%')
                ->pluck('user_id')
                ->unique()
                ->filter()
                ->values();

            $query->where(function ($builder) use ($userIds, $pin) {
                $builder->whereIn('id', $userIds)
                    ->orWhere('location', 'like', '%' . $pin . '%');
            });
        }

        $hint = $location !== '' ? $location : ($pin !== '' ? $pin : $q);
        AnglerRanker::applyRanking($query, $hint);

        $anglers = $query->limit(40)->get();

        return response()->json([
            'anglers' => AnglerRanker::formatMany($anglers, $viewerId),
        ]);
    }

    // UPDATE MY PROFILE
    public function update(Request $request)
    {
        $user = $request->user();

        $request->validate([
            'name' => 'sometimes|string|max:255',
            'bio' => 'nullable|string|max:500',
            'location' => 'nullable|string|max:255',
            'fishing_style' => 'nullable|string|max:255',
        ]);

        $user->update($request->only([
            'name',
            'bio',
            'location',
            'fishing_style',
        ]));

        return response()->json([
            'message' => 'Profile updated successfully!',
            'user' => $user,
        ]);
    }

    // UPLOAD PROFILE PICTURE
    public function uploadPhoto(Request $request)
    {
        // If PHP/server discarded the upload (post_max_size, upload_max_filesize,
        // file_uploads=Off), $request->file('photo') will be null and Laravel's
        // 'required' rule reports a misleading message. Detect that explicitly
        // so the client can show a useful error.
        $contentLength = (int) $request->server('CONTENT_LENGTH', 0);
        if (! $request->hasFile('photo') && $contentLength > 1024) {
            $debug = [
                'content_length'      => $contentLength,
                'php_files_keys'      => array_keys($request->allFiles()),
                'post_max_size'       => ini_get('post_max_size'),
                'upload_max_filesize' => ini_get('upload_max_filesize'),
                'file_uploads'        => (bool) ini_get('file_uploads'),
            ];
            Log::warning('Profile photo upload — request reached PHP without files', $debug);

            return response()->json([
                'message'      => 'The server rejected the upload before it reached the app. '
                                . 'Most likely the host\'s PHP upload limits are too low. '
                                . 'See `upload_debug` for the effective limits.',
                'upload_debug' => $debug,
            ], 413);
        }

        $request->validate([
            // Generous: client-side compression keeps real uploads <500 KB,
            // but allow up to 8 MB so a slightly larger file still works.
            'photo' => 'required|image|mimes:jpg,jpeg,png,webp|max:8192',
        ]);

        $user = $request->user();

        try {
            $user->clearMediaCollection('profile_picture');
            $media = $user->addMediaFromRequest('photo')
                ->toMediaCollection('profile_picture');
        } catch (\Throwable $e) {
            Log::error('Profile photo Spatie write failed', [
                'user_id' => $user->id,
                'message' => $e->getMessage(),
            ]);
            return response()->json([
                'message'      => 'Could not save the photo on the server. '
                                . 'Most likely a permissions issue on storage/app/public/.',
                'upload_debug' => ['error' => $e->getMessage()],
            ], 500);
        }

        $photoUrl = $media->getUrl();

        // Persist URL on the user row so profile works even if the
        // getProfilePictureAttribute accessor is not deployed yet.
        $user->update(['profile_picture' => $photoUrl]);

        return response()->json([
            'message'   => 'Profile picture updated!',
            'photo_url' => $photoUrl,
            'user'      => $user->fresh(),
        ]);
    }

    // FOLLOW A USER
    public function follow(Request $request, $id)
    {
        $currentUser = $request->user();

        if ($currentUser->id == $id) {
            return response()->json([
                'message' => 'You cannot follow yourself.',
            ], 400);
        }

        $already = Follow::where('follower_id', $currentUser->id)
            ->where('following_id', $id)
            ->exists();

        if ($already) {
            return response()->json([
                'message' => 'You are already following this user.',
            ], 400);
        }

        Follow::create([
            'follower_id' => $currentUser->id,
            'following_id' => $id,
        ]);

        return response()->json([
            'message' => 'User followed successfully!',
        ]);
    }

    // UNFOLLOW A USER
    public function unfollow(Request $request, $id)
    {
        Follow::where('follower_id', $request->user()->id)
            ->where('following_id', $id)
            ->delete();

        return response()->json([
            'message' => 'User unfollowed.',
        ]);
    }
    // GET PEOPLE I FOLLOW
    public function myFollowing(Request $request)
    {
        $following = $request->user()
            ->following()
            ->get([
                'users.id',
                'users.name',
                'users.profile_picture',
                'users.location',
                'users.fishing_style',
            ]);

        return response()->json($following);
    }

    // GET SUGGESTED ANGLERS — ranked by followers + catches + location affinity
    public function suggestedAnglers(Request $request)
    {
        $user = $request->user();
        $userId = $user->id;

        $followingIds = $user->following()->pluck('users.id');
        $excludeIds = $followingIds->push($userId);

        $query = User::whereNotIn('id', $excludeIds);
        AnglerRanker::applyRanking($query, $user->location);

        $anglers = $query->limit(20)->get();

        return response()->json(AnglerRanker::formatMany($anglers, $userId));
    }

    // GET ALL USERS FOR ADMIN DROPDOWN
    public function allUsers()
    {
        $users = User::select('id', 'name', 'email')
            ->orderBy('name')
            ->get();

        return response()->json($users);
    }
}