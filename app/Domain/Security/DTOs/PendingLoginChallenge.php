<?php

namespace App\Domain\Security\DTOs;

use Illuminate\Support\Carbon;

/**
 * A sign-in that has passed the password but still owes an emailed code.
 *
 * The token is the app's ticket back to finish the challenge. It is not an
 * access token: it authenticates nothing, it cannot read an order or touch a
 * wallet, and its only use is the verify and resend endpoints.
 */
final readonly class PendingLoginChallenge
{
    public function __construct(
        public string $token,
        public Carbon $expiresAt,
    ) {}
}
