<?php

namespace App\Domain\Risk\Signals;

use App\Domain\Risk\DTOs\RiskContext;
use App\Domain\Risk\DTOs\SignalHit;
use App\Models\RiskAssessment;

/**
 * One IP driving many distinct accounts inside a window: a bulk-account / carding
 * indicator. Read from the engine's own risk_assessments store (self-building),
 * counting distinct users seen from this IP. Fires when that count EXCEEDS the
 * configured maximum. Weaker than DEVICE_SHARED alone (IPs are shared by NATs and
 * proxies), so it scores lower and leans on combination with other signals.
 */
class IpSharedSignal extends AbstractRiskSignal
{
    public function code(): string
    {
        return 'IP_SHARED';
    }

    public function evaluate(RiskContext $context): ?SignalHit
    {
        if ($context->ip === null) {
            return null;
        }

        $max = (int) $this->threshold('ip_shared_max_accounts', 2);
        $windowHours = (int) $this->threshold('ip_shared_window_hours', 24);

        $accounts = (int) RiskAssessment::query()
            ->where('ip_address', $context->ip)
            ->whereNotNull('user_id')
            ->where('created_at', '>=', now()->subHours($windowHours))
            ->distinct()
            ->count('user_id');

        if ($accounts > $max) {
            return $this->hit(['accounts' => $accounts, 'max' => $max, 'window_hours' => $windowHours]);
        }

        return null;
    }
}
