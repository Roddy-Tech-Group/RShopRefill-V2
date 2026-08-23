<?php

namespace App\Domain\Risk\Signals;

use App\Domain\Risk\DTOs\RiskContext;
use App\Domain\Risk\DTOs\SignalHit;
use App\Models\RiskAssessment;

/**
 * Many assessments from one device fingerprint in the last hour. Harder to spoof
 * than an IP, so a strong abuse indicator. Same self-building velocity store as
 * VELOCITY_IP_1H (prior risk_assessments rows for this device_uuid).
 */
class VelocityDevice1hSignal extends AbstractRiskSignal
{
    public function code(): string
    {
        return 'VELOCITY_DEVICE_1H';
    }

    public function evaluate(RiskContext $context): ?SignalHit
    {
        if ($context->deviceUuid === null) {
            return null;
        }

        $max = (int) $this->threshold('velocity_device_1h_max', 8);
        $count = RiskAssessment::query()
            ->where('device_uuid', $context->deviceUuid)
            ->where('created_at', '>=', now()->subHour())
            ->count();

        if ($count > $max) {
            return $this->hit(['count' => $count, 'window_hours' => 1, 'max' => $max]);
        }

        return null;
    }
}
