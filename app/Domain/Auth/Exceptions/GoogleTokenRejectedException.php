<?php

namespace App\Domain\Auth\Exceptions;

use RuntimeException;

/**
 * Google answered, and the ID token is not one we accept: expired, malformed,
 * minted for another application, or for an address Google has not verified.
 * The customer has to sign in again; retrying the same token will not help.
 */
class GoogleTokenRejectedException extends RuntimeException {}
