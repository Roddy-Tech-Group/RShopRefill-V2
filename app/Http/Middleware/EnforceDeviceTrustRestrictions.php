<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use App\Models\TrustedDevice;
use App\Domain\Security\Services\SecurityPolicyService;
use Symfony\Component\HttpFoundation\Response;

class EnforceDeviceTrustRestrictions
{
    public function __construct(
        private SecurityPolicyService $policyService
    ) {}

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next, string $actionType = 'generic'): Response
    {
        if (!$request->user()) {
            return $next($request);
        }

        if (\App\Support\FeatureFlag::off('device_management')) {
            return $next($request);
        }

        // We assume the device ID is stored in the session or can be inferred
        // Since we created the user_sessions overlay, we could get the device from there.
        // But for simplicity during this request lifecycle, we'll check the Trusted_Devices table based on current session
        
        $laravelSessionId = session()->getId();
        $userSession = \App\Models\UserSession::where('laravel_session_id', $laravelSessionId)->first();

        if ($userSession && $userSession->device) {
            $device = $userSession->device;

            if (!$this->policyService->isDeviceMature($device)) {
                $hours = config('security.device_restriction_hours', 24);
                
                // Return a 403 response with a specific message so the frontend can handle it
                if ($request->expectsJson()) {
                    return response()->json([
                        'message' => "This action is restricted on new devices for the first {$hours} hours."
                    ], 403);
                }

                abort(403, "This action is restricted on new devices for the first {$hours} hours to protect your funds.");
            }
        }

        return $next($request);
    }
}
