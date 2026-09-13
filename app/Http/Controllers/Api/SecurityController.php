<?php

namespace App\Http\Controllers\Api;

use App\Domain\Security\Services\DeviceTrustManager;
use App\Domain\Security\Services\SecurityPolicyService;
use App\Domain\Security\Services\SessionRevocationService;
use App\Http\Controllers\Controller;
use App\Models\TrustedDevice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class SecurityController extends Controller
{
    /**
     * Sign a device out and withdraw its trust. Proving the account password
     * is the step-up that lets a customer on a brand-new device cut a stolen
     * one loose immediately, instead of waiting out the 24-hour new-device
     * hold (which used to leave the thief signed in for a day). Accounts
     * without a password (Google-only) keep the maturity hold, the only
     * proof of identity available to them.
     */
    public function revokeDevice(
        Request $request,
        TrustedDevice $device,
        SessionRevocationService $sessions,
        DeviceTrustManager $devices,
        SecurityPolicyService $policy,
    ): JsonResponse {
        $user = $request->user();

        if ($device->user_id !== $user->id) {
            abort(403, 'Unauthorized access to device.');
        }

        if ($user->password !== null) {
            $request->validate(['password' => ['required', 'string', 'current_password']]);
        } else {
            $current = $devices->currentDeviceFor($user, $request);

            if ($current && ! $policy->isDeviceMature($current)) {
                $hours = config('security.device_restriction_hours', 24);

                return response()->json([
                    'message' => "For your security, this action is restricted on new devices for the first {$hours} hours.",
                    'device_restricted' => true,
                ], 403);
            }
        }

        $sessions->revokeDevice($device);

        return response()->json(['message' => 'Device signed out and removed.']);
    }

    public function updatePreferences(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email_otp_preference' => 'required|in:never,new_device,always',
        ]);

        $user = Auth::user();
        $user->email_otp_preference = $data['email_otp_preference'];
        $user->save();

        return response()->json(['message' => 'Security preferences updated.']);
    }
}
