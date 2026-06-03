<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Tournament;
use App\Models\TournamentPost;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class TournamentAdminController extends Controller
{
    // GET /api/admin/tournaments
    public function index()
    {
        $tournaments = Tournament::with('media')
                                  ->withCount(['participants', 'posts'])
                                  ->latest('id')
                                  ->paginate(20);

        return response()->json($tournaments);
    }

    // POST /api/admin/tournaments
    public function store(Request $request)
    {
        $data = $request->validate([
            'name'                  => 'required|string|max:255',
            'description'           => 'nullable|string|max:5000',
            'location'              => 'nullable|string|max:255',
            'prize_pool'            => 'nullable|numeric|min:0',
            'entry_fee'             => 'nullable|numeric|min:0',
            'max_participants'      => 'nullable|integer|min:1',
            'starts_at'             => 'nullable|date',
            'ends_at'               => 'nullable|date|after_or_equal:starts_at',
            'registration_deadline' => 'nullable|date',
            'status'                => 'nullable|in:upcoming,open,active,completed,cancelled',
            'cover_image'           => 'nullable|string|max:1024',
        ]);

        $data['admin_id'] = $request->user()->id;
        $data['slug']     = $this->makeUniqueSlug($data['name']);
        $data['status']   = $data['status'] ?? 'upcoming';

        $tournament = Tournament::create($data);

        return response()->json([
            'message'    => 'Tournament created!',
            'tournament' => $tournament->fresh()->load('media'),
        ], 201);
    }

    // GET /api/admin/tournaments/{id}
    public function show($id)
    {
        $tournament = Tournament::with(['media', 'posts'])
                                  ->withCount(['participants', 'posts'])
                                  ->findOrFail($id);

        return response()->json($tournament);
    }

    // PUT /api/admin/tournaments/{id}
    public function update(Request $request, $id)
    {
        $tournament = Tournament::findOrFail($id);

        $data = $request->validate([
            'name'                  => 'sometimes|string|max:255',
            'description'           => 'nullable|string|max:5000',
            'location'              => 'nullable|string|max:255',
            'prize_pool'            => 'nullable|numeric|min:0',
            'entry_fee'             => 'nullable|numeric|min:0',
            'max_participants'      => 'nullable|integer|min:1',
            'starts_at'             => 'nullable|date',
            'ends_at'               => 'nullable|date|after_or_equal:starts_at',
            'registration_deadline' => 'nullable|date',
            'status'                => 'nullable|in:upcoming,open,active,completed,cancelled',
            'cover_image'           => 'nullable|string|max:1024',
        ]);

        if (isset($data['name']) && $data['name'] !== $tournament->name) {
            $data['slug'] = $this->makeUniqueSlug($data['name'], $tournament->id);
        }

        $tournament->update($data);

        return response()->json([
            'message'    => 'Tournament updated!',
            'tournament' => $tournament->fresh()->load('media'),
        ]);
    }

    // DELETE /api/admin/tournaments/{id}
    public function destroy($id)
    {
        $tournament = Tournament::findOrFail($id);
        $tournament->delete();

        return response()->json(['message' => 'Tournament deleted.']);
    }

    // POST /api/admin/tournaments/{id}/cover
    public function uploadCover(Request $request, $id)
    {
        $tournament = Tournament::findOrFail($id);

        $contentLength = (int) $request->server('CONTENT_LENGTH', 0);
        if (! $request->hasFile('cover') && $contentLength > 1024) {
            Log::warning('Tournament cover upload — request reached PHP without files', [
                'tournament_id' => $tournament->id,
                'content_length' => $contentLength,
                'php_files_keys' => array_keys($request->allFiles()),
            ]);

            return response()->json([
                'message' => 'The server rejected the upload before it reached the app. '
                            . 'Check PHP upload limits (post_max_size, upload_max_filesize).',
            ], 413);
        }

        $request->validate([
            'cover' => 'required|image|mimes:jpg,jpeg,png,webp|max:8192',
        ]);

        try {
            $tournament->clearMediaCollection('cover');
            $media = $tournament->addMediaFromRequest('cover')
                ->toMediaCollection('cover');
        } catch (\Throwable $e) {
            Log::error('Tournament cover Spatie write failed', [
                'tournament_id' => $tournament->id,
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Could not save the cover image on the server. '
                            . 'Check storage/app/public permissions and the storage symlink.',
            ], 500);
        }

        $coverUrl = $media->getUrl();
        $tournament->update(['cover_image' => $coverUrl]);

        return response()->json([
            'message'    => 'Cover image updated!',
            'tournament' => $tournament->fresh()->load('media'),
        ]);
    }

    // GET /api/admin/tournaments/{id}/posts
    public function posts($id)
    {
        $tournament = Tournament::findOrFail($id);
        $posts = $tournament->posts()
                            ->with('admin:id,name,profile_picture')
                            ->paginate(30);

        return response()->json($posts);
    }

    // POST /api/admin/tournaments/{id}/posts
    public function createPost(Request $request, $id)
    {
        $tournament = Tournament::findOrFail($id);

        $data = $request->validate([
            'title'              => 'nullable|string|max:255',
            'body'               => 'required|string|max:5000',
            'cross_post_to_feed' => 'nullable|boolean',
        ]);

        $post = TournamentPost::create([
            'tournament_id'      => $tournament->id,
            'admin_id'           => $request->user()->id,
            'title'              => $data['title'] ?? null,
            'body'               => $data['body'],
            'cross_post_to_feed' => (bool) ($data['cross_post_to_feed'] ?? false),
        ]);

        return response()->json([
            'message' => 'Tournament update posted!',
            'post'    => $post->fresh()->load('admin:id,name,profile_picture'),
        ], 201);
    }

    // PUT /api/admin/tournament-posts/{postId}
    public function updatePost(Request $request, $postId)
    {
        $post = TournamentPost::findOrFail($postId);

        $data = $request->validate([
            'title'              => 'nullable|string|max:255',
            'body'               => 'sometimes|string|max:5000',
            'cross_post_to_feed' => 'nullable|boolean',
        ]);

        if (array_key_exists('cross_post_to_feed', $data)) {
            $data['cross_post_to_feed'] = (bool) $data['cross_post_to_feed'];
        }

        $post->update($data);

        return response()->json([
            'message' => 'Update edited.',
            'post'    => $post->fresh(),
        ]);
    }

    // DELETE /api/admin/tournament-posts/{postId}
    public function destroyPost($postId)
    {
        TournamentPost::findOrFail($postId)->delete();
        return response()->json(['message' => 'Update deleted.']);
    }

    // GET /api/admin/tournaments/{id}/participants
    public function participants($id)
    {
        $tournament = Tournament::findOrFail($id);
        $participants = $tournament->participants()
                                    ->with('user:id,name,email,profile_picture')
                                    ->latest('registered_at')
                                    ->paginate(50);

        return response()->json($participants);
    }

    private function makeUniqueSlug(string $name, ?int $ignoreId = null): string
    {
        $base = Str::slug($name);
        if ($base === '') {
            $base = 'tournament-' . Str::random(6);
        }
        $slug = $base;
        $i = 2;

        while (Tournament::where('slug', $slug)
                          ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
                          ->exists()
        ) {
            $slug = $base . '-' . $i;
            $i++;
        }
        return $slug;
    }
}
