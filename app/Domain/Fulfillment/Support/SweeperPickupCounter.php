<?php

namespace App\Domain\Fulfillment\Support;

use Illuminate\Support\Facades\Cache;

/**
 * Counts rescue-sweeper pickups in hourly cache buckets so Monitoring and
 * monitor:operations can report them. Every pickup is a paid order whose
 * fulfillment never started, which means a provider webhook went missing —
 * the count should be zero, and a spike means a provider's webhooks are
 * failing before any customer has complained.
 */
class SweeperPickupCounter
{
    private const KEY_PREFIX = 'ops:sweeper-pickups:';

    /** Buckets live one hour past the 24h reporting window. */
    private const TTL_HOURS = 25;

    public static function record(int $pickups): void
    {
        if ($pickups <= 0) {
            return;
        }

        $key = self::KEY_PREFIX.now()->format('YmdH');

        // add() seeds the bucket with a TTL; increment() alone would create a
        // counter that never expires on some cache drivers.
        Cache::add($key, 0, now()->addHours(self::TTL_HOURS));
        Cache::increment($key, $pickups);
    }

    public static function lastHours(int $hours): int
    {
        $total = 0;
        $cursor = now();

        for ($i = 0; $i < $hours; $i++) {
            $total += (int) Cache::get(self::KEY_PREFIX.$cursor->format('YmdH'), 0);
            $cursor = $cursor->subHour();
        }

        return $total;
    }
}
