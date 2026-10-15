<?php

namespace App\Domain\Security\Services;

use App\Domain\Security\Enums\OtpCheck;
use App\Domain\Security\Enums\OtpResend;
use App\Domain\Security\Events\OtpRequested;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

/**
 * The emailed six-digit code that proves a sign-in from a new or unusual
 * device. Issuing, resending and checking it live here so the website's
 * verify-device page and the mobile app's sign-in hold the same rules: a
 * 10-minute life, a widening wait between resends, five a day, and five wrong
 * guesses before the code is burned.
 *
 * The cache keys predate this class and are kept as they were, so a code sent
 * before a deploy still works after it.
 */
final class DeviceOtpService
{
    /**
     * How long a code stays valid. The "expires in" clock, the guess budget
     * window and the cache lifetime all read this one number, so the customer
     * is never told a time the server does not enforce.
     */
    public const TTL_MINUTES = 10;

    /** The wait, in seconds, that the nth code of the day sets before the next. */
    private const RESEND_DELAYS = [
        1 => 60,       // the code a challenged sign-in sends at once
        2 => 180,
        3 => 300,
        4 => 1800,
        5 => 3600,
    ];

    private const MAX_GUESSES = 5;

    /**
     * The first code of a challenged sign-in, sent as the challenge begins.
     *
     * @param  string  $context  What the email says the code is for: 'login' (a
     *                           new device) or 'email_verification' (an address
     *                           the mobile app is confirming, AppSignIn).
     */
    public function issue(User $user, string $context = 'login'): void
    {
        $this->send($user, attempt: 1, context: $context);
    }

    public function resend(User $user, string $context = 'login'): OtpResend
    {
        $throttleKey = $this->throttleKey($user);

        // Anti-spam with a 3-second grace period for frontend timer drift.
        if (Cache::has($throttleKey)) {
            $value = Cache::get($throttleKey);
            if ($value === true || $value === 1 || $value < 1000) {
                // A flag stored before the wait became a timestamp.
                Cache::forget($throttleKey);
            } elseif (max(0, (int) $value - time()) <= 3) {
                Cache::forget($throttleKey);
            } else {
                return OtpResend::TooSoon;
            }
        }

        $attempt = (int) Cache::get($this->attemptsKey($user), 0) + 1;

        if ($attempt > count(self::RESEND_DELAYS)) {
            return OtpResend::Exhausted;
        }

        $this->send($user, $attempt, $context);

        return OtpResend::Sent;
    }

    public function check(User $user, string $code): OtpCheck
    {
        $stored = Cache::get($this->codeKey($user));
        $failsKey = $this->failsKey($user);

        // hash_equals is constant-time; a plain !== leaks how many leading
        // digits matched via response timing. Compare as strings so a numeric
        // cache value and the string input still line up.
        if (! $stored || ! hash_equals((string) $stored, $code)) {
            // Cap wrong guesses so the six-digit code cannot be brute-forced
            // inside its 10-minute life. After five misses the code is burned
            // and the customer must request a new one.
            $fails = (int) Cache::get($failsKey, 0) + 1;

            if ($fails >= self::MAX_GUESSES) {
                Cache::forget($this->codeKey($user));
                Cache::forget($this->expiresKey($user));
                Cache::forget($failsKey);

                return OtpCheck::Burned;
            }

            Cache::put($failsKey, $fails, now()->addMinutes(self::TTL_MINUTES));

            return OtpCheck::Invalid;
        }

        Cache::forget($this->codeKey($user));
        Cache::forget($this->expiresKey($user));
        Cache::forget($this->throttleKey($user));
        Cache::forget($this->attemptsKey($user));
        Cache::forget($failsKey);

        return OtpCheck::Valid;
    }

    /** Seconds until another code may be asked for; 0 when one may be asked for now. */
    public function secondsUntilResend(User $user): int
    {
        $until = Cache::get($this->throttleKey($user), 0);

        // A flag stored before the wait became a timestamp reads as no wait.
        return is_numeric($until) ? max(0, (int) $until - time()) : 0;
    }

    /**
     * The code's real remaining life, stamped when it was issued. Null when
     * nothing stamped it, so a client shows no clock rather than a made-up one.
     */
    public function secondsUntilExpiry(User $user): ?int
    {
        $expiry = Cache::get($this->expiresKey($user));

        return is_numeric($expiry) ? max(0, (int) $expiry - time()) : null;
    }

    /**
     * The address a code went to, with the local part hidden past its first
     * two characters: it is shown before the device is trusted, so it says
     * where to look without printing the address.
     */
    public static function maskEmail(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');
        $keep = min(2, strlen($local));

        return substr($local, 0, $keep).str_repeat('*', max(1, strlen($local) - $keep)).'@'.$domain;
    }

    private function send(User $user, int $attempt, string $context): void
    {
        // random_int is a CSPRNG (mt_rand is not) and the (0, 999999) range
        // covers the full six-digit space including 000000.
        $code = sprintf('%06d', random_int(0, 999999));
        $expiresAt = now()->addMinutes(self::TTL_MINUTES);
        $delay = self::RESEND_DELAYS[$attempt];

        Cache::put($this->codeKey($user), $code, $expiresAt);
        // Stamped beside the code so a client can show the real time left.
        Cache::put($this->expiresKey($user), $expiresAt->timestamp, $expiresAt);
        Cache::put($this->throttleKey($user), now()->addSeconds($delay)->timestamp, now()->addSeconds($delay));
        Cache::put($this->attemptsKey($user), $attempt, now()->addHours(24));

        // A fresh code gets a fresh guess budget.
        Cache::forget($this->failsKey($user));

        OtpRequested::dispatch($user, $code, $context);
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
