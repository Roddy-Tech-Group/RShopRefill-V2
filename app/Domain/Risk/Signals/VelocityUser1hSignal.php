<?php

namespace App\Domain\Risk\Signals;

use App\Domain\Risk\DTOs\RiskContext;
use App\Domain\Risk\DTOs\SignalHit;

/**
 * The user has placed an unusual number of orders in the last hour. Read from
 * the orders table (persisted, deterministic) rather than a cache counter, so it
 * is reproducible and cannot be reset by cache eviction. Fires when the count
 * EXCEEDS the configured max.
 */
class VelocityUser1hSignal extends AbstractRiskSignal
{
    public function code(): string
    {
        return 'VELOCITY_USER_1H';
    }

    public function evaluate(RiskContext $context): ?SignalHit
    {
        $user = $context->user;

        if ($user === null) {
            return null;
        }

        $max = (int) $this->threshold('velocity_user_1h_max', 6);
        $count = $user->orders()->where('created_at', '>=', now()->subHour())->count();

        if ($count > $max) {
            return $this->hit(['count' => $count, 'window_hours' => 1, 'max' => $max]);
        }

        return null;
    }
}
