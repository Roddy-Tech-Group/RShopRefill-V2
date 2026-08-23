<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Escalating cooldown for verification-email resends, per user: the wait grows
 * with every send (30s -> 1m -> 2m -> 5m, then 10m for every send after that),
 * which is the standard progressive-backoff pattern for "didn't get the email?"
 * buttons. The schedule is SERVER-enforced; the UI countdown merely mirrors it.
 *
 * The counter remembers for 24h, so a user who walks away and comes back the
 * next day starts fresh at 30s. This complements (does not replace) the flat
 * verify-resend RateLimiter budget the middleware and auth modal share.
 */
class VerificationResendThrottle
{
    /** Send number => seconds until the NEXT send is allowed. */
    private const SCHEDULE = [1 => 30, 2 => 60, 3 => 120, 4 => 300];

    /** Every send beyond the schedule waits this long. */
    private const MAX_WAIT_SECONDS = 600;

    /** How long the escalation counter is remembered. */
    private const TTL_SECONDS = 86400;

    /** Flat spam ceiling shared by every sender (middleware, endpoint, gates). */
    private const FLAT_MAX_SENDS = 5;

    private const FLAT_DECAY_SECONDS = 600;

    /**
     * The ONE decision point for sending a verification email: checks the
     * escalating schedule AND the flat budget, sends when both allow, and
     * advances the schedule. Every surface (blocked checkout, blocked funding,
     * the Resend button) goes through here so their countdowns can never drift.
     *
     * @return array{sent: bool, retry_after: int, reason: string}
     *                                                             reason: 'sent' | 'schedule' (escalation window) | 'budget' (flat cap)
     */
    public function attemptSend(User $user): array
    {
        $remaining = $this->remainingSeconds($user->getKey());

        if ($remaining > 0) {
            return ['sent' => false, 'retry_after' => $remaining, 'reason' => 'schedule'];
        }

        $flatKey = 'verify-resend|'.$user->getKey();

        if (RateLimiter::tooManyAttempts($flatKey, self::FLAT_MAX_SENDS)) {
            return ['sent' => false, 'retry_after' => RateLimiter::availableIn($flatKey), 'reason' => 'budget'];
        }

        RateLimiter::hit($flatKey, self::FLAT_DECAY_SECONDS);
        $user->sendEmailVerificationNotification();

        return ['sent' => true, 'retry_after' => $this->recordSend($user->getKey()), 'reason' => 'sent'];
    }

    /**
     * Record a send and return the cooldown (seconds) before the next one.
     */
    public function recordSend(int $userId): int
    {
        $state = Cache::get($this->key($userId), ['count' => 0]);
        $count = (int) ($state['count'] ?? 0) + 1;
        $wait = self::SCHEDULE[$count] ?? self::MAX_WAIT_SECONDS;

        Cache::put($this->key($userId), [
            'count' => $count,
            'available_at' => now()->timestamp + $wait,
        ], self::TTL_SECONDS);

        return $wait;
    }

    /**
     * Seconds until the next send is allowed (0 = allowed now).
     */
    public function remainingSeconds(int $userId): int
    {
        $state = Cache::get($this->key($userId));

        if (! is_array($state)) {
            return 0;
        }

        return max(0, (int) ($state['available_at'] ?? 0) - now()->timestamp);
    }

    private function key(int $userId): string
    {
        return 'verify-resend-schedule|'.$userId;
    }
}
