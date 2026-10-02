<?php

namespace App\Domain\Security\Services;

use App\Domain\Security\Enums\OtpDispatchResult;
use App\Domain\Security\Enums\OtpVerificationResult;
use App\Domain\Security\Events\OtpRequested;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * The device-verification code: issuing it, resending it, checking it.
 *
 * Lifted out of DeviceVerificationController and EvaluateLoginRiskListener
 * unchanged, because the mobile app needs the identical behaviour and a second
 * copy of it would drift. There is exactly one set of cache keys, one code
 * lifetime, one escalating cooldown table and one guess budget, and both the
 * website and /v2 read them from here.
 *
 * Everything is keyed by USER, not by session, which is what makes it usable
 * from a stateless API at all.
 */
class DeviceOtpService
{
    /**
     * How long a code stays valid. The page's "expires in" clock, the guess
     * budget window and the cache lifetime all read this one number, so the
     * customer is never told a time the server does not enforce.
     */
    public const TTL_MINUTES = 10;

    /**
     * Cooldown before the next resend, by how many have been asked for in the
     * last 24 hours. Escalating, so a mistyped address or a mail loop cannot be
     * turned into a mailbox flood.
     */
    private const RESEND_DELAYS = [
        1 => 60,    // 1 min
        2 => 180,   // 3 mins
        3 => 300,   // 5 mins
        4 => 1800,  // 30 mins
        5 => 3600,  // 1 hour
    ];

    private const MAX_RESENDS = 5;

    /**
     * Wrong guesses allowed against one code. Caps brute force inside the
     * code's ten-minute life: five tries out of a million.
     */
    private const MAX_GUESSES = 5;

    /**
     * The first code of a login, sent automatically because the sign-in needed
     * verifying. Fixed one-minute cooldown and the resend counter reset, so the
     * customer's first "resend" is available quickly.
     */
    public function issueForLogin(User $user): Carbon
    {
        return $this->put($user, attempts: 1, cooldownSeconds: 60);
    }

    /**
     * A code the customer asked for. Honours the escalating cooldown and the
     * daily ceiling.
     */
    public function resend(User $user): OtpDispatchResult
    {
        $throttleKey = $this->throttleKey($user);

        if (Cache::has($throttleKey)) {
            $value = Cache::get($throttleKey);

            // Older rows stored a boolean here rather than a timestamp. Treat
            // anything that is not plausibly a unix time as already elapsed.
            if ($value === true || $value === 1 || $value < 1000) {
                Cache::forget($throttleKey);
            } else {
                $remaining = max(0, (int) $value - time());

                // Three seconds of slack for a client-side countdown that ran
                // slightly fast, so the customer who waited is not refused.
                if ($remaining <= 3) {
                    Cache::forget($throttleKey);
                } else {
                    return OtpDispatchResult::CooldownActive;
                }
            }
        }

        $attempts = (int) Cache::get($this->attemptsKey($user), 0) + 1;

        if ($attempts > self::MAX_RESENDS) {
            return OtpDispatchResult::TooManyRequests;
        }

        $this->put($user, $attempts, self::RESEND_DELAYS[$attempts]);

        return OtpDispatchResult::Sent;
    }

    /**
     * Check a code, consuming the guess budget on a miss and clearing
     * everything on a match.
     */
    public function verify(User $user, string $code): OtpVerificationResult
    {
        $stored = Cache::get($this->codeKey($user));
        $failsKey = $this->failsKey($user);

        // hash_equals is constant-time; a plain !== leaks how many leading
        // digits matched via response timing. Compared as strings so a numeric
        // cache value and string input still line up.
        if (! $stored || ! hash_equals((string) $stored, $code)) {
            $fails = (int) Cache::get($failsKey, 0) + 1;

            if ($fails >= self::MAX_GUESSES) {
                Cache::forget($this->codeKey($user));
                Cache::forget($this->expiresKey($user));
                Cache::forget($failsKey);

                return OtpVerificationResult::Burned;
            }

            Cache::put($failsKey, $fails, now()->addMinutes(self::TTL_MINUTES));

            return OtpVerificationResult::Invalid;
        }

        Cache::forget($this->codeKey($user));
        Cache::forget($this->expiresKey($user));
        Cache::forget($this->throttleKey($user));
        Cache::forget($this->attemptsKey($user));
        Cache::forget($failsKey);

        return OtpVerificationResult::Verified;
    }

    /** Seconds until another code may be asked for. Zero when clear. */
    public function cooldownSeconds(User $user): int
    {
        $value = Cache::get($this->throttleKey($user), 0);

        return is_numeric($value) ? max(0, (int) $value - time()) : 0;
    }

    /**
     * Seconds of life left in the current code, or null when nothing stamped it
     * (a code issued before that key existed). Null means show no clock rather
     * than a made-up one.
     */
    public function codeExpiresInSeconds(User $user): ?int
    {
        $stamped = Cache::get($this->expiresKey($user));

        return is_numeric($stamped) ? max(0, (int) $stamped - time()) : null;
    }

    public function hasLiveCode(User $user): bool
    {
        return Cache::get($this->codeKey($user)) !== null;
    }

    /**
     * The address a code went to, with the local part hidden past its first two
     * characters. Both the verification page and the app show this: it confirms
     * where to look without printing an address to a device that has not been
     * trusted yet.
     */
    public static function maskedEmail(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');
        $keep = min(2, strlen($local));

        return substr($local, 0, $keep).str_repeat('*', max(1, strlen($local) - $keep)).'@'.$domain;
    }

    /**
     * Mint and store a code, and send it. The only place that writes these keys.
     */
    private function put(User $user, int $attempts, int $cooldownSeconds): Carbon
    {
        // random_int is a CSPRNG (mt_rand is not) and the (0, 999999) range
        // covers the full six-digit space including 000000, which mt_rand(1, ...)
        // could never produce.
        $code = sprintf('%06d', random_int(0, 999999));

        $expiresAt = now()->addMinutes(self::TTL_MINUTES);
        $cooldownUntil = now()->addSeconds($cooldownSeconds);

        Cache::put($this->codeKey($user), $code, $expiresAt);
        // Stamped beside the code so a clock can show the real time left.
        Cache::put($this->expiresKey($user), $expiresAt->timestamp, $expiresAt);
        Cache::put($this->throttleKey($user), $cooldownUntil->timestamp, $cooldownUntil);
        Cache::put($this->attemptsKey($user), $attempts, now()->addHours(24));

        // A fresh code gets a fresh guess budget.
        Cache::forget($this->failsKey($user));

        OtpRequested::dispatch($user, $code, 'login');

        return $expiresAt;
    }

    private function codeKey(User $user): string
    {
        return 'device_otp_'.$user->id;
    }

    private function expiresKey(User $user): string
    {
        return 'device_otp_expires_'.$user->id;
    }

    private function throttleKey(User $user): string
    {
        return 'otp_throttle_'.$user->id;
    }

    private function attemptsKey(User $user): string
    {
        return 'otp_attempts_'.$user->id;
    }

    private function failsKey(User $user): string
    {
        return 'otp_verify_fails_'.$user->id;
    }
}
