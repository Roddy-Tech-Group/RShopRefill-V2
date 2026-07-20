<?php

namespace App\Domain\Security\Services;

use App\Models\TrustedDevice;
use App\Models\User;
use Illuminate\Support\Str;
use Jenssegers\Agent\Agent;
use Stevebauman\Location\Facades\Location;

class DeviceTrustManager
{
    /**
     * Compute a hybrid fingerprint combining client-provided data and server-side headers.
     */
    public function computeFingerprint(string $installationId, string $userAgent, string $ipAddress): string
    {
        return hash('sha256', $installationId . '|' . $userAgent . '|' . $ipAddress);
    }

    /**
     * Registers a new device or updates an existing one.
     */
    public function registerOrUpdateDevice(User $user, array $deviceData, string $ipAddress, string $userAgent): TrustedDevice
    {
        $installationId = $deviceData['installation_id'] ?? Str::uuid()->toString();
        $fingerprint = $this->computeFingerprint($installationId, $userAgent, $ipAddress);

        $device = TrustedDevice::where('user_id', $user->id)
            ->where('device_uuid', $installationId)
            ->first();

        if (!$device) {
            $device = new TrustedDevice([
                'user_id' => $user->id,
                'device_uuid' => $installationId,
                'trust_score' => 20, // Default for new devices
            ]);
        }
        
        $device->fingerprint = $fingerprint;

        $agent = new Agent();
        $agent->setUserAgent($userAgent);
        
        $location = Location::get($ipAddress);
        
        $device->fill([
            'device_name' => $deviceData['device_name'] ?? $device->device_name,
            'browser' => $deviceData['browser'] ?? ($agent->browser() ?: null) ?? $device->browser,
            'os' => $deviceData['os'] ?? ($agent->platform() ?: null) ?? $device->os,
            'device_type' => $deviceData['device_type'] ?? ($agent->isDesktop() ? 'desktop' : ($agent->isTablet() ? 'tablet' : 'mobile')) ?? $device->device_type,
            'ip_address' => $ipAddress,
            'city' => $deviceData['city'] ?? ($location ? $location->cityName : $device->city),
            'country' => $deviceData['country'] ?? ($location ? $location->countryName : $device->country),
            'timezone' => $deviceData['timezone'] ?? ($location ? $location->timezone : $device->timezone),
            'user_agent' => $userAgent,
            'last_seen_at' => now(),
        ]);

        $device->save();

        return $device;
    }

    public function markAsTrusted(TrustedDevice $device): void
    {
        $device->trusted_since = now();
        $device->trust_score = 40; // Initial bump upon verification
        $device->save();
    }

    public function revokeDevice(TrustedDevice $device): void
    {
        $device->trusted_since = null;
        $device->trust_score = 0;
        $device->save();
    }
}
