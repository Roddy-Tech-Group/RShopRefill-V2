<?php

namespace App\Domain\Kyc\Exceptions;

use RuntimeException;

/**
 * Thrown at order placement when the transaction would push the customer past
 * their rolling daily/weekly/monthly spending cap. Only raised with enforcement
 * on; in shadow the gate logs the would-be refusal and lets the order through.
 *
 * The message doubles as a KYC nudge: an unverified customer is told that
 * verifying raises the cap, which is the self-clearing path.
 */
class SpendingLimitExceededException extends RuntimeException
{
    /**
     * @param  string  $window  'daily' | 'weekly' | 'monthly'
     */
    public function __construct(
        public readonly string $window,
        public readonly float $limitUsd,
        public readonly bool $isKycVerified,
    ) {
        parent::__construct('Spending limit exceeded.');
    }

    public function customerMessage(): string
    {
        $limit = '$'.number_format($this->limitUsd, 0);

        return $this->isKycVerified
            ? "You've reached your {$this->window} transaction limit of {$limit}. It resets automatically; please try again later."
            : "You've reached your {$this->window} transaction limit of {$limit}. Verify your identity to raise your limits.";
    }

    /**
     * @return array<string, mixed>
     */
    public function toResponse(): array
    {
        return [
            'limit_exceeded' => true,
            'message' => $this->customerMessage(),
            'window' => $this->window,
        ];
    }
}
