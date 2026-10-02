<?php

namespace App\Domain\Security\Enums;

/**
 * What happened when a device-verification code was checked.
 *
 * Burned is kept apart from Invalid deliberately: the customer needs to be told
 * to request a NEW code rather than to try again, because the one they have has
 * just been destroyed.
 */
enum OtpVerificationResult: string
{
    case Verified = 'verified';

    /** Wrong or expired, and there is still guess budget left. */
    case Invalid = 'invalid';

    /** Guess budget exhausted. The code has been destroyed. */
    case Burned = 'burned';
}
