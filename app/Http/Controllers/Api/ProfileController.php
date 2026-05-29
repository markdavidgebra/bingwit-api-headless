<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Follow;
use Illuminate\Http\Request;

class ProfileController extends Controller
{
    // VIEW MY OWN PROFILE
    public function me(Request $request)
    {
        $user = $request->user();

        return response()->json([
            'user' => $user,
            'followers_count' => $user->followers()->count(),
            'following_count' => $user->following()->count(),
        ]);
    }

    // VIEW SOMEONE ELSE'S PROFILE
    public function show($id)
    {
        $user = User::findOrFail($id);

        return response()->json([
            'user' => $user,
            'followers_count' => $user->followers()->count(),
            'following_count' => $user->following()->count(),
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
        $request->validate([
            'photo' => 'required|image|mimes:jpg,jpeg,png|max:2048',
        ]);

        $user = $request->user();

        // Store photo using Spatie Media Library
        $user->clearMediaCollection('profile_picture');
        $media = $user->addMediaFromRequest('photo')
            ->toMediaCollection('profile_picture');

        return response()->json([
            'message' => 'Profile picture updated!',
            'photo_url' => $media->getUrl(),
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

    // GET ALL USERS (for admin)
    // GET ALL USERS FOR ADMIN DROPDOWN
public function allUsers()
{
    $users = User::select('id', 'name', 'email')
                              ->orderBy('name')
                              ->get();

    return response()->json($users);
}
}