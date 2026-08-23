<?php

namespace App\Domain\Risk\Signals;

use App\Domain\Kyc\Services\KycTierService;
use App\Domain\Risk\DTOs\RiskContext;
use App\Domain\Risk\DTOs\SignalHit;

/**
 * A high-value action from an identity-UNVERIFIED account: the classic
 * gift-card abuse shape, weighted heavily. Reuses HighValueSignal's threshold so
 * the two never disagree on what "high value" means.
 */
class UnverifiedHighValueSignal extends AbstractRiskSignal
{
    public function __construct(
        private readonly KycTierService $tiers,
        private readonly HighValueSignal $highValue,
    ) {}

    public function code(): string
    {
        return 'UNVERIFIED_HIGH_VALUE';
    }

    public function evaluate(RiskContext $context): ?SignalHit
    {
        $user = $context->user;

        if ($user === null) {
            return null;
        }

        $threshold = $this->highValue->highValueThreshold($this->tiers);

        if ($threshold > 0 && $context->amountUsd >= $threshold && ! $user->isKycVerified()) {
            return $this->hit([
                'amount_usd' => round($context->amountUsd, 2),
                'threshold_usd' => $threshold,
            ]);
        }

        return null;
    }
}
