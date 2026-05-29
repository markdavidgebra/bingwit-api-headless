<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\GroupPost;
use App\Models\User;
use Illuminate\Http\Request;

class GroupController extends Controller
{
    // GET ALL PUBLIC GROUPS
    public function index(Request $request)
    {
        $groups = Group::with(['creator' => function ($q) {
                            $q->select('id', 'name', 'profile_picture');
                        }])
                        ->withCount('members')
                        ->where('privacy', 'public')
                        ->latest()
                        ->paginate(15);

        return response()->json($groups);
    }

    // GET SUGGESTED GROUPS (not joined yet)
    public function suggested(Request $request)
    {
        $userId = $request->user()->id;

        $joinedGroupIds = GroupMember::where('user_id', $userId)
                                     ->pluck('group_id');

        $groups = Group::with(['creator' => function ($q) {
                            $q->select('id', 'name', 'profile_picture');
                        }])
                        ->withCount('members')
                        ->where('privacy', 'public')
                        ->whereNotIn('id', $joinedGroupIds)
                        ->latest()
                        ->limit(10)
                        ->get();

        return response()->json($groups);
    }

    // GET SUGGESTED ANGLERS (not following yet)
    public function suggestedAnglers(Request $request)
    {
        $userId = $request->user()->id;

        $followingIds = $request->user()
                                ->following()
                                ->pluck('users.id');

        // Exclude self and already following
        $excludeIds = $followingIds->push($userId);

        $anglers = User::whereNotIn('id', $excludeIds)
                       ->withCount('catches')
                       ->latest()
                       ->limit(10)
                       ->get([
                           'id',
                           'name',
                           'profile_picture',
                           'location',
                           'fishing_style',
                       ]);

        return response()->json($anglers);
    }

    // CREATE A GROUP
    public function store(Request $request)
    {
        $request->validate([
            'name'        => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'category'    => 'nullable|string|max:100',
            'privacy'     => 'nullable|in:public,private',
        ]);

        $group = Group::create([
            'creator_id'  => $request->user()->id,
            'name'        => $request->name,
            'description' => $request->description,
            'category'    => $request->category ?? 'general',
            'privacy'     => $request->privacy   ?? 'public',
        ]);

        // Creator auto-joins as admin
        GroupMember::create([
            'group_id' => $group->id,
            'user_id'  => $request->user()->id,
            'role'     => 'admin',
        ]);

        return response()->json([
            'message' => 'Group created!',
            'group'   => $group->load('creator'),
        ], 201);
    }

    // VIEW A SINGLE GROUP
    public function show(Request $request, $id)
    {
        $group = Group::with([
                        'creator' => function ($q) {
                            $q->select('id', 'name', 'profile_picture');
                        },
                        'members.user' => function ($q) {
                            $q->select('id', 'name', 'profile_picture');
                        },
                    ])
                    ->withCount('members')
                    ->findOrFail($id);

        $isMember = false;
        if ($request->user()) {
            $isMember = $group->isMember($request->user()->id);
        }

        return response()->json([
            'group'     => $group,
            'is_member' => $isMember,
        ]);
    }

    // JOIN A GROUP
    public function join(Request $request, $id)
    {
        $group = Group::findOrFail($id);

        if ($group->isMember($request->user()->id)) {
            return response()->json([
                'message' => 'You are already a member.',
            ], 400);
        }

        GroupMember::create([
            'group_id' => $id,
            'user_id'  => $request->user()->id,
            'role'     => 'member',
        ]);

        return response()->json([
            'message' => 'Joined group successfully!',
        ]);
    }

    // LEAVE A GROUP
    public function leave(Request $request, $id)
    {
        GroupMember::where('group_id', $id)
                   ->where('user_id', $request->user()->id)
                   ->delete();

        return response()->json([
            'message' => 'Left group.',
        ]);
    }

    // GET GROUP POSTS
    public function posts(Request $request, $id)
    {
        $posts = GroupPost::with([
                            'user' => function ($q) {
                                $q->select('id', 'name', 'profile_picture');
                            },
                            'catch',
                        ])
                        ->where('group_id', $id)
                        ->latest()
                        ->paginate(15);

        return response()->json($posts);
    }

    // POST IN A GROUP
    public function createPost(Request $request, $id)
    {
        $group = Group::findOrFail($id);

        if (!$group->isMember($request->user()->id)) {
            return response()->json([
                'message' => 'You must be a member to post.',
            ], 403);
        }

        $request->validate([
            'body'     => 'nullable|string|max:1000',
            'catch_id' => 'nullable|exists:catches,id',
        ]);

        $post = GroupPost::create([
            'group_id' => $id,
            'user_id'  => $request->user()->id,
            'body'     => $request->body,
            'catch_id' => $request->catch_id,
        ]);

        return response()->json([
            'message' => 'Post created!',
            'post'    => $post->load(['user', 'catch']),
        ], 201);
    }

    // DELETE A GROUP (creator only)
    public function destroy(Request $request, $id)
    {
        $group = Group::findOrFail($id);

        if ($group->creator_id !== $request->user()->id) {
            return response()->json([
                'message' => 'Only the creator can delete this group.',
            ], 403);
        }

        $group->delete();

        return response()->json([
            'message' => 'Group deleted.',
        ]);
    }

    // GET MY GROUPS
    public function myGroups(Request $request)
    {
        $groupIds = GroupMember::where('user_id', $request->user()->id)
                               ->pluck('group_id');

        $groups = Group::with(['creator' => function ($q) {
                            $q->select('id', 'name', 'profile_picture');
                        }])
                        ->withCount('members')
                        ->whereIn('id', $groupIds)
                        ->latest()
                        ->get();

        return response()->json($groups);
    }
}