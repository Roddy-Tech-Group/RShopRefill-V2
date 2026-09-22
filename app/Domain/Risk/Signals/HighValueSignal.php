<?php

namespace App\Domain\Risk\Signals;

use App\Domain\Kyc\Services\KycTierService;
use App\Domain\Risk\DTOs\RiskContext;
use App\Domain\Risk\DTOs\SignalHit;

/**
 * The action's value is at or above the high-value threshold (USD). The amount
 * defaults to the KYC Tier 2 threshold so the two stay aligned by one knob;
 * config('risk.thresholds.high_value_usd') overrides it when they need to split.
 */
class HighValueSignal extends AbstractRiskSignal
{
    public function __construct(private readonly KycTierService $tiers) {}

    public function code(): string
    {
        return 'HIGH_VALUE';
    }

    public function evaluate(RiskContext $context): ?SignalHit
    {
        $threshold = $this->highValueThreshold($this->tiers);

        if ($threshold > 0 && $context->amountUsd >= $threshold) {
            return $this->hit([
                'amount_usd' => round($context->amountUsd, 2),
                'threshold_usd' => $threshold,
            ]);
        }

        return null;
    }

    /**
     * The high-value cutoff in USD: the explicit risk config when set, otherwise
     * the KYC Tier 2 threshold. Shared with UnverifiedHighValueSignal.
     */
    public function highValueThreshold(KycTierService $tiers): float
    {
        $configured = config('risk.thresholds.high_value_usd');

        if ($configured !== null && $configured !== '') {
            return (float) $configured;
        }

        return $tiers->singleThresholdUsd();
    }
}
