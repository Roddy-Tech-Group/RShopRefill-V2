<?php

namespace App\Domain\Security\Enums;

/** What asking for another device code came to. */
enum OtpResend
{
    case Sent;

    /** Inside the wait the last code set. */
    case TooSoon;

    /** Five codes in a day already: no more until the day is out. */
    case Exhausted;
}
