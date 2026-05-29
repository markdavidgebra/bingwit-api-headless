<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    // REGISTER A NEW USER (angler)
    public function register(Request $request)
    {
        $request->validate([
            'name'          => 'required|string|max:255',
            'email'         => 'required|string|email|unique:users,email',
            'password'      => 'required|string|min:8|confirmed',
            'fishing_style' => 'nullable|string',
            'location'      => 'nullable|string',
        ]);

        $user = User::create([
            'name'          => $request->name,
            'email'         => $request->email,
            'password'      => Hash::make($request->password),
            'fishing_style' => $request->fishing_style,
            'location'      => $request->location,
        ]);

        $token = $user->createToken('bingwit-token')->plainTextToken;

        return response()->json([
            'message' => 'Account created successfully!',
            'role'    => 'user',
            'user'    => $user,
            'token'   => $token,
        ], 201);
    }

    // USER-SIDE LOGIN
    // Resolution order: users -> vendors -> admins
    // (User, Vendor, and Admin may all sign in here.)
    public function login(Request $request)
    {
        $request->validate([
            'email'    => 'required|email',
            'password' => 'required',
        ]);

        $account = $this->resolveAccount(
            $request->email,
            $request->password,
            [User::class, Vendor::class, Admin::class]
        );

        return $this->issueToken($account, 'bingwit-token');
    }

    // VENDOR-SIDE LOGIN
    // Resolution order: vendors -> admins
    // (Vendor and Admin may sign in here. Plain users cannot.)
    public function loginVendor(Request $request)
    {
        $request->validate([
            'email'    => 'required|email',
            'password' => 'required',
        ]);

        $account = $this->resolveAccount(
            $request->email,
            $request->password,
            [Vendor::class, Admin::class]
        );

        return $this->issueToken($account, 'bingwit-vendor-token');
    }

    // ADMIN-SIDE LOGIN
    // Only entries in the `admins` table may sign in here.
    public function loginAdmin(Request $request)
    {
        $request->validate([
            'email'    => 'required|email',
            'password' => 'required',
        ]);

        $account = $this->resolveAccount(
            $request->email,
            $request->password,
            [Admin::class]
        );

        return $this->issueToken($account, 'bingwit-admin-token');
    }

    // LOGOUT (works for any authenticated entity)
    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'message' => 'Logged out successfully!',
        ]);
    }

    // CURRENT AUTHENTICATED ENTITY (user / vendor / admin)
    public function me(Request $request)
    {
        return response()->json([
            'role'    => $this->roleFor($request->user()),
            'account' => $request->user(),
        ]);
    }

    /**
     * Walk the allowed model classes in order and return the first
     * one that matches the given credentials.
     */
    protected function resolveAccount(string $email, string $password, array $modelClasses)
    {
        foreach ($modelClasses as $modelClass) {
            $account = $modelClass::where('email', $email)->first();

            if ($account && Hash::check($password, $account->password)) {
                return $account;
            }
        }

        throw ValidationException::withMessages([
            'email' => ['These credentials do not match our records.'],
        ]);
    }

    /**
     * Issue a Sanctum token for an entity and shape the response.
     */
    protected function issueToken($account, string $tokenName)
    {
        $token = $account->createToken($tokenName)->plainTextToken;

        return response()->json([
            'message' => 'Logged in successfully!',
            'role'    => $this->roleFor($account),
            'account' => $account,
            'token'   => $token,
        ]);
    }

    protected function roleFor($account): string
    {
        return match (true) {
            $account instanceof Admin  => 'admin',
            $account instanceof Vendor => 'vendor',
            $account instanceof User   => 'user',
            default                    => 'unknown',
        };
    }
}
