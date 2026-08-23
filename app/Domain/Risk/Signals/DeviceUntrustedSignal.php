<?php

namespace App\Domain\Risk\Signals;

use App\Domain\Risk\DTOs\RiskContext;
use App\Domain\Risk\DTOs\SignalHit;
use App\Models\TrustedDevice;

/**
 * The action comes from a device this user has not established trust on (no
 * matching TrustedDevice, or one that has never been marked trusted). New/
 * untrusted devices carry elevated account-takeover and abuse risk. Requires
 * both a known user and a device fingerprint to judge; absent either, it abstains
 * rather than guessing.
 */
class DeviceUntrustedSignal extends AbstractRiskSignal
{
    public function code(): string
    {
        return 'DEVICE_UNTRUSTED';
    }

    public function evaluate(RiskContext $context): ?SignalHit
    {
        $user = $context->user;

        if ($user === null || $context->deviceUuid === null) {
            return null;
        }

        $device = TrustedDevice::query()
            ->where('user_id', $user->getKey())
            ->where('device_uuid', $context->deviceUuid)
            ->first();

        if ($device === null) {
            return $this->hit(['reason' => 'unknown_device']);
        }

        if (! $device->isTrusted()) {
            return $this->hit(['reason' => 'device_not_trusted']);
        }

        return null;
    }
}
