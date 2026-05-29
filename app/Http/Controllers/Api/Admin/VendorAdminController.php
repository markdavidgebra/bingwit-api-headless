<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Vendor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class VendorAdminController extends Controller
{
    // LIST ALL VENDORS
    public function index()
    {
        $vendors = Vendor::withCount('products')
                         ->latest()
                         ->get();

        return response()->json($vendors);
    }

    // CREATE A NEW VENDOR ACCOUNT (with credentials)
    public function storeVendor(Request $request)
    {
        $request->validate([
            'name'       => 'required|string|max:255',
            'email'      => 'required|email|unique:vendors,email',
            'password'   => 'required|string|min:8',
            'store_name' => 'required|string|max:255',
        ]);

        $vendor = Vendor::create([
            'name'       => $request->name,
            'email'      => $request->email,
            'password'   => Hash::make($request->password),
            'store_name' => $request->store_name,
            'store_slug' => Str::slug($request->store_name) . '-' . Str::random(6),
            'is_active'  => true,
        ]);

        return response()->json([
            'message' => 'Vendor created successfully!',
            'vendor'  => $vendor,
        ], 201);
    }

    // REMOVE / DEACTIVATE VENDOR
    public function removeVendor(Request $request, $vendorId)
    {
        $vendor = Vendor::findOrFail($vendorId);
        $vendor->update(['is_active' => false]);

        return response()->json([
            'message' => 'Vendor deactivated.',
        ]);
    }

    // VERIFY A VENDOR STORE
    public function verifyStore($vendorId)
    {
        $vendor = Vendor::findOrFail($vendorId);
        $vendor->update(['is_verified' => ! $vendor->is_verified]);

        return response()->json([
            'message'     => $vendor->is_verified
                ? 'Store verified!'
                : 'Store unverified.',
            'is_verified' => $vendor->is_verified,
        ]);
    }
}
