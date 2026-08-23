<?php

namespace App\Domain\Risk\DTOs;

/**
 * A single fired signal. Carries the machine-readable reason code and structured
 * detail (never secrets/PAN/CVV/OTP) explaining WHY it fired. The weight is NOT
 * on the hit: the engine looks it up from config by code, so weighting stays a
 * pure policy concern and a signal never hardcodes its own importance.
 */
final readonly class SignalHit
{
    /**
     * @param  array<string, mixed>  $detail  structured, PII-safe explanation
     */
    public function __construct(
        public string $code,
        public array $detail = [],
    ) {}
}
