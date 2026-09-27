<?php

namespace App\Domain\Payment\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * A gateway's "slow down", remembered across requests and workers.
 *
 * The gateway publishes no numeric limit; it answers a burst with HTTP 429
 * and asks for a graceful back-off. A single request can back off on its
 * own, but the sweepers that verify stale sessions in bulk would keep
 * hammering from the next session, so the 429 is noted here and every bulk
 * caller stands down for the cool-down. Customer-facing calls carry on: one
 * customer's payment is worth a 429 far more than a sweep is.
 */
final class GatewayRateLimit
{
    private const DEFAULT_COOLDOWN_SECONDS = 60;

    private const MAX_COOLDOWN_SECONDS = 600;

    /**
     * Record a 429. Honours a numeric Retry-After, else the default. Returns
     * the cool-down in seconds.
     */
    public static function noteRateLimited(string $gateway, mixed $retryAfter = null): int
    {
        $seconds = is_numeric($retryAfter) ? (int) $retryAfter : self::DEFAULT_COOLDOWN_SECONDS;
        $seconds = max(1, min($seconds, self::MAX_COOLDOWN_SECONDS));

        Cache::put(self::key($gateway), now()->addSeconds($seconds)->getTimestamp(), $seconds);

        Log::warning('Gateway asked us to slow down; bulk callers stand down for the cool-down.', [
            'gateway' => $gateway,
            'cooldown_seconds' => $seconds,
        ]);

        return $seconds;
    }

    public static function isCoolingDown(string $gateway): bool
    {
        return self::secondsRemaining($gateway) > 0;
    }

    public static function secondsRemaining(string $gateway): int
    {
        $until = (int) Cache::get(self::key($gateway), 0);

        return max(0, $until - now()->getTimestamp());
    }

    public static function clear(string $gateway): void
    {
        Cache::forget(self::key($gateway));
    }

    private static function key(string $gateway): string
    {
        return 'gateway:rate-limited:'.strtolower(trim($gateway));
    }
}
