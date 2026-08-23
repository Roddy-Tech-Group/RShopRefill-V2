<?php

namespace App\Domain\Kyc\Exceptions;

use App\Domain\Kyc\DTOs\KycSession;
use App\Domain\Kyc\Enums\KycTier;
use App\Models\User;
use RuntimeException;

/**
 * Thrown at a money action when the customer must complete a higher tier of
 * identity verification before it may proceed. Carries the verification session
 * (the Smile web token the frontend launches) so the choke-point controllers can
 * hand the customer straight into the flow rather than a dead end.
 *
 * Only ever thrown when KYC enforcement is switched on; in shadow mode the tier
 * engine logs the would-be step-up and this is never raised.
 */
class KycStepUpRequiredException extends RuntimeException
{
    /**
     * @param  string  $context  'order' | 'funding' — drives the customer-facing noun
     */
    public function __construct(
        public readonly KycTier $requiredTier,
        public readonly KycSession $session,
        public readonly User $user,
        public readonly string $context = 'order',
    ) {
        parent::__construct('Identity verification required to continue.');
    }

    public function customerMessage(): string
    {
        $noun = $this->context === 'funding' ? 'request' : 'purchase';

        return "To continue with this {$noun}, please complete a quick identity verification.";
    }

    /**
     * The response body the choke points return so the frontend can launch the
     * Smile flow with the minted session.
     *
     * @return array<string, mixed>
     */
    public function toResponse(): array
    {
        return [
            'kyc_required' => true,
            'message' => $this->customerMessage(),
            'required_tier' => $this->requiredTier->value,
            'smile' => [
                'token' => $this->session->token,
                'reference' => $this->session->reference,
                'partner_id' => $this->session->metadata['partner_id'] ?? null,
                'product' => $this->session->metadata['product'] ?? null,
                'environment' => $this->session->metadata['environment'] ?? null,
                'callback_url' => $this->session->metadata['callback_url'] ?? null,
                'user_id' => $this->session->metadata['user_id'] ?? null,
            ],
        ];
    }
}
