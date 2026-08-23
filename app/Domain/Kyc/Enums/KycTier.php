<?php

namespace App\Domain\Kyc\Enums;

/**
 * The identity-assurance ladder. A user sits AT one tier and an action REQUIRES
 * a minimum tier; when the user is below it, the engine steps them up. Tiers are
 * cumulative and ordered by their backing int, so comparisons are numeric.
 *
 *  - Registered (0): email verified + name + valid phone captured. Free. Granted
 *    the moment the email-verification gate is satisfied.
 *  - PhoneVerified (1): the captured phone proven by OTP. Costs one SMS, so it is
 *    stepped up at first purchase / funding rather than at signup.
 *  - IdentityVerified (2): government ID + liveness confirmed by a KYC provider.
 *    Paid, so it is stepped up only when risk exposure (cumulative volume, a
 *    single high-value transaction, velocity) justifies the cost.
 */
enum KycTier: int
{
    case Registered = 0;
    case PhoneVerified = 1;
    case IdentityVerified = 2;

    /**
     * Is this tier at least the given one? The comparison every gate uses:
     * "does the user's tier satisfy the tier this action requires?"
     */
    public function satisfies(self $required): bool
    {
        return $this->value >= $required->value;
    }

    /**
     * The next tier up, or null when already at the top.
     */
    public function next(): ?self
    {
        return self::tryFrom($this->value + 1);
    }

    public function label(): string
    {
        return match ($this) {
            self::Registered => 'Registered',
            self::PhoneVerified => 'Phone verified',
            self::IdentityVerified => 'Identity verified',
        };
    }
}
