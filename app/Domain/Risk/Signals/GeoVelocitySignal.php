<?php

namespace App\Domain\Risk\Signals;

use App\Domain\Risk\DTOs\RiskContext;
use App\Domain\Risk\DTOs\SignalHit;
use App\Models\TrustedDevice;

/**
 * Impossible travel: the account's devices report two or more distinct countries
 * within a short window, which no single human could physically do. A pragmatic
 * distance/time proxy built from the device registry's resolved country, replacing
 * the old naive "IP changed" check; it upgrades to true lat/long distance the day
 * a GeoIP provider is wired, with no change to the engine.
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
            ->whereNotNull('country')
            ->distinct()
            ->pluck('country')
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
