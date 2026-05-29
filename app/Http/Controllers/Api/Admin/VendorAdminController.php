<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
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

    // LIST USERS ELIGIBLE TO BECOME A VENDOR
    // Excludes users whose email already matches a Vendor row.
    public function eligibleUsers()
    {
        $vendorEmails = Vendor::pluck('email')->all();

        $users = User::select('id', 'name', 'email')
                     ->when(! empty($vendorEmails), fn ($q) =>
                         $q->whereNotIn('email', $vendorEmails)
                     )
                     ->orderBy('name')
                     ->get();

        return response()->json($users);
    }

    // PROMOTE AN EXISTING USER INTO A VENDOR
    // Copies the user's name, email, and password hash into a new
    // Vendor row so they can immediately sign in to the vendor portal
    // with the credentials they already use.
    public function assignVendor(Request $request)
    {
        $data = $request->validate([
            'user_id'    => 'required|exists:users,id',
            'store_name' => 'required|string|max:255',
        ]);

        $user = User::findOrFail($data['user_id']);

        if (Vendor::where('email', $user->email)->exists()) {
            return response()->json([
                'message' => 'This user is already a vendor.',
            ], 422);
        }

        $vendor = Vendor::create([
            'name'       => $user->name,
            'email'      => $user->email,
            // Reuse the existing hashed password so the vendor can log in
            // immediately with their current credentials. Disable the
            // hashed-cast write by passing the raw hash via setAttribute.
            'password'   => $user->getAuthPassword(),
            'store_name' => $data['store_name'],
            'store_slug' => Str::slug($data['store_name']) . '-' . Str::random(6),
            'is_active'  => true,
        ]);

        return response()->json([
            'message' => 'Vendor assigned successfully!',
            'vendor'  => $vendor,
        ], 201);
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

    // REMOVE VENDOR — permanently deletes the vendor, their products
    // (with images), and their store logo file from disk.
    public function removeVendor(Request $request, $vendorId)
    {
        $vendor = Vendor::findOrFail($vendorId);

        DB::transaction(function () use ($vendor) {
            // 1. Delete each product's image files, then the product rows.
            $vendor->products()->with('images')->get()->each(function ($product) {
                foreach ($product->images as $image) {
                    if (
                        $image->image_path &&
                        ! preg_match('#^https?://#i', $image->image_path) &&
                        Storage::disk('public')->exists($image->image_path)
                    ) {
                        Storage::disk('public')->delete($image->image_path);
                    }
                }
                $product->delete();
            });

            // 2. Remove the store logo from disk.
            if (
                $vendor->store_logo &&
                ! preg_match('#^https?://#i', $vendor->store_logo) &&
                Storage::disk('public')->exists($vendor->store_logo)
            ) {
                Storage::disk('public')->delete($vendor->store_logo);
            }

            // 3. Delete the vendor row itself.
            $vendor->delete();
        });

        return response()->json([
            'message' => 'Vendor removed.',
            'id'      => (int) $vendorId,
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
