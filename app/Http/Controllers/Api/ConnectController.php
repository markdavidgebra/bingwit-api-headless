<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FishingBoat;
use App\Models\FishingSpot;
use App\Models\Resort;
use App\Models\User;
use App\Support\AnglerRanker;
use Illuminate\Http\Request;

class ConnectController extends Controller
{
    /**
     * Localized Connect search: anglers, pin authors, resorts, boats.
     */
    public function search(Request $request)
    {
        $request->validate([
            'q' => 'nullable|string|max:100',
            'location' => 'nullable|string|max:100',
            'latitude' => 'nullable|numeric',
            'longitude' => 'nullable|numeric',
            'radius_km' => 'nullable|numeric|min:1|max:100',
        ]);

        $q = trim((string) $request->query('q', ''));
        $location = trim((string) $request->query('location', $q));
        $viewerId = $request->user()?->id;
        $place = $location !== '' ? $location : $q;

        // Anglers by profile location / name
        $anglerQuery = User::query();
        if ($q !== '') {
            $term = '%' . $q . '%';
            $anglerQuery->where(function ($builder) use ($term) {
                $builder->where('name', 'like', $term)
                    ->orWhere('location', 'like', $term)
                    ->orWhere('fishing_style', 'like', $term);
            });
        }
        if ($place !== '' && $place !== $q) {
            $anglerQuery->where('location', 'like', '%' . $place . '%');
        }
        AnglerRanker::applyRanking($anglerQuery, $place !== '' ? $place : null);
        $anglers = AnglerRanker::formatMany($anglerQuery->limit(20)->get(), $viewerId);

        // Spots / pins in the area
        $spotsQuery = FishingSpot::with(['user:id,name,profile_picture']);
        if ($place !== '') {
            $spotsQuery->where(function ($builder) use ($place) {
                $builder->where('name', 'like', '%' . $place . '%')
                    ->orWhere('description', 'like', '%' . $place . '%');
            });
        }
        $spots = $spotsQuery->latest()->limit(20)->get();

        // Resorts (h)
        $resortsQuery = Resort::with('municipality')->where('is_active', true);
        if ($place !== '') {
            $resortsQuery->where(function ($builder) use ($place) {
                $builder->where('name', 'like', '%' . $place . '%')
                    ->orWhere('location', 'like', '%' . $place . '%');
            });
        }
        $resorts = $resortsQuery->orderByDesc('is_verified')->orderByDesc('rating')->limit(15)->get();

        // Boats (i)
        $boatsQuery = FishingBoat::query()->where('status', 'available');
        if ($place !== '') {
            $boatsQuery->where(function ($builder) use ($place) {
                $builder->where('name', 'like', '%' . $place . '%')
                    ->orWhere('location', 'like', '%' . $place . '%')
                    ->orWhere('departure_point', 'like', '%' . $place . '%');
            });
        }
        $boats = $boatsQuery->latest()->limit(15)->get();

        return response()->json([
            'query' => ['q' => $q, 'location' => $place],
            'anglers' => $anglers,
            'spots' => $spots,
            'resorts' => $resorts,
            'boats' => $boats,
        ]);
    }
}
