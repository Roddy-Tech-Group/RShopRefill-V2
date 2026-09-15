<?php

namespace App\Domain\Risk\Signals;

use App\Domain\PaymentInsights\Support\DeclineVerdict;
use App\Domain\Risk\DTOs\RiskContext;
use App\Domain\Risk\DTOs\SignalHit;
use App\Models\PaymentDecline;

/**
 * Several different cards refused in a short window: the card-testing
 * fingerprint, weighted heavily.
 *
 * Reads the refused cards the gateway reported (payment_declines), counting
 * distinct card fingerprints rather than attempts so a customer retrying one
 * card is one signal and a tester running three is three. Cards the gateway
 * could not have processed anyway (our compliance blocks, unsupported brands)
 * are not counted. Until that table existed this signal read a rate-limiter
 * event table that never received a decline, so it had never fired.
 */
class RepeatedDeclinesSignal extends AbstractRiskSignal
{
    public function code(): string
    {
        return 'REPEATED_DECLINES';
    }

    public function evaluate(RiskContext $context): ?SignalHit
    {
        $windowHours = (int) $this->threshold('repeated_declines_window_hours', 2);
        $min = (int) $this->threshold('repeated_declines_min', 3);

        $query = PaymentDecline::query()
            ->where('declined_at', '>=', now()->subHours($windowHours))
            ->where('verdict_class', '!=', DeclineVerdict::CLASS_IGNORED);

        // Match on user when known, then the device across any account, and
        // fall back to the IP only when nothing better exists so guest card
        // testing from one origin is still caught.
        if ($context->user !== null) {
            $query->where(function ($inner) use ($context) {
                $inner->where('user_id', $context->user->getKey());
                if ($context->deviceUuid !== null) {
                    $inner->orWhere('device_uuid', $context->deviceUuid);
                }
            });
        } elseif ($context->deviceUuid !== null) {
            $query->where('device_uuid', $context->deviceUuid);
        } elseif ($context->ip !== null) {
            $query->where('customer_ip', $context->ip);
        } else {
            return null;
        }

        $cards = $query->get(['card_fingerprint', 'verdict_class'])
            ->map(fn (PaymentDecline $row) => $row->card_fingerprint ?? '__unknown__')
            ->unique()
            ->count();

        if ($cards >= $min) {
            return $this->hit([
                'decline_count' => $cards,
                'window_hours' => $windowHours,
                'min' => $min,
            ]);
        }

        return null;
    }
}
