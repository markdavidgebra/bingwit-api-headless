<?php

namespace App\Http\Middleware;

use Illuminate\Auth\Middleware\Authenticate as Middleware;
use Illuminate\Http\Request;

class Authenticate extends Middleware
{
    protected function redirectTo(Request $request): ?string
    {
        // Return null for API requests instead of redirecting to login
        if ($request->expectsJson()) {
            return null;
        }

        return null;
    }
}