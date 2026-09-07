<?php

namespace App\Domain\Security\Services;

use App\Models\TrustedDevice;
use App\Support\FeatureFlag;

/**
 * The one device-maturity rule. Sensitive account mutations (PIN changes,
 * OTP preference, Rcoin withdrawal) are refused from a device trusted less
 * than the configured window ago; EnsureDeviceMature enforces it at the
 * route boundary and EnforcesDeviceTrust inside Livewire actions.
 */
class SecurityPolicyService
{
    /**
     * Checks if a device has passed the configurable restriction window.
     */
    public function isDeviceMature(TrustedDevice $device): bool
    {
        if (FeatureFlag::off('device_restriction')) {
            return true;
        }

        if (! $device->isTrusted()) {
            return false;
        }

        $restrictionHours = config('security.device_restriction_hours', 24);

        return $device->trusted_since->diffInHours(now()) >= $restrictionHours;
    }
}
