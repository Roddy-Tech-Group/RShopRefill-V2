<?php

namespace App\Domain\Kyc\Exceptions;

use RuntimeException;

/**
 * Thrown at a money action (an order or a wallet funding that brings new money
 * in) when it would push the customer past their rolling daily/weekly/monthly
 * cap. Only raised with enforcement on; in shadow the gate logs the would-be
 * refusal and lets the action through.
 *
 * The message doubles as a KYC nudge: an unverified customer is told that
 * verifying raises the cap, which is the self-clearing path. While
 * verification is switched off that would send them to a page that cannot
 * help, so they are told when the limit resets instead.
 */
class SpendingLimitExceededException extends RuntimeException
{
    /**
     * @param  string  $window  'daily' | 'weekly' | 'monthly'
     * @param  bool  $canVerify  whether identity verification is open to the
     *                           customer right now
     */
    public function __construct(
        public readonly string $window,
        public readonly float $limitUsd,
        public readonly bool $isKycVerified,
        public readonly bool $canVerify = true,
    ) {
        parent::__construct('Spending limit exceeded.');
    }

    public function customerMessage(): string
    {
        $limit = '$'.number_format($this->limitUsd, 0);

        return $this->isKycVerified || ! $this->canVerify
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
