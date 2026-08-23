<?php

namespace App\Domain\Risk\Signals;

use App\Domain\Risk\DTOs\RiskContext;
use App\Domain\Risk\DTOs\SignalHit;

/**
 * The account's email is not verified. Email verification is mandatory for money
 * actions (EnsureEmailVerified), so at a real checkout this should be rare; when
 * it is not, it is a strong signal something bypassed the normal path.
 */
class EmailUnverifiedSignal extends AbstractRiskSignal
{
    public function code(): string
    {
        return 'EMAIL_UNVERIFIED';
    }

    public function evaluate(RiskContext $context): ?SignalHit
    {
        $user = $context->user;

        if ($user === null) {
            return null;
        }

        if (! $user->hasVerifiedEmail()) {
            return $this->hit(['is_guest' => $context->isGuest()]);
        }

        return null;
    }
}
