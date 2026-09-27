<?php

namespace App\Domain\Risk\Signals;

use App\Domain\Risk\DTOs\RiskContext;
use App\Domain\Risk\DTOs\SignalHit;
use App\Models\TrustedDevice;

/**
 * Impossible travel on the money path: the account's devices report two or more
 * distinct countries within a short window, which no single human could
 * physically do.
 *
 * Compares ISO country CODES, not names. Names are provider-specific ("The
 * Netherlands" against "Netherlands" for the same place), so a name comparison
 * would read a provider swap as a customer moving country. Devices whose code
 * has not been resolved yet are skipped rather than guessed at, which can only
 * ever make the signal quieter; `devices:backfill-countries` fills them in.
 *
 * Shares its window with the login-time check in LoginCountryGuard so both
 * paths agree on what counts as too fast.
 */
class GeoVelocitySignal extends AbstractRiskSignal
{
    public function code(): string
    {
        return 'GEO_VELOCITY';
    }

    public function evaluate(RiskContext $context): ?SignalHit
    {
        $user = $context->user;

        if ($user === null) {
            return null;
        }

        $windowHours = (int) $this->threshold('geo_velocity_window_hours', 6);

        $countries = TrustedDevice::query()
            ->where('user_id', $user->getKey())
            ->where('last_seen_at', '>=', now()->subHours($windowHours))
            ->whereNotNull('country_code')
            ->distinct()
            ->pluck('country_code')
            ->filter()
            ->values();

        if ($countries->count() >= 2) {
            return $this->hit([
                'countries' => $countries->all(),
                'window_hours' => $windowHours,
            ]);
        }

        return null;
    }
}
