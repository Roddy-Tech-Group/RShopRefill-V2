<?php

namespace App\Domain\Risk\Signals;

use App\Domain\Risk\DTOs\RiskContext;
use App\Domain\Risk\DTOs\SignalHit;
use App\Models\RiskAssessment;

/**
 * Many assessments from one IP in the last hour: the carding-attack shape (a
 * single origin driving many attempts, often across accounts). Read from the
 * engine's own risk_assessments store, which unifies the velocity window across
 * every money action instead of the old per-service cache counters. Prior rows
 * only (the current assessment is persisted after scoring), so no off-by-one.
 */
class VelocityIp1hSignal extends AbstractRiskSignal
{
    public function code(): string
    {
        return 'VELOCITY_IP_1H';
    }

    public function evaluate(RiskContext $context): ?SignalHit
    {
        if ($context->ip === null) {
            return null;
        }

        $max = (int) $this->threshold('velocity_ip_1h_max', 10);
        $count = RiskAssessment::query()
            ->where('ip_address', $context->ip)
            ->where('created_at', '>=', now()->subHour())
            ->count();

        if ($count > $max) {
            return $this->hit(['count' => $count, 'window_hours' => 1, 'max' => $max]);
        }

        return null;
    }
}
