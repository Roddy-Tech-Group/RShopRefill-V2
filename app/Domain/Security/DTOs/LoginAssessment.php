<?php

namespace App\Domain\Security\DTOs;

use App\Models\TrustedDevice;

/** What a sign-in decided about the device it came from. */
final readonly class LoginAssessment
{
    public function __construct(
        public TrustedDevice $device,
        /** LOW, MEDIUM, HIGH or CRITICAL, from the RiskEngine. */
        public string $riskLevel,
        /** Whether the emailed device code must be entered before the sign-in completes. */
        public bool $needsOtp,
    ) {}
}
