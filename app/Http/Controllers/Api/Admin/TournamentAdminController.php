<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Tournament;
use App\Models\TournamentPost;
use App\Models\TournamentDay;
use App\Models\TournamentDayParticipant;
use App\Models\User;
use App\Services\TournamentRankingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class TournamentAdminController extends Controller
{
    public function __construct(private TournamentRankingService $ranking)
    {
    }

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
        $this->ranking->syncDays($tournament);

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
        $this->ranking->syncDays($tournament->fresh());

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

        $tournament->update(['cover_image' => $media->getPathRelativeToRoot()]);

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
            'media_files'      => 'nullable|array|max:5',
            'media_files.*'    => 'file|mimes:jpg,jpeg,png,webp|max:8192',
        ]);

        $post = TournamentPost::create([
            'tournament_id'      => $tournament->id,
            'admin_id'           => $request->user()->id,
            'title'              => $data['title'] ?? null,
            'body'               => $data['body'],
            'cross_post_to_feed' => filter_var(
                $data['cross_post_to_feed'] ?? false,
                FILTER_VALIDATE_BOOLEAN
            ),
        ]);

        foreach ($this->collectPostUploadFiles($request) as $file) {
            $post->addMedia($file)->toMediaCollection('images');
        }

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
        $post = TournamentPost::findOrFail($postId);
        $post->clearMediaCollection('images');
        $post->delete();

        return response()->json(['message' => 'Update deleted.']);
    }

    private function collectPostUploadFiles(Request $request): array
    {
        $files = [];

        if ($request->hasFile('media_files')) {
            $incoming = $request->file('media_files');
            $files = array_merge($files, is_array($incoming) ? $incoming : [$incoming]);
        }

        foreach ($request->allFiles() as $key => $value) {
            if ($key === 'media_files') {
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

        return array_values(array_filter($files));
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

    // POST /api/admin/tournaments/{id}/participants
    public function addParticipant(Request $request, $id)
    {
        $tournament = Tournament::findOrFail($id);

        $data = $request->validate([
            'user_id' => 'required_without:email|integer|exists:users,id',
            'email'   => 'required_without:user_id|email',
            'status'  => 'nullable|in:registered,confirmed',
        ]);

        $userId = $data['user_id'] ?? null;

        if (! $userId && ! empty($data['email'])) {
            $userId = User::where('email', $data['email'])->value('id');
            if (! $userId) {
                return response()->json(['message' => 'No user found with that email.'], 404);
            }
        }

        $participant = $this->ranking->addTournamentParticipant(
            $tournament,
            (int) $userId,
            $data['status'] ?? 'confirmed'
        );

        return response()->json([
            'message'     => 'Angler added to tournament.',
            'participant' => $participant->load('user:id,name,email,profile_picture'),
        ], 201);
    }

    // DELETE /api/admin/tournaments/{id}/participants/{participantId}
    public function removeParticipant($id, $participantId)
    {
        $tournament = Tournament::findOrFail($id);

        $participant = $tournament->participants()->findOrFail($participantId);
        $participant->update(['status' => 'withdrawn']);

        TournamentDayParticipant::where('tournament_participant_id', $participant->id)->delete();

        return response()->json(['message' => 'Angler removed from tournament.']);
    }

    // GET /api/admin/users/search?q=
    public function searchUsers(Request $request)
    {
        $request->validate(['q' => 'required|string|min:2|max:100']);
        $term = '%' . $request->q . '%';

        $users = User::query()
            ->where(function ($q) use ($term) {
                $q->where('name', 'like', $term)
                  ->orWhere('email', 'like', $term);
            })
            ->orderBy('name')
            ->limit(20)
            ->get(['id', 'name', 'email', 'profile_picture']);

        return response()->json(['data' => $users]);
    }

    // GET /api/admin/tournaments/{id}/days
    public function days($id)
    {
        $tournament = Tournament::findOrFail($id);
        $days = $this->ranking->syncDays($tournament)
            ->loadCount('dayParticipants');

        return response()->json(['data' => $days]);
    }

    // GET /api/admin/tournaments/{id}/days/{dayId}/participants
    public function dayParticipants($id, $dayId)
    {
        $tournament = Tournament::findOrFail($id);
        $day = $tournament->days()->findOrFail($dayId);

        $participants = $day->dayParticipants()
            ->with('user:id,name,email,profile_picture')
            ->orderBy('id')
            ->get();

        return response()->json(['data' => $participants]);
    }

    // PUT /api/admin/tournaments/{id}/days/{dayId}/participants
    public function syncDayParticipants(Request $request, $id, $dayId)
    {
        $tournament = Tournament::findOrFail($id);
        $day = $tournament->days()->findOrFail($dayId);

        $data = $request->validate([
            'user_ids'   => 'required|array',
            'user_ids.*' => 'integer|exists:users,id',
        ]);

        $participants = $this->ranking->syncDayParticipants($day, $data['user_ids']);

        return response()->json([
            'message'      => 'Day participants updated.',
            'participants' => $participants,
        ]);
    }

    // GET /api/admin/tournaments/{id}/days/{dayId}/leaderboard
    public function dayLeaderboard(Request $request, $id, $dayId)
    {
        $tournament = Tournament::findOrFail($id);
        $day = $tournament->days()->findOrFail($dayId);
        $type = $request->query('type', 'biggest');

        if (! in_array($type, ['biggest', 'most'], true)) {
            $type = 'biggest';
        }

        $board = $this->ranking->dayLeaderboard($day, $type);

        return response()->json(array_merge([
            'day'        => $day,
            'tournament' => $tournament->only(['id', 'name', 'status']),
            'label'      => $day->day_date->format('M j, Y'),
        ], $board));
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
