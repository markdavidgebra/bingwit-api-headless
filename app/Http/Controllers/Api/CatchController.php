<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FishCatch;
use App\Models\Like;
use App\Models\Comment;
use App\Services\WalletService;
use App\Support\CatchEconomyPresenter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class CatchController extends Controller
{
    public function __construct(private WalletService $wallet)
    {
    }
    /**
     * Collect uploaded catch photos from every field name PHP might use.
     * CloudPanel / nginx sometimes expose `media_files[]` under a different key.
     */
    private function collectCatchUploadFiles(Request $request): array
    {
        $files = [];

        if ($request->hasFile('media_files')) {
            $incoming = $request->file('media_files');
            $files = array_merge($files, is_array($incoming) ? $incoming : [$incoming]);
        }

        foreach ($request->allFiles() as $key => $value) {
            if ($key === 'media_files' || $key === 'media') {
                continue;
            }
            if (! str_starts_with((string) $key, 'media_files')) {
                continue;
            }
            if (is_array($value)) {
                $files = array_merge($files, $value);
            } elseif ($value) {
                $files[] = $value;
            }
        }

        // Legacy single-file field — only when no multi-file uploads were sent.
        if (empty($files) && $request->hasFile('media')) {
            $files[] = $request->file('media');
        }

        return $this->dedupeUploadedFiles(array_values(array_filter($files)));
    }

    /**
     * Drop duplicate uploads (e.g. same file sent as both media and media_files[0]).
     */
    private function dedupeUploadedFiles(array $files): array
    {
        $unique = [];
        $seenHashes = [];

        foreach ($files as $file) {
            try {
                $hash = md5_file($file->getRealPath());
            } catch (\Throwable) {
                $hash = null;
            }

            if ($hash !== null) {
                if (isset($seenHashes[$hash])) {
                    continue;
                }
                $seenHashes[$hash] = true;
            }

            $unique[] = $file;
        }

        return $unique;
    }

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
            'fishing_lesson' => 'nullable|string|max:2000',
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
            'fishing_lesson' => $request->fishing_lesson,
            'location'       => $request->location,
            'latitude'       => $request->latitude,
            'longitude'      => $request->longitude,
            'media_type'     => $request->media_type ?? 'photo',
        ]);

        $uploadDebug = [
            'has_media_files' => $request->hasFile('media_files'),
            'has_media'       => $request->hasFile('media'),
            'files_received'  => 0,
            'php_files_keys'  => array_keys($request->allFiles()),
            'content_length'  => (int) $request->server('CONTENT_LENGTH', 0),
            'post_max_size'   => ini_get('post_max_size'),
            'upload_max_size' => ini_get('upload_max_filesize'),
        ];

        $uploadFiles = $this->collectCatchUploadFiles($request);
        $uploadDebug['upload_file_keys'] = array_keys($request->allFiles());
        $uploadDebug['resolved_file_count'] = count($uploadFiles);

        try {
            foreach ($uploadFiles as $file) {
                $catch->addMedia($file)
                      ->toMediaCollection('catch_media');
                $uploadDebug['files_received']++;
            }
        } catch (\Throwable $e) {
            $uploadDebug['error'] = $e->getMessage();
            Log::error('Catch media upload failed', [
                'catch_id' => $catch->id,
                'message'  => $e->getMessage(),
                'trace'    => $e->getTraceAsString(),
            ]);
        }

        // If the client expected to upload photos but PHP/server discarded
        // them (post_max_size / upload_max_filesize / file_uploads), the
        // catch row would otherwise be created silently with no images.
        // Surface that explicitly so the frontend can react and so it shows
        // up in logs.
        if ($uploadDebug['files_received'] === 0
            && (int) $request->server('CONTENT_LENGTH', 0) > 1024 * 1024
            && empty($uploadDebug['php_files_keys'])
        ) {
            Log::warning('Catch posted but no files reached PHP — likely upload limit', $uploadDebug);
        }

        $hasLesson = ! empty(trim((string) $request->fishing_lesson));
        $fpAmount  = $hasLesson
            ? $this->wallet->setting('fish_points_post_lesson', '15')
            : $this->wallet->setting('fish_points_post_catch', '10');

        $this->wallet->creditFishPoints(
            $request->user(),
            $fpAmount,
            $hasLesson ? 'post_catch_lesson' : 'post_catch',
            'catch',
            (int) $catch->id,
            $hasLesson ? 'Posted a catch with a fishing lesson' : 'Posted a catch'
        );

        // Reload with relations the frontend needs; media_url/media_urls
        // are auto-appended via FishCatch model accessors.
        $catch->load(['user', 'media']);
        CatchEconomyPresenter::enrich($catch, $request->user()->id);

        return response()->json([
            'message'            => 'Catch posted successfully!',
            'catch'              => $catch,
            'fish_points_earned' => $fpAmount,
            'upload_debug'       => $uploadDebug,
        ], 201);
    }

    // VIEW A SINGLE CATCH
    public function show($id)
    {
        $catch = FishCatch::with(['user', 'comments.user', 'media'])
                          ->withReactionCounts()
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
                            ->withReactionCounts()
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
            'fishing_lesson' => 'nullable|string|max:2000',
            'location'       => 'nullable|string|max:255',
        ]);

        $catch->update($request->only([
            'fish_species',
            'weight_kg',
            'length_cm',
            'bait_used',
            'fishing_method',
            'caption',
            'fishing_lesson',
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

    // LIKE OR UNLIKE A CATCH (toggle thumbs up)
    public function like(Request $request, $id)
    {
        return $this->toggleReaction($request, $id, Like::TYPE_LIKE);
    }

    // LOVE OR UNLOVE A CATCH (toggle heart)
    public function love(Request $request, $id)
    {
        return $this->toggleReaction($request, $id, Like::TYPE_LOVE);
    }

    private function toggleReaction(Request $request, int $id, string $type)
    {
        FishCatch::findOrFail($id);

        $existing = Like::where('user_id', $request->user()->id)
                        ->where('catch_id', $id)
                        ->where('type', $type)
                        ->first();

        if ($existing) {
            $existing->delete();
            $message = $type === Like::TYPE_LOVE
                ? 'Catch unloved.'
                : 'Catch unliked.';
            $active = false;
        } else {
            $opposite = $type === Like::TYPE_LOVE
                ? Like::TYPE_LIKE
                : Like::TYPE_LOVE;

            Like::where('user_id', $request->user()->id)
                ->where('catch_id', $id)
                ->where('type', $opposite)
                ->delete();

            Like::create([
                'user_id'  => $request->user()->id,
                'catch_id' => $id,
                'type'     => $type,
            ]);
            $message = $type === Like::TYPE_LOVE
                ? 'Catch loved!'
                : 'Catch liked!';
            $active = true;
        }

        $userId = $request->user()->id;

        return response()->json(array_merge([
            'message'     => $message,
            'active'      => $active,
            'liked_by_me' => Like::where('user_id', $userId)
                                 ->where('catch_id', $id)
                                 ->where('type', Like::TYPE_LIKE)
                                 ->exists(),
            'loved_by_me' => Like::where('user_id', $userId)
                                 ->where('catch_id', $id)
                                 ->where('type', Like::TYPE_LOVE)
                                 ->exists(),
        ], $this->reactionCounts($id)));
    }

    private function reactionCounts(int $catchId): array
    {
        return [
            'likes_count' => Like::where('catch_id', $catchId)
                                 ->where('type', Like::TYPE_LIKE)
                                 ->count(),
            'loves_count' => Like::where('catch_id', $catchId)
                                 ->where('type', Like::TYPE_LOVE)
                                 ->count(),
        ];
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