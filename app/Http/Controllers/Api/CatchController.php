<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FishCatch;
use App\Models\Like;
use App\Models\Comment;
use Illuminate\Http\Request;

class CatchController extends Controller
{
    // POST A NEW CATCH
    public function store(Request $request)
    {
        $request->validate([
            'fish_species'   => 'required|string|max:255',
            'weight_kg'      => 'nullable|numeric|min:0',
            'length_cm'      => 'nullable|numeric|min:0',
            'bait_used'      => 'nullable|string|max:255',
            'fishing_method' => 'nullable|string|max:255',
            'caption'        => 'nullable|string|max:1000',
            'location'       => 'nullable|string|max:255',
            'latitude'       => 'nullable|numeric',
            'longitude'      => 'nullable|numeric',
            // Single legacy field (kept for backward compatibility)
            'media'          => 'nullable|file|mimes:jpg,jpeg,png,mp4|max:51200',
            // New: up to 10 photos
            'media_files'    => 'nullable|array|max:10',
            'media_files.*'  => 'file|mimes:jpg,jpeg,png|max:51200',
            'media_type'     => 'nullable|in:photo,video',
        ]);

        $catch = FishCatch::create([
            'user_id'        => $request->user()->id,
            'fish_species'   => $request->fish_species,
            'weight_kg'      => $request->weight_kg,
            'length_cm'      => $request->length_cm,
            'bait_used'      => $request->bait_used,
            'fishing_method' => $request->fishing_method,
            'caption'        => $request->caption,
            'location'       => $request->location,
            'latitude'       => $request->latitude,
            'longitude'      => $request->longitude,
            'media_type'     => $request->media_type ?? 'photo',
        ]);

        // Multi-photo upload (preferred)
        if ($request->hasFile('media_files')) {
            foreach ($request->file('media_files') as $file) {
                $catch->addMedia($file)
                      ->toMediaCollection('catch_media');
            }
        }
        // Single legacy upload
        elseif ($request->hasFile('media')) {
            $catch->addMediaFromRequest('media')
                  ->toMediaCollection('catch_media');
        }

        // Reload with relations the frontend needs; media_url/media_urls
        // are auto-appended via FishCatch model accessors.
        $catch->load(['user', 'media']);

        return response()->json([
            'message' => 'Catch posted successfully!',
            'catch'   => $catch,
        ], 201);
    }

    // VIEW A SINGLE CATCH
    public function show($id)
    {
        $catch = FishCatch::with(['user', 'comments.user', 'media'])
                          ->withCount(['likes', 'comments'])
                          ->findOrFail($id);

        return response()->json([
            'catch'      => $catch,
            'media_url'  => $catch->media_url,
            'media_urls' => $catch->media_urls,
        ]);
    }

    // VIEW ALL CATCHES BY A SPECIFIC USER
    public function userCatches($userId)
    {
        $catches = FishCatch::with(['user', 'media'])
                            ->withCount(['likes', 'comments'])
                            ->where('user_id', $userId)
                            ->latest()
                            ->get();

        return response()->json($catches);
    }

    // EDIT A CATCH
    public function update(Request $request, $id)
    {
        $catch = FishCatch::findOrFail($id);

        // Only the owner can edit
        if ($catch->user_id !== $request->user()->id) {
            return response()->json([
                'message' => 'You can only edit your own catches.',
            ], 403);
        }

        $request->validate([
            'fish_species'   => 'sometimes|string|max:255',
            'weight_kg'      => 'nullable|numeric|min:0',
            'length_cm'      => 'nullable|numeric|min:0',
            'bait_used'      => 'nullable|string|max:255',
            'fishing_method' => 'nullable|string|max:255',
            'caption'        => 'nullable|string|max:1000',
            'location'       => 'nullable|string|max:255',
        ]);

        $catch->update($request->only([
            'fish_species',
            'weight_kg',
            'length_cm',
            'bait_used',
            'fishing_method',
            'caption',
            'location',
        ]));

        return response()->json([
            'message' => 'Catch updated!',
            'catch'   => $catch,
        ]);
    }

    // DELETE A CATCH
    public function destroy(Request $request, $id)
    {
        $catch = FishCatch::findOrFail($id);

        if ($catch->user_id !== $request->user()->id) {
            return response()->json([
                'message' => 'You can only delete your own catches.',
            ], 403);
        }

        // Spatie automatically deletes media files too
        $catch->delete();

        return response()->json([
            'message' => 'Catch deleted.',
        ]);
    }

    // LIKE OR UNLIKE A CATCH (toggle)
    public function like(Request $request, $id)
    {
        $existing = Like::where('user_id', $request->user()->id)
                        ->where('catch_id', $id)
                        ->first();

        if ($existing) {
            $existing->delete();
            return response()->json(['message' => 'Catch unliked.']);
        }

        Like::create([
            'user_id'  => $request->user()->id,
            'catch_id' => $id,
        ]);

        return response()->json(['message' => 'Catch liked!']);
    }

    // COMMENT ON A CATCH
    public function comment(Request $request, $id)
    {
        $request->validate([
            'body' => 'required|string|max:500',
        ]);

        $comment = Comment::create([
            'user_id'  => $request->user()->id,
            'catch_id' => $id,
            'body'     => $request->body,
        ]);

        return response()->json([
            'message' => 'Comment posted!',
            'comment' => $comment->load('user'),
        ], 201);
    }

    // DELETE A COMMENT
    public function deleteComment(Request $request, $commentId)
    {
        $comment = Comment::findOrFail($commentId);

        if ($comment->user_id !== $request->user()->id) {
            return response()->json([
                'message' => 'You can only delete your own comments.',
            ], 403);
        }

        $comment->delete();

        return response()->json(['message' => 'Comment deleted.']);
    }
}