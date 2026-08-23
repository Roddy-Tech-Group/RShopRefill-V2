<?php

namespace App\Domain\Risk\Exceptions;

use App\Models\RiskReview;
use RuntimeException;

/**
 * Thrown at a money action the risk engine has held for manual review (a HIGH
 * band). The attempt does not proceed; a pending RiskReview is on the queue for
 * an admin. Once approved, the customer's retry is allowed for the clearance
 * window; if rejected, the retry is refused.
 *
 * Only raised when risk enforcement is on. The customer sees a neutral
 * "under review" message, never the reason codes.
 */
class RiskReviewRequiredException extends RuntimeException
{
    public function __construct(
        public readonly RiskReview $review,
    ) {
        parent::__construct('This action is being reviewed.');
    }

    public function customerMessage(): string
    {
        $noun = $this->review->context === 'funding' ? 'request' : 'purchase';

        return "Your {$noun} is being reviewed by our team and will be processed shortly.";
    }

    /**
     * @return array<string, mixed>
     */
    public function toResponse(): array
    {
        return [
            'risk_review' => true,
            'message' => $this->customerMessage(),
            'reference' => $this->review->getKey(),
        ];
    }
}
