<?php

namespace App\Domain\Security\Enums;

/**
 * What happened when a device-verification code was asked for.
 *
 * Returned rather than thrown because none of these are errors: two of them are
 * the throttle working. The caller decides how to say so, which differs between
 * a Blade page and the mobile API.
 */
enum OtpDispatchResult: string
{
    case Sent = 'sent';

    /** Still inside the escalating cooldown from the previous request. */
    case CooldownActive = 'cooldown_active';

    /** Five requests in 24 hours is the ceiling; support has to unstick it. */
    case TooManyRequests = 'too_many_requests';
}
