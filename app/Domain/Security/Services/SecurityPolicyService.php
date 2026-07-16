<?php

namespace App\Domain\Security\Services;

use App\Models\TrustedDevice;
use Illuminate\Support\Carbon;

class SecurityPolicyService
{
    /**
     * Checks if a device has passed the configurable restriction window.
     */
    public function isDeviceMature(TrustedDevice $device): bool
    {
        if (!$device->isTrusted()) {
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

    public function canCreateVirtualCard(TrustedDevice $device): bool
    {
        return $this->isDeviceMature($device);
    }
    
    public function canDisable2FA(TrustedDevice $device): bool
    {
        return $this->isDeviceMature($device);
    }
}
