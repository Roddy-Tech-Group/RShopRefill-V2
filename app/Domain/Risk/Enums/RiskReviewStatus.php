<?php

namespace App\Domain\Risk\Enums;

/**
 * The lifecycle of a manual risk review.
 *
 *  - Pending: awaiting an admin decision; the held action stays blocked.
 *  - Approved: admin cleared it; the customer's retry is allowed for the
 *    clearance window (config risk.review.clearance_hours).
 *  - Rejected: admin killed it; the action stays refused.
 *  - Escalated: needs a second/senior look; treated as still-blocking, like
 *    pending, but visible as its own state in the queue.
 */
enum RiskReviewStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Escalated = 'escalated';

    /**
     * Whether a review in this state still holds the action (nothing may proceed).
     */
    public function isBlocking(): bool
    {
        return $this === self::Pending || $this === self::Escalated;
    }

    /**
     * Whether this is a terminal admin decision.
     */
    public function isDecided(): bool
    {
        return $this === self::Approved || $this === self::Rejected;
    }

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
