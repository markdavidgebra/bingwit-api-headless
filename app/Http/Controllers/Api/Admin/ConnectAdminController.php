<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Municipality;
use App\Models\Resort;
use Illuminate\Http\Request;

class ConnectAdminController extends Controller
{
    public function municipalities()
    {
        return response()->json([
            'municipalities' => Municipality::orderBy('province')->orderBy('name')->get(),
        ]);
    }

    public function storeMunicipality(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'province' => 'nullable|string|max:255',
            'region' => 'nullable|string|max:255',
            'is_active' => 'boolean',
            'is_verified' => 'boolean',
        ]);

        $row = Municipality::create($data);

        return response()->json(['message' => 'Municipality created.', 'municipality' => $row], 201);
    }

    public function updateMunicipality(Request $request, $id)
    {
        $row = Municipality::findOrFail($id);
        $data = $request->validate([
            'name' => 'sometimes|string|max:255',
            'province' => 'nullable|string|max:255',
            'region' => 'nullable|string|max:255',
            'is_active' => 'boolean',
            'is_verified' => 'boolean',
        ]);
        $row->update($data);

        return response()->json(['message' => 'Municipality updated.', 'municipality' => $row]);
    }

    public function resorts()
    {
        return response()->json([
            'resorts' => Resort::with('municipality')->orderBy('name')->get(),
        ]);
    }

    public function storeResort(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'location' => 'nullable|string|max:255',
            'municipality_id' => 'nullable|exists:municipalities,id',
            'latitude' => 'nullable|numeric',
            'longitude' => 'nullable|numeric',
            'contact_phone' => 'nullable|string|max:50',
            'contact_email' => 'nullable|email|max:255',
            'has_fishing_area' => 'boolean',
            'has_gear_rental' => 'boolean',
            'is_verified' => 'boolean',
            'is_active' => 'boolean',
        ]);

        $resort = Resort::create($data);

        return response()->json(['message' => 'Resort created.', 'resort' => $resort], 201);
    }

    public function updateResort(Request $request, $id)
    {
        $resort = Resort::findOrFail($id);
        $data = $request->validate([
            'name' => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'location' => 'nullable|string|max:255',
            'municipality_id' => 'nullable|exists:municipalities,id',
            'latitude' => 'nullable|numeric',
            'longitude' => 'nullable|numeric',
            'contact_phone' => 'nullable|string|max:50',
            'contact_email' => 'nullable|email|max:255',
            'has_fishing_area' => 'boolean',
            'has_gear_rental' => 'boolean',
            'is_verified' => 'boolean',
            'is_active' => 'boolean',
        ]);
        $resort->update($data);

        return response()->json(['message' => 'Resort updated.', 'resort' => $resort->fresh('municipality')]);
    }
}
