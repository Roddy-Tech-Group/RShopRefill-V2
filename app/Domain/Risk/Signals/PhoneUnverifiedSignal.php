<?php

namespace App\Domain\Risk\Signals;

use App\Domain\Risk\DTOs\RiskContext;
use App\Domain\Risk\DTOs\SignalHit;

/**
 * The account's phone number has not been proven by OTP. A soft signal on its
 * own (phone verification is not yet enforced for a first order), it adds weight
 * in combination with value and velocity.
 */
class PhoneUnverifiedSignal extends AbstractRiskSignal
{
    public function code(): string
    {
        return 'PHONE_UNVERIFIED';
    }

    public function evaluate(RiskContext $context): ?SignalHit
    {
        $user = $context->user;

        if ($user === null) {
            return null;
        }

        if (! $user->hasVerifiedPhone()) {
            return $this->hit();
        }

        return null;
    }
}
