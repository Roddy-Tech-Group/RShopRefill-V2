<?php

namespace App\Domain\Security\Enums;

/** What checking a typed device code found. */
enum OtpCheck
{
    case Valid;

    /** Wrong, or the code has run out: the customer may try again. */
    case Invalid;

    /** The fifth wrong guess: the code is gone and a new one must be requested. */
    case Burned;
}
