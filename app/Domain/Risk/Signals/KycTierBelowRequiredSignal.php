<?php

namespace App\Domain\Risk\Signals;

use App\Domain\Kyc\Services\KycTierService;
use App\Domain\Risk\DTOs\RiskContext;
use App\Domain\Risk\DTOs\SignalHit;

/**
 * The customer's KYC tier is below what this action's value/context requires.
 * Reuses the existing tier engine so the risk score and the KYC gate agree on
 * one definition of "required tier"; this is the seam through which a HIGH score
 * can later force identity verification regardless of amount (Stage 2).
 */
class KycTierBelowRequiredSignal extends AbstractRiskSignal
{
    public function __construct(private readonly KycTierService $tiers) {}

    public function code(): string
    {
        return 'KYC_TIER_BELOW_REQUIRED';
    }

    public function evaluate(RiskContext $context): ?SignalHit
    {
        $user = $context->user;

        if ($user === null) {
            return null;
        }

        $required = $this->tiers->requiredTierFor($user, $context->amountUsd, $context->context);
        $current = $this->tiers->currentTier($user);

        if (! $current->satisfies($required)) {
            return $this->hit([
                'current_tier' => $current->value,
                'required_tier' => $required->value,
            ]);
        }

        return null;
    }
}
