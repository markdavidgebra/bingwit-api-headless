<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

class OptionalBearerUser
{
    public static function id(Request $request): ?int
    {
        $user = $request->user();
        if ($user instanceof User) {
            return $user->id;
        }

        $token = $request->bearerToken();
        if (! $token) {
            return null;
        }

        $accessToken = PersonalAccessToken::findToken($token);
        if (! $accessToken || ! ($accessToken->tokenable instanceof User)) {
            return null;
        }

        return $accessToken->tokenable->id;
    }
}
