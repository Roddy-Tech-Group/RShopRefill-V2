<?php

namespace App\Livewire\Traits;

use App\Domain\Security\Services\SecurityPolicyService;
use App\Support\FeatureFlag;
use Illuminate\Validation\ValidationException;

trait EnforcesDeviceTrust
{
    /**
     * Enforces the 24-hour hold on new devices for sensitive actions.
     * Throws a validation exception attached to the specified field if the device is not mature.
     */
    protected function enforceDeviceTrust(string $errorField = 'trust'): void
    {
        if (FeatureFlag::off('device_management')) {
            return;
        }

        $laravelSessionId = session()->getId();
        $userSession = \App\Models\UserSession::where('laravel_session_id', $laravelSessionId)->first();

        if ($userSession && $userSession->device) {
            $device = $userSession->device;

            if (!app(SecurityPolicyService::class)->isDeviceMature($device)) {
                $hours = config('security.device_restriction_hours', 24);
                
                throw ValidationException::withMessages([
                    $errorField => "For your security, this action is restricted on new devices for the first {$hours} hours."
                ]);
            }
        }
    }
}
