<?php

namespace App\Domain\Security\DTOs;

use App\Models\TrustedDevice;

/**
 * What LoginRiskEvaluator decided about one sign-in.
 *
 * The website turns requiresOtp into a session flag and a redirect; the mobile
 * API turns it into a challenge instead of a token pair. The DECISION is made in
 * one place so those two can never drift apart, which is the whole point of this
 * object existing.
 */
final readonly class LoginRiskOutcome
{
    public function __construct(
        public TrustedDevice $device,
        public string $riskLevel,
        public bool $requiresOtp,
    ) {}
}
