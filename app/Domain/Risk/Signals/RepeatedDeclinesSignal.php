<?php

namespace App\Domain\Risk\Signals;

use App\Domain\PaymentInsights\Services\PaymentBackoffManager;
use App\Domain\Risk\DTOs\RiskContext;
use App\Domain\Risk\DTOs\SignalHit;
use App\Models\PaymentBackoffEvent;

/**
 * Repeated payment declines in a short window: the card-testing fingerprint,
 * weighted heavily. Reads the PaymentBackoffManager's persisted decline events
 * (read-only, no new event recorded) so the backoff subsystem becomes a signal
 * feeding the engine rather than a competing gate.
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

        $query = PaymentBackoffEvent::query()
            ->where('signal', PaymentBackoffManager::SIGNAL_DECLINE)
            ->where('created_at', '>=', now()->subHours($windowHours));

        // Match on user when known, otherwise fall back to the IP so guest
        // card-testing from one origin is still caught.
        if ($context->user !== null) {
            $query->where('user_id', $context->user->getKey());
        } elseif ($context->ip !== null) {
            $query->where('customer_ip', $context->ip);
        } else {
            return null;
        }

        $declines = (int) $query->max('decline_count');

        if ($declines >= $min) {
            return $this->hit([
                'decline_count' => $declines,
                'window_hours' => $windowHours,
                'min' => $min,
            ]);
        }

        return null;
    }
}
