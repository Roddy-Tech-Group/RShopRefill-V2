<?php

namespace App\Domain\Auth\Exceptions;

use RuntimeException;

/**
 * Thrown at a money action when the customer's email is not yet verified.
 * Carries whether a fresh link was just sent and the resend cooldown, so the
 * frontend can show the same verify-email notice (with its escalating Resend
 * countdown) that checkout uses, instead of a bare form error.
 */
class EmailVerificationRequiredException extends RuntimeException
{
    public function __construct(
        public readonly string $email,
        public readonly bool $sent,
        public readonly int $retryAfter,
    ) {
        parent::__construct('Email verification required.');
    }

    public function customerMessage(): string
    {
        return $this->sent
            ? 'Please verify your email to continue. We just sent a new verification link to '.$this->email.'.'
            : 'Please verify your email to continue. Check your inbox for the verification link we sent to '.$this->email.'.';
    }
}
