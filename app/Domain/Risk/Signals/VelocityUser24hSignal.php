<?php

namespace App\Domain\Risk\Signals;

use App\Domain\Risk\DTOs\RiskContext;
use App\Domain\Risk\DTOs\SignalHit;

/**
 * The user has placed an unusual number of orders in the last 24 hours. The
 * daily companion to VELOCITY_USER_1H, catching sustained abuse that stays under
 * the hourly bar. Fires when the count EXCEEDS the configured max.
 */
class VelocityUser24hSignal extends AbstractRiskSignal
{
    public function code(): string
    {
        return 'VELOCITY_USER_24H';
    }

    public function evaluate(RiskContext $context): ?SignalHit
    {
        $user = $context->user;

        if ($user === null) {
            return null;
        }

        $max = (int) $this->threshold('velocity_user_24h_max', 20);
        $count = $user->orders()->where('created_at', '>=', now()->subDay())->count();

        if ($count > $max) {
            return $this->hit(['count' => $count, 'window_hours' => 24, 'max' => $max]);
        }

        return null;
    }
}
