<?php

namespace App\Domain\Kyc\DTOs;

use App\Domain\Kyc\Enums\KycTier;

/**
 * A provider's normalised decision on a verification, parsed out of whatever
 * shape the provider's webhook arrived in. The engine acts only on this, never
 * on raw provider payloads. An approval carries the identity the document
 * proved, when the provider returned one.
 */
final readonly class KycVerdict
{
    public function __construct(
        public string $reference,
        public bool $approved,
        public KycTier $tier = KycTier::IdentityVerified,
        public ?string $reason = null,
        public array $raw = [],
        public ?VerifiedIdentity $identity = null,
    ) {}

    public static function approved(string $reference, KycTier $tier = KycTier::IdentityVerified, array $raw = [], ?VerifiedIdentity $identity = null): self
    {
        return new self($reference, true, $tier, null, $raw, $identity);
    }

    public static function rejected(string $reference, ?string $reason = null, array $raw = []): self
    {
        return new self($reference, false, KycTier::PhoneVerified, $reason, $raw);
    }
}
