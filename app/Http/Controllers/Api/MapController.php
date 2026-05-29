<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FishingSpot;
use App\Models\SavedSpot;
use App\Models\FishCatch;
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