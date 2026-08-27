<?php

namespace App\Domain\Communication\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use RuntimeException;

/**
 * Global throughput gate in front of the Resend API.
 *
 * Resend allows 10 requests/second per account. The per-recipient
 * RateLimiter only protects one user from being spammed; it says nothing
 * about how many workers hit Resend at once. During a newsletter blast ten
 * Horizon workers each fire instantly and the account gets 429s
 * (Sentry PHP-LARAVEL-29, 2026-08-26: 54 rate-limited sends in 40s).
 *
 * The gate is shared across every worker through the cache store (Redis in
 * production), so total throughput stays under the ceiling regardless of
 * process count. A worker that cannot get a slot waits in place for one.
 * Waiting beats releasing the job: a released job counts as an attempt and
 * would burn through $tries during a large blast.
 */
class ResendApiThrottle
{
    private const KEY = 'resend-api-requests';

    private const POLL_INTERVAL_MICROSECONDS = 50_000;

    public function __construct(
        private readonly int $maxPerSecond,
        private readonly int $maxWaitMilliseconds,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            maxPerSecond: max(1, (int) config('services.resend.max_requests_per_second', 8)),
            maxWaitMilliseconds: max(0, (int) config('services.resend.throttle_wait_ms', 5000)),
        );
    }

    /**
     * Block until a request slot is available in the current one-second window.
     *
     * @throws RuntimeException when no slot opened within the wait budget
     */
    public function acquire(): void
    {
        $deadline = hrtime(true) + $this->maxWaitMilliseconds * 1_000_000;

        do {
            try {
                $acquired = RateLimiter::attempt(self::KEY, $this->maxPerSecond, fn () => true, 1);
            } catch (\Throwable $e) {
                // The gate must never be the reason an email does not go out.
                // If the cache store is unreachable, send without it and
                // let Resend's own 429 (retried in the provider) be the limit.
                Log::warning('Resend API throttle unavailable, sending without it', [
                    'exception' => get_class($e),
                    'message' => $e->getMessage(),
                ]);

                return;
            }

            if ($acquired) {
                return;
            }

            if (hrtime(true) >= $deadline) {
                break;
            }

            usleep(self::POLL_INTERVAL_MICROSECONDS);
        } while (true);

        throw new RuntimeException(
            "Resend API throttle: no slot within {$this->maxWaitMilliseconds}ms (limit {$this->maxPerSecond}/s)."
        );
    }
}
