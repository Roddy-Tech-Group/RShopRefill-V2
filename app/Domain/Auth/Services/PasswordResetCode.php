<?php

namespace App\Domain\Auth\Services;

use App\Domain\Security\Enums\OtpCheck;
use App\Domain\Security\Enums\OtpResend;
use App\Domain\Security\Events\OtpRequested;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * The emailed six-digit code that lets the mobile app reset a forgotten
 * password, where the website sends a link (rshoprefill-mobileapp cannot open
 * one into itself). The same shape as the device code (DeviceOtpService): a
 * 10-minute life, a widening wait between codes, five a day, five wrong guesses
 * before a code is burned. Its own keys, so a reset code can never stand in for
 * a sign-in code or the other way round.
 *
 * Everything is keyed by the address asked about, not by an account, and an
 * address with no account is answered exactly as one with an account is (the
 * same waits, the same clocks, a code that is simply never sent), so the reset
 * form cannot be used to learn who has an account. The website says the same:
 * "A reset link will be sent if the account exists."
 */
final class PasswordResetCode
{
    public const TTL_MINUTES = 10;

    /** How long a proven code's reset may wait for the new password. */
    private const TICKET_MINUTES = 10;

    /** The wait, in seconds, that the nth code of the day sets before the next. */
    private const RESEND_DELAYS = [
        1 => 60,
        2 => 180,
        3 => 300,
        4 => 1800,
        5 => 3600,
    ];

    private const MAX_GUESSES = 5;

    /**
     * A code for the address, if its wait is over and the day's codes are not
     * spent. Sent only when the address has an account.
     */
    public function send(string $email): OtpResend
    {
        $email = self::normalise($email);
        $wait = Cache::get($this->key('wait', $email));

        // A 3-second grace for the app's clock running a little ahead.
        if (is_int($wait) && $wait - time() > 3) {
            return OtpResend::TooSoon;
        }

        $attempt = (int) Cache::get($this->key('attempts', $email), 0) + 1;

        if ($attempt > count(self::RESEND_DELAYS)) {
            return OtpResend::Exhausted;
        }

        // random_int is a CSPRNG and (0, 999999) covers every six-digit code.
        $code = sprintf('%06d', random_int(0, 999999));
        $expiresAt = now()->addMinutes(self::TTL_MINUTES);
        $delay = self::RESEND_DELAYS[$attempt];

        Cache::put($this->key('code', $email), $code, $expiresAt);
        Cache::put($this->key('expires', $email), $expiresAt->timestamp, $expiresAt);
        Cache::put($this->key('wait', $email), now()->addSeconds($delay)->timestamp, now()->addSeconds($delay));
        Cache::put($this->key('attempts', $email), $attempt, now()->addHours(24));
        Cache::forget($this->key('fails', $email));

        $user = self::account($email);

        if ($user !== null) {
            OtpRequested::dispatch($user, $code, 'password_reset');
        }

        return OtpResend::Sent;
    }

    /**
     * The address's code, checked. A right code is spent at once, and so is one
     * that has had five wrong guesses.
     */
    public function check(string $email, string $code): OtpCheck
    {
        $email = self::normalise($email);
        $stored = Cache::get($this->key('code', $email));

        if (! is_string($stored)) {
            return OtpCheck::Invalid;
        }

        if (! hash_equals($stored, $code)) {
            $fails = (int) Cache::get($this->key('fails', $email), 0) + 1;
            Cache::put($this->key('fails', $email), $fails, now()->addMinutes(self::TTL_MINUTES));

            if ($fails >= self::MAX_GUESSES) {
                Cache::forget($this->key('code', $email));
                Cache::forget($this->key('fails', $email));

                return OtpCheck::Burned;
            }

            return OtpCheck::Invalid;
        }

        Cache::forget($this->key('code', $email));
        Cache::forget($this->key('fails', $email));

        // An address with no account has a code no one was sent; matching it
        // proves nothing, and is answered as any wrong code is.
        return self::account($email) === null ? OtpCheck::Invalid : OtpCheck::Valid;
    }

    /**
     * What a proven code buys: one reset, on the phone that proved it, of the
     * password the account had then. Single use.
     */
    public function ticket(User $user, string $installationId): string
    {
        $ticket = Str::random(64);

        Cache::put($this->ticketKey($ticket), [
            'user_id' => $user->id,
            'installation_id' => $installationId,
            'password' => hash('sha256', (string) $user->password),
        ], now()->addMinutes(self::TICKET_MINUTES));

        return $ticket;
    }

    /** Whose reset a ticket is, without spending it: what the new password is checked against. */
    public function holder(string $ticket): ?User
    {
        $held = Cache::get($this->ticketKey($ticket));

        return is_array($held) ? User::find($held['user_id']) : null;
    }

    /** The account a ticket resets, spending it; null when it is not good for this phone. */
    public function redeem(string $ticket, string $installationId): ?User
    {
        $held = Cache::pull($this->ticketKey($ticket));

        if (! is_array($held) || ! hash_equals((string) $held['installation_id'], $installationId)) {
            return null;
        }

        $user = User::find($held['user_id']);

        // A password changed since the code was proven (another reset, or a
        // change on the website) makes the ticket stale.
        if ($user === null || ! hash_equals((string) $held['password'], hash('sha256', (string) $user->password))) {
            return null;
        }

        return $user;
    }

    public function secondsUntilExpiry(string $email): int
    {
        $expires = Cache::get($this->key('expires', self::normalise($email)));

        return is_int($expires) ? max(0, $expires - time()) : 0;
    }

    public function secondsUntilNext(string $email): int
    {
        $wait = Cache::get($this->key('wait', self::normalise($email)));

        return is_int($wait) ? max(0, $wait - time()) : 0;
    }

    /** An account that can have its password reset: not closed, not banned. */
    public static function account(string $email): ?User
    {
        return User::query()
            ->where('email', self::normalise($email))
            ->whereNull('anonymised_at')
            ->whereNull('banned_at')
            ->first();
    }

    private static function normalise(string $email): string
    {
        return Str::lower(trim($email));
    }

    /** Only a digest of the address is in a key, so the cache holds no list of emails. */
    private function key(string $part, string $email): string
    {
        return "password_reset_{$part}_".hash('sha256', $email);
    }

    private function ticketKey(string $ticket): string
    {
        return 'password_reset_ticket_'.hash('sha256', $ticket);
    }
}
