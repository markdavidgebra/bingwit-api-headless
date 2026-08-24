<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AdminProfileController extends Controller
{
    // GET CURRENT ADMIN
    public function me(Request $request)
    {
        return response()->json([
            'account' => $request->user()->withStaffPermissions(),
        ]);
    }

    // UPDATE CURRENT ADMIN'S OWN PROFILE
    // Name + email are always allowed.
    // Password change requires `current_password` AND a new
    // `password` (with `password_confirmation`).
    public function update(Request $request)
    {
        $admin = $request->user();

        $data = $request->validate([
            'name'  => 'sometimes|required|string|max:255',
            'email' => [
                'sometimes',
                'required',
                'email',
                'max:255',
                Rule::unique('admins', 'email')->ignore($admin->id),
            ],
            'current_password' => 'required_with:password|string',
            'password'         => 'sometimes|nullable|string|min:8|confirmed',
        ]);

        if (!empty($data['password'])) {
            if (!Hash::check($data['current_password'], $admin->password)) {
                throw ValidationException::withMessages([
                    'current_password' => ['Current password is incorrect.'],
                ]);
            }
            $admin->password = Hash::make($data['password']);
        }

        if (array_key_exists('name', $data)) {
            $admin->name = $data['name'];
        }
        if (array_key_exists('email', $data)) {
            $admin->email = $data['email'];
        }

        $admin->save();

        return response()->json([
            'message' => 'Profile updated successfully!',
            'account' => $admin->fresh()->withStaffPermissions(),
        ]);
    }

    // UPLOAD / REPLACE PROFILE PICTURE
    public function uploadPhoto(Request $request)
    {
        $request->validate([
            'photo' => 'required|image|mimes:jpg,jpeg,png,webp|max:4096',
        ]);

        $admin = $request->user();

        // Remove the previous picture if it was a stored file.
        if (
            $admin->profile_picture &&
            ! preg_match('#^https?://#i', $admin->profile_picture) &&
            Storage::disk('public')->exists($admin->profile_picture)
        ) {
            Storage::disk('public')->delete($admin->profile_picture);
        }

        $file     = $request->file('photo');
        $filename = 'admin-' . $admin->id . '-' . Str::random(12)
                  . '.' . $file->getClientOriginalExtension();

        $path = $file->storeAs('admin-profiles', $filename, 'public');

        $admin->profile_picture = $path;
        $admin->save();

        return response()->json([
            'message' => 'Profile picture updated!',
            'account' => $admin->fresh()->withStaffPermissions(),
        ]);
    }
}
