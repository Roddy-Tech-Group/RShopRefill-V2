<?php

namespace App\Http\Middleware;

use App\Domain\Security\Services\DeviceTrustManager;
use App\Domain\Security\Services\SecurityPolicyService;
use App\Support\FeatureFlag;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The 24-hour hold on new devices, at the API boundary. The Livewire
 * security surfaces already enforce this through the EnforcesDeviceTrust
 * trait, but the raw endpoints they sit on (PIN change/reset/remove, device
 * revoke, OTP-preference change) accepted a scripted request from a
 * minutes-old device without question - the exact device an account thief
 * signs in from. Money OUT (the Rcoin withdrawal request) sits behind it
 * too. Ordering and paying are deliberately NOT behind this hold; only
 * actions that reshape the account's own security, or move money out, are.
 */
class EnsureDeviceMature
{
    public function __construct(
        private readonly DeviceTrustManager $devices,
        private readonly SecurityPolicyService $policy,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (FeatureFlag::off('device_management')) {
            return $next($request);
        }

        $user = $request->user();

        if (! $user) {
            return $next($request);
        }

        // Unresolvable device = a session predating device tracking. Failing
        // closed here would brick every legacy session at rollout; the hold
        // is a hardening layer on top of auth, not a replacement for it.
        $device = $this->devices->currentDeviceFor($user, $request);

        if ($device && ! $this->policy->isDeviceMature($device)) {
            $hours = config('security.device_restriction_hours', 24);
            $message = "For your security, this action is restricted on new devices for the first {$hours} hours.";

            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'message' => $message,
                    'device_restricted' => true,
                ], 403);
            }

            // A web form (the Rcoin withdrawal request) gets its error back
            // on the page it came from, not a JSON body.
            return back()->withErrors(['device' => $message]);
        }

        return $next($request);
    }
}
