<?php

namespace App\Domain\Risk\Signals;

use App\Domain\Payment\Enums\PaymentStatus;
use App\Domain\Risk\DTOs\RiskContext;
use App\Domain\Risk\DTOs\SignalHit;

/**
 * A cumulative spend spike: the user's paid volume in the last 24 hours, plus the
 * amount now being attempted, reaches the configured USD figure. Catches a burst
 * of moderate orders that each stay under the single-transaction high-value bar.
 */
class VelocityAmount24hSignal extends AbstractRiskSignal
{
    public function code(): string
    {
        return 'VELOCITY_AMOUNT_24H';
    }

    public function evaluate(RiskContext $context): ?SignalHit
    {
        $user = $context->user;

        if ($user === null) {
            return null;
        }

        $threshold = (float) $this->threshold('velocity_amount_24h_usd', 2000);

        if ($threshold <= 0) {
            return null;
        }

        // Orders with suspect pricing (non-USD display currency, no recorded
        // exchange rate) are excluded: usdTotal() would read their display
        // amount at face value as USD, so one degraded NGN order could brand a
        // customer a spend-spike abuser. Legit pre-snapshot legacy rows never
        // appear inside a 24h window, so nothing real is lost.
        $recentVolume = (float) $user->orders()
            ->where('payment_status', PaymentStatus::Paid)
            ->where('created_at', '>=', now()->subDay())
            ->get()
            ->reject(fn ($order) => $order->hasSuspectPricing())
            ->sum(fn ($order) => $order->usdTotal());

        $projected = $recentVolume + $context->amountUsd;

        if ($projected >= $threshold) {
            return $this->hit([
                'recent_volume_usd' => round($recentVolume, 2),
                'amount_usd' => round($context->amountUsd, 2),
                'projected_usd' => round($projected, 2),
                'threshold_usd' => $threshold,
            ]);
        }

        return null;
    }
}
