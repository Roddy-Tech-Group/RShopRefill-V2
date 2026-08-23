<?php

namespace App\Domain\Kyc\Services;

use App\Domain\Kyc\Enums\KycTier;
use App\Domain\Kyc\Exceptions\KycStepUpRequiredException;
use App\Models\User;

/**
 * The enforcement seam between the tier engine and the money paths.
 *
 * Called at each money action (order placement, wallet funding) with the
 * transaction's USD value. When the customer must step up a tier to proceed, it
 * mints a verification session with the routed provider (Smile) and throws
 * KycStepUpRequiredException, which the choke-point controllers turn into a
 * "kyc_required" response carrying that session.
 *
 * In shadow mode (features.kyc_enforce_enabled off) the tier engine returns no
 * step-up, so guard() is inert: it records what it WOULD demand and lets the
 * action through. No provider call is made until enforcement is switched on.
 */
class KycGate
{
    public function __construct(
        private readonly KycTierService $tiers,
        private readonly KycProviderFactory $providers,
    ) {}

    /**
     * @param  string  $context  'order' | 'funding'
     * @param  KycTier|null  $riskFloor  a floor the risk engine imposes, raising
     *                                   the required tier independently of value
     *
     * @throws KycStepUpRequiredException
     */
    public function guard(User $user, float $amountUsd, string $context, ?KycTier $riskFloor = null): void
    {
        $required = $this->tiers->requiredStepUp($user, $amountUsd, $context, $riskFloor);

        if ($required === null) {
            return;
        }

        $this->stepUp($user, $required, $context);
    }

    /**
     * Force a verification step-up to the given tier regardless of value or the
     * KYC enforcement flag. Used by the risk engine when a decision (e.g. a HIGH
     * band) requires identity verification on its own authority. Mints the
     * provider session and throws so the choke-point controllers hand the
     * customer straight into the flow.
     *
     * No-op when the user already satisfies the tier.
     *
     * @param  string  $context  'order' | 'funding' — carried onto the exception
     *                           so the customer message uses the right noun
     *
     * @throws KycStepUpRequiredException
     */
    public function stepUp(User $user, KycTier $required = KycTier::IdentityVerified, string $context = 'order'): void
    {
        if ($this->tiers->currentTier($user)->satisfies($required)) {
            return;
        }

        $session = $this->providers->forUser($user)->startVerification($user);

        throw new KycStepUpRequiredException($required, $session, $user, $context);
    }
}
