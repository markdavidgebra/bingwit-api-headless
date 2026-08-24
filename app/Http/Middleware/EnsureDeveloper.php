<?php

namespace App\Http\Middleware;

use App\Models\Admin;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureDeveloper
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof Admin || ! $user->isDeveloper()) {
            return response()->json([
                'message' => 'Developer access required.',
            ], 403);
        }

        return $next($request);
    }
}
