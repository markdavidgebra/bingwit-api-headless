<?php

namespace App\Http\Middleware;

use App\Models\Admin;
use App\Models\Vendor;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureVendor
{
    /**
     * Allow vendors to act as vendors. Admins are also allowed
     * because they can log in to vendor and user contexts.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! ($user instanceof Vendor || $user instanceof Admin)) {
            return response()->json([
                'message' => 'Vendor access required.',
            ], 403);
        }

        return $next($request);
    }
}
