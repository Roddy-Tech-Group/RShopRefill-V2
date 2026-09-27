<?php

namespace App\Domain\Security\Services;

use App\Domain\Security\DTOs\ResolvedLocation;
use App\Models\GeoVelocityEvent;
use App\Models\User;
use App\Models\UserLoginCountry;
use App\Support\FeatureFlag;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Impossible travel, done by country and time.
 *
 * What this replaces: a check that read "the IP differs from the one cached an
 * hour ago" and called it travel. It knew nothing about places or distances, so
 * it could not tell a customer walking from WiFi onto mobile data from a
 * stolen password being used on another continent. Because its cache lived only
 * an hour it also barely ever fired, which made it the worst of both: no
 * detection, and a risk level on the session record that meant nothing.
 *
 * The rule now, in full:
 *
 *  1. Resolve the country of this login.
 *  2. Look for another country on this account seen inside the window.
 *  3. Same country, or no other country in the window: nothing happens.
 *  4. Country unknown: nothing happens. A guess is worse than a shrug, because
 *     a fabricated country reads as a real move.
 *  5. A different country inside the window: ask for the emailed code, unless
 *     the customer has already proved this country, in which case let them
 *     through. Roughly a fifth of our devices resolve to the United States on a
 *     platform serving Cameroon and Nigeria, which is VPN exit traffic; without
 *     the proved-country rule every one of those customers would be challenged
 *     every session.
 *
 * What it will never do: block a login, hold an order, or touch a wallet. The
 * most expensive false positive is a customer typing six digits, which is what
 * makes the rule safe to run on imperfect location data.
 *
 * Every hit is recorded either way, so `geo:report` can answer whether the rule
 * is earning its place instead of us guessing.
 */
class LoginCountryGuard
{
    public function __construct(private IpLocator $locator) {}

    /**
     * Whether this login should be challenged for a country change.
     *
     * Also records the login's country, so the account's country history is
     * built by the same pass that reads it. Never throws.
     */
    public function challengeRequired(User $user, ?string $ip, ?string $deviceUuid = null): bool
    {
        if (FeatureFlag::off('login_country_check')) {
            return false;
        }

        try {
            return $this->evaluate($user, $ip, $deviceUuid);
        } catch (Throwable $e) {
            // A location is a nice to have; signing in is not.
            Log::warning('login_country.check_failed', [
                'user_id' => $user->getKey(),
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Record that the customer has demonstrated they reach the country this
     * address resolves to, so the check stops asking about it.
     *
     * Called from both sides of the login: when a code was passed, and when a
     * login needed no code at all. The second case matters as much as the
     * first, mirroring how a device that signs in without a challenge still
     * becomes a known device. Without it a customer's own home country would
     * never be proved, and their first trip abroad would challenge them twice.
     */
    public function markProven(User $user, ?string $ip): void
    {
        $location = $this->locator->locate($ip);

        if ($location === null) {
            return;
        }

        try {
            UserLoginCountry::query()
                ->where('user_id', $user->getKey())
                ->where('country_code', $location->countryCode)
                ->update(['proven_at' => now()]);
        } catch (Throwable $e) {
            Log::warning('login_country.prove_failed', [
                'user_id' => $user->getKey(),
                'country' => $location->countryCode,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * How many hours apart two countries have to be before the pair stops
     * being suspicious. Shared with GeoVelocitySignal on purpose: one number,
     * so the login path and the money path can never disagree about what
     * counts as too fast.
     */
    public function windowHours(): int
    {
        return max(1, (int) config('risk.thresholds.geo_velocity_window_hours', 6));
    }

    /**
     * How long a proved country stays proved. 0 means forever.
     */
    public function proofExpiryDays(): int
    {
        return max(0, (int) config('risk.thresholds.login_country_proof_days', 90));
    }

    private function evaluate(User $user, ?string $ip, ?string $deviceUuid): bool
    {
        $location = $this->locator->locate($ip);

        // Rule 4: unknown location scores nothing, and is not recorded either.
        // Writing an "unknown" row would make the next real country look like a
        // change from somewhere we never actually saw them.
        if ($location === null) {
            return false;
        }

        $windowHours = $this->windowHours();
        $now = now();

        /** @var UserLoginCountry|null $elsewhere */
        $elsewhere = UserLoginCountry::query()
            ->where('user_id', $user->getKey())
            ->where('country_code', '!=', $location->countryCode)
            ->where('last_seen_at', '>=', $now->copy()->subHours($windowHours))
            ->orderByDesc('last_seen_at')
            ->first();

        $destination = $this->touch($user, $location, $ip, $now);

        // Rules 2 and 3: nowhere else inside the window, so nothing to compare.
        if ($elsewhere === null) {
            return false;
        }

        $proven = $destination !== null && $destination->isProven($this->proofExpiryDays());

        $this->record(
            user: $user,
            from: $elsewhere,
            toCode: $location->countryCode,
            toName: $location->countryName,
            minutesApart: (int) round(abs($elsewhere->last_seen_at->diffInMinutes($now))),
            windowHours: $windowHours,
            challenged: ! $proven,
            proven: $proven,
            ip: $ip,
            deviceUuid: $deviceUuid,
        );

        return ! $proven;
    }

    /**
     * Upsert the account's row for this country and stamp it as just seen.
     *
     * Returns the row as it was BEFORE this login for the proven check, because
     * proof is about previous logins, not this one.
     */
    private function touch(User $user, ResolvedLocation $location, ?string $ip, Carbon $now): ?UserLoginCountry
    {
        /** @var UserLoginCountry|null $existing */
        $existing = UserLoginCountry::query()
            ->where('user_id', $user->getKey())
            ->where('country_code', $location->countryCode)
            ->first();

        if ($existing === null) {
            UserLoginCountry::query()->create([
                'user_id' => $user->getKey(),
                'country_code' => $location->countryCode,
                'country_name' => $location->countryName,
                'last_seen_at' => $now,
                'last_ip' => $ip,
            ]);

            return null;
        }

        $existing->forceFill([
            'country_name' => $location->countryName ?? $existing->country_name,
            'last_seen_at' => $now,
            'last_ip' => $ip,
        ])->save();

        return $existing;
    }

    /**
     * Log the hit. Wrapped because a missing audit row must never cost a
     * customer their login: the decision has already been made by this point.
     */
    private function record(
        User $user,
        UserLoginCountry $from,
        string $toCode,
        ?string $toName,
        int $minutesApart,
        int $windowHours,
        bool $challenged,
        bool $proven,
        ?string $ip,
        ?string $deviceUuid,
    ): void {
        try {
            GeoVelocityEvent::query()->create([
                'user_id' => $user->getKey(),
                'from_country_code' => $from->country_code,
                'from_country_name' => $from->country_name,
                'to_country_code' => $toCode,
                'to_country_name' => $toName,
                'minutes_apart' => max(0, $minutesApart),
                'window_hours' => $windowHours,
                'challenged' => $challenged,
                'destination_proven' => $proven,
                'ip_address' => $ip,
                'device_uuid' => $deviceUuid,
            ]);
        } catch (Throwable $e) {
            Log::warning('login_country.event_not_recorded', [
                'user_id' => $user->getKey(),
                'from' => $from->country_code,
                'to' => $toCode,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
