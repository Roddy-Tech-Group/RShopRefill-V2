<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnforceDeviceVerification
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        // If the user is logged in, but their current session is flagged as pending verification
        if ($request->user() && session('pending_email_otp')) {
            // Allow access to the verification routes and logout
            if ($request->routeIs('security.verify-device*') || $request->routeIs('logout')) {
                return $next($request);
            }

            // Redirect all other requests to the verification page
            return redirect()->route('security.verify-device');
        }

        return $next($request);
    }
}
