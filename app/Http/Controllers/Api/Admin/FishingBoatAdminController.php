<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\BoatBooking;
use App\Models\FishingBoat;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class FishingBoatAdminController extends Controller
{
    public function index()
    {
        $boats = FishingBoat::with('media')
                            ->withCount([
                                'bookings',
                                'bookings as pending_bookings_count' => function ($q) {
                                    $q->where('status', 'pending');
                                },
                            ])
                            ->latest('id')
                            ->paginate(20);

        return response()->json($boats);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name'              => 'required|string|max:255',
            'description'       => 'nullable|string|max:5000',
            'location'          => 'nullable|string|max:255',
            'departure_point'   => 'nullable|string|max:255',
            'capacity'          => 'nullable|integer|min:1|max:100',
            'trip_price'        => 'nullable|numeric|min:0',
            'duration_hours'    => 'nullable|integer|min:1|max:72',
            'captain_name'      => 'nullable|string|max:255',
            'contact_phone'     => 'nullable|string|max:50',
            'status'            => 'nullable|in:available,unavailable,maintenance',
            'cover_image'       => 'nullable|string|max:1024',
            'cover_focus_x'     => 'nullable|integer|min:0|max:100',
            'cover_focus_y'     => 'nullable|integer|min:0|max:100',
        ]);

        $data['admin_id'] = $request->user()->id;
        $data['slug']     = $this->makeUniqueSlug($data['name']);
        $data['status']   = $data['status'] ?? 'available';
        $data['capacity'] = $data['capacity'] ?? 6;

        $boat = FishingBoat::create($data);

        return response()->json([
            'message' => 'Fishing boat created!',
            'boat'    => $boat->fresh()->load('media'),
        ], 201);
    }

    public function show($id)
    {
        $boat = FishingBoat::with('media')
                           ->withCount('bookings')
                           ->findOrFail($id);

        return response()->json($boat);
    }

    public function update(Request $request, $id)
    {
        $boat = FishingBoat::findOrFail($id);

        $data = $request->validate([
            'name'              => 'sometimes|string|max:255',
            'description'       => 'nullable|string|max:5000',
            'location'          => 'nullable|string|max:255',
            'departure_point'   => 'nullable|string|max:255',
            'capacity'          => 'nullable|integer|min:1|max:100',
            'trip_price'        => 'nullable|numeric|min:0',
            'duration_hours'    => 'nullable|integer|min:1|max:72',
            'captain_name'      => 'nullable|string|max:255',
            'contact_phone'     => 'nullable|string|max:50',
            'status'            => 'nullable|in:available,unavailable,maintenance',
            'cover_image'       => 'nullable|string|max:1024',
            'cover_focus_x'     => 'nullable|integer|min:0|max:100',
            'cover_focus_y'     => 'nullable|integer|min:0|max:100',
        ]);

        if (isset($data['name']) && $data['name'] !== $boat->name) {
            $data['slug'] = $this->makeUniqueSlug($data['name'], $boat->id);
        }

        $boat->update($data);

        return response()->json([
            'message' => 'Fishing boat updated!',
            'boat'    => $boat->fresh()->load('media'),
        ]);
    }

    public function destroy($id)
    {
        FishingBoat::findOrFail($id)->delete();

        return response()->json(['message' => 'Fishing boat deleted.']);
    }

    public function uploadCover(Request $request, $id)
    {
        $boat = FishingBoat::findOrFail($id);

        if (! $request->hasFile('cover')) {
            Log::warning('Boat cover upload — no file', ['boat_id' => $boat->id]);

            return response()->json(['message' => 'No cover image uploaded.'], 422);
        }

        $request->validate([
            'cover' => 'required|image|max:10240',
        ]);

        try {
            $boat->clearMediaCollection('cover');
            $media = $boat->addMediaFromRequest('cover')->toMediaCollection('cover');
        } catch (\Throwable $e) {
            Log::error('Boat cover upload failed', [
                'boat_id' => $boat->id,
                'error'   => $e->getMessage(),
            ]);

            return response()->json(['message' => 'Could not save cover image.'], 500);
        }

        $relativePath = $media->getPathRelativeToRoot();
        $boat->update(['cover_image' => $relativePath]);

        return response()->json([
            'message' => 'Cover uploaded!',
            'boat'    => $boat->fresh()->load('media'),
        ]);
    }

    public function bookings($id)
    {
        $boat = FishingBoat::findOrFail($id);

        $bookings = $boat->bookings()
                         ->with('user:id,name,email,profile_picture')
                         ->latest('trip_at')
                         ->paginate(30);

        return response()->json($bookings);
    }

    public function updateBookingStatus(Request $request, $bookingId)
    {
        $booking = BoatBooking::findOrFail($bookingId);

        $data = $request->validate([
            'status' => 'required|in:pending,confirmed,cancelled,completed',
        ]);

        $booking->update(['status' => $data['status']]);

        return response()->json([
            'message' => 'Booking status updated.',
            'booking' => $booking->fresh()->load(['user:id,name,email', 'boat:id,name']),
        ]);
    }

    private function makeUniqueSlug(string $name, ?int $exceptId = null): string
    {
        $base = Str::slug($name) ?: 'boat';
        $slug = $base;
        $n    = 1;

        while (FishingBoat::where('slug', $slug)
            ->when($exceptId, fn ($q) => $q->where('id', '!=', $exceptId))
            ->exists()
        ) {
            $slug = $base . '-' . (++$n);
        }

        return $slug;
    }
}
