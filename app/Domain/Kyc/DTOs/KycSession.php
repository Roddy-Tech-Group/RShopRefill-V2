<?php

namespace App\Domain\Kyc\DTOs;

/**
 * A started identity-verification session: where to send the customer to finish
 * it, and the reference that ties the eventual provider verdict back to us.
 *
 * Provider-neutral by design: a hosted redirect URL and/or an inline token cover
 * every provider's flow (Sumsub WebSDK token, Smile hosted link, a redirect,
 * etc.) without leaking the provider's shape into the engine.
 */
final readonly class KycSession
{
    public function __construct(
        public string $reference,
        public ?string $hostedUrl = null,
        public ?string $token = null,
        public array $metadata = [],
    ) {}
}
