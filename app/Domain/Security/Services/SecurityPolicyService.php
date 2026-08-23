<?php

namespace App\Domain\Security\Services;

use App\Models\TrustedDevice;
use App\Support\FeatureFlag;

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

    public function canWithdraw(TrustedDevice $device): bool
    {
        return $this->isDeviceMature($device);
    }

    public function canRemoveDevice(TrustedDevice $device): bool
    {
        return $this->isDeviceMature($device);
    }

    public function canResetPin(TrustedDevice $device): bool
    {
        return $this->isDeviceMature($device);
    }

    public function canDisable2FA(TrustedDevice $device): bool
    {
        return $this->isDeviceMature($device);
    }
}
