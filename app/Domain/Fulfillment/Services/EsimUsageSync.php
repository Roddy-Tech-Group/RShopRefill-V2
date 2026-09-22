<?php

namespace App\Domain\Fulfillment\Services;

use App\Domain\Fulfillment\Enums\EsimStatus;
use App\Domain\Fulfillment\Providers\AiraloFulfillmentProvider;
use App\Models\Esim;

/**
 * Refreshes an eSIM's status and data from the supplier.
 *
 * Only Airalo reports usage; any other supplier's eSIM is left as recorded.
 * A failed read changes nothing but the error note, so a supplier outage never
 * turns a known state into a guess. A refunded eSIM keeps Refunded, and one
 * with a refund pending keeps Refund pending, whatever the supplier says; the
 * supplier's own status is still stored (provider_status), so an eSIM switched
 * on while its refund is pending shows up on the detail page.
 */
class EsimUsageSync
{
    public function __construct(private readonly AiraloFulfillmentProvider $airalo) {}

    public function supports(Esim $esim): bool
    {
        return $esim->provider === 'airalo';
    }

    /**
     * Returns true when the supplier answered and the row was refreshed.
     */
    public function sync(Esim $esim): bool
    {
        if (! $this->supports($esim)) {
            return false;
        }

        $usage = $this->airalo->fetchUsage($esim->iccid);

        if ($usage === null) {
            $esim->forceFill(['sync_error' => 'The supplier did not return usage for this eSIM.', 'last_synced_at' => now()])->save();

            return false;
        }

        $status = EsimStatus::fromProvider($usage['status'], $usage['remaining_mb'], $usage['total_mb'], $usage['is_unlimited']);
        $hasBeenUsed = ! in_array($status, [EsimStatus::Inactive, EsimStatus::Unknown], true);

        $esim->forceFill([
            'status' => match (true) {
                $esim->isRefunded() => EsimStatus::Refunded,
                $esim->isRefundPending() => EsimStatus::RefundPending,
                default => $status,
            },
            'provider_status' => $usage['status'],
            'data_total_mb' => $usage['total_mb'],
            'data_remaining_mb' => $usage['remaining_mb'],
            'is_unlimited' => $usage['is_unlimited'],
            'expires_at' => $usage['expires_at'] ?? $esim->expires_at,
            'activated_at' => $esim->activated_at ?? ($hasBeenUsed ? now() : null),
            'last_synced_at' => now(),
            'sync_error' => null,
            'metadata' => array_merge((array) $esim->metadata, ['usage' => $usage['raw']]),
        ])->save();

        return true;
    }
}
