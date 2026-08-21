<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FishingSpot;
use App\Models\SavedSpot;
use App\Models\FishCatch;
use App\Models\User;
use App\Support\AnglerRanker;
use Illuminate\Http\Request;

class MapController extends Controller
{
    // GET ALL FISHING SPOTS
    public function spots()
    {
        $spots = FishingSpot::with(['user' => function ($q) {
                                $q->select(
                                    'id',
                                    'name',
                                    'profile_picture'
                                );
                            }])
                            ->latest()
                            ->get();

        return response()->json($spots);
    }

    // ADD A NEW FISHING SPOT
    public function addSpot(Request $request)
    {
        $request->validate([
            'name'        => 'required|string|max:255',
            'description' => 'nullable|string|max:500',
            'latitude'    => 'required|numeric',
            'longitude'   => 'required|numeric',
            'spot_type'   => 'nullable|string|max:100',
        ]);

        $spot = FishingSpot::create([
            'user_id'     => $request->user()->id,
            'name'        => $request->name,
            'description' => $request->description,
            'latitude'    => $request->latitude,
            'longitude'   => $request->longitude,
            'spot_type'   => $request->spot_type ?? 'general',
        ]);

        return response()->json([
            'message' => 'Fishing spot added!',
            'spot'    => $spot,
        ], 201);
    }

    // GET RECENT CATCHES NEAR A LOCATION
    public function nearbyCatches(Request $request)
    {
        $request->validate([
            'latitude'  => 'required|numeric',
            'longitude' => 'required|numeric',
            'radius_km' => 'nullable|numeric|min:1|max:100',
        ]);

        $lat    = $request->latitude;
        $lon    = $request->longitude;
        $radius = $request->radius_km ?? 20;

        // Haversine formula — calculates real distance
        // between two GPS coordinates on a sphere (Earth)
        $catches = FishCatch::with(['user' => function ($q) {
                                $q->select(
                                    'id',
                                    'name',
                                    'profile_picture'
                                );
                            }])
                            ->whereNotNull('latitude')
                            ->whereNotNull('longitude')
                            ->selectRaw("
                                *,
                                ( 6371 * acos(
                                    cos(radians(?)) *
                                    cos(radians(latitude)) *
                                    cos(radians(longitude) - radians(?)) +
                                    sin(radians(?)) *
                                    sin(radians(latitude))
                                )) AS distance_km
                            ", [$lat, $lon, $lat])
                            ->having('distance_km', '<=', $radius)
                            ->orderBy('distance_km')
                            ->limit(50)
                            ->get()
                            ->map(function ($catch) {
                                $catch->media_url = $catch->getFirstMediaUrl('catch_media');
                                return $catch;
                            });

        return response()->json([
            'center'    => ['latitude' => $lat, 'longitude' => $lon],
            'radius_km' => $radius,
            'catches'   => $catches,
        ]);
    }

    /**
     * Anglers active near a map point (from geolocated catches + spots they pinned).
     * Returns pins with angler names for map labels / callouts.
     */
    public function nearbyAnglers(Request $request)
    {
        $request->validate([
            'latitude' => 'required|numeric',
            'longitude' => 'required|numeric',
            'radius_km' => 'nullable|numeric|min:1|max:100',
        ]);

        $lat = (float) $request->latitude;
        $lon = (float) $request->longitude;
        $radius = (float) ($request->radius_km ?? 25);
        $viewerId = $request->user()?->id;

        $distanceKm = function (float $lat1, float $lon1, float $lat2, float $lon2): float {
            $earth = 6371;
            $dLat = deg2rad($lat2 - $lat1);
            $dLon = deg2rad($lon2 - $lon1);
            $a = sin($dLat / 2) ** 2
                + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;

            return $earth * (2 * atan2(sqrt($a), sqrt(1 - $a)));
        };

        $pinMap = [];
        $collect = function ($userId, $plat, $plon) use (&$pinMap, $lat, $lon, $radius, $distanceKm) {
            $uid = (int) $userId;
            if ($uid < 1 || $plat === null || $plon === null) {
                return;
            }
            $dist = $distanceKm($lat, $lon, (float) $plat, (float) $plon);
            if ($dist > $radius) {
                return;
            }
            if (! isset($pinMap[$uid]) || $dist < $pinMap[$uid]['distance_km']) {
                $pinMap[$uid] = [
                    'user_id' => $uid,
                    'latitude' => (float) $plat,
                    'longitude' => (float) $plon,
                    'distance_km' => round($dist, 2),
                ];
            }
        };

        FishCatch::query()
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->latest()
            ->limit(400)
            ->get(['user_id', 'latitude', 'longitude'])
            ->each(fn ($row) => $collect($row->user_id, $row->latitude, $row->longitude));

        FishingSpot::query()
            ->latest()
            ->limit(400)
            ->get(['user_id', 'latitude', 'longitude'])
            ->each(fn ($row) => $collect($row->user_id, $row->latitude, $row->longitude));

        $users = User::whereIn('id', array_keys($pinMap))
            ->withCount(['followers', 'catches'])
            ->get()
            ->keyBy('id');

        $anglers = collect($pinMap)
            ->map(function ($pin) use ($users, $viewerId) {
                $user = $users->get($pin['user_id']);
                if (! $user) {
                    return null;
                }

                return array_merge(AnglerRanker::format($user, $viewerId), [
                    'latitude' => $pin['latitude'],
                    'longitude' => $pin['longitude'],
                    'distance_km' => $pin['distance_km'],
                ]);
            })
            ->filter()
            ->sortBy('distance_km')
            ->values();

        return response()->json([
            'center' => ['latitude' => $lat, 'longitude' => $lon],
            'radius_km' => $radius,
            'anglers' => $anglers,
        ]);
    }

    // SAVE A SPOT TO FAVORITES
    public function saveSpot(Request $request, $spotId)
    {
        $already = SavedSpot::where('user_id', $request->user()->id)
                            ->where('fishing_spot_id', $spotId)
                            ->exists();

        if ($already) {
            return response()->json([
                'message' => 'Spot already saved.',
            ], 400);
        }

        SavedSpot::create([
            'user_id'         => $request->user()->id,
            'fishing_spot_id' => $spotId,
        ]);

        return response()->json([
            'message' => 'Spot saved to favorites!',
        ]);
    }

    // UNSAVE A SPOT FROM FAVORITES
    public function unsaveSpot(Request $request, $spotId)
    {
        SavedSpot::where('user_id', $request->user()->id)
                 ->where('fishing_spot_id', $spotId)
                 ->delete();

        return response()->json([
            'message' => 'Spot removed from favorites.',
        ]);
    }

    // GET MY SAVED SPOTS
    public function mySavedSpots(Request $request)
    {
        $spots = FishingSpot::whereHas('savedBy', function ($q) use ($request) {
                                $q->where('user_id', $request->user()->id);
                            })
                            ->with(['user' => function ($q) {
                                $q->select('id', 'name', 'profile_picture');
                            }])
                            ->get();

        return response()->json($spots);
    }
}