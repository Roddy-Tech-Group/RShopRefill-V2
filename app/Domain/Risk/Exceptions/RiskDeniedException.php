<?php

namespace App\Domain\Risk\Exceptions;

use App\Domain\Risk\DTOs\RiskDecision;
use RuntimeException;

/**
 * Thrown at a money action the risk engine has refused outright (a CRITICAL band
 * or a hard-override signal such as confirmed fraud or a banned account, or a
 * previously rejected review). Only raised when risk enforcement is on; in shadow
 * mode the decision is recorded and the action proceeds.
 *
 * The customer message is deliberately generic: it never exposes reason codes or
 * which signal fired. The codes travel on the exception for internal logging and
 * dispute evidence only.
 */
class RiskDeniedException extends RuntimeException
{
    /**
     * @param  list<string>  $reasonCodes
     */
    public function __construct(
        public readonly array $reasonCodes = [],
        public readonly ?string $assessmentId = null,
    ) {
        parent::__construct('This action was declined by our security systems.');
    }

    public static function fromDecision(RiskDecision $decision): self
    {
        return new self($decision->reasonCodes, $decision->assessmentId);
    }

    public function customerMessage(): string
    {
        return 'We could not process this transaction for security reasons. If you believe this is a mistake, please contact support.';
    }

    /**
     * @return array<string, mixed>
     */
    public function toResponse(): array
    {
        return [
            'risk_declined' => true,
            'message' => $this->customerMessage(),
        ];
    }
}
