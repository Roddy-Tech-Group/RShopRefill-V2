<?php

namespace App\Domain\Risk\Signals;

use App\Domain\Risk\DTOs\RiskContext;
use App\Domain\Risk\DTOs\SignalHit;
use App\Models\TrustedDevice;

/**
 * One device fingerprint tied to many distinct accounts: the core multi-account
 * abuse shape, weighted heavily for gift cards. Built from our own device
 * registry (no third-party de-dup subscription), fires when the number of
 * distinct accounts on this device EXCEEDS the configured maximum.
 */
class DeviceSharedSignal extends AbstractRiskSignal
{
    public function code(): string
    {
        return 'DEVICE_SHARED';
    }

    public function evaluate(RiskContext $context): ?SignalHit
    {
        if ($context->deviceUuid === null) {
            return null;
        }

        $max = (int) $this->threshold('device_shared_max_accounts', 1);

        $accounts = (int) TrustedDevice::query()
            ->where('device_uuid', $context->deviceUuid)
            ->distinct()
            ->count('user_id');

        if ($accounts > $max) {
            return $this->hit(['accounts' => $accounts, 'max' => $max]);
        }

        return null;
    }
}
