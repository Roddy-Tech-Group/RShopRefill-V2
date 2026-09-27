<?php

namespace App\Domain\Order\Enums;

enum OrderStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Completed = 'completed';
    case PartiallyCompleted = 'partially_completed';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case Refunded = 'refunded';
    case RequiresAttention = 'requires_attention';

    /**
     * The statuses that mean the order DID complete, so its sale is booked as
     * revenue in the month it completed.
     *
     * Refunded is here on purpose. A refund is recorded as its own event on the
     * day it happens, so the sale it refunds has to stay booked where it
     * happened; dropping the order instead makes a closed month's revenue
     * shrink after the fact. PartiallyCompleted is here because the items that
     * DID deliver were really sold, and callers exclude the failed items
     * separately rather than discarding the whole order.
     *
     * This is the single definition. The Finances page and the Overview and
     * Reports pages all read it, so they can never quietly disagree about what
     * counts as a sale.
     *
     * @return list<string>
     */
    public static function booked(): array
    {
        return [
            self::Completed->value,
            self::PartiallyCompleted->value,
            self::Refunded->value,
        ];
    }

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Processing => 'Processing',
            self::Completed => 'Completed',
            self::PartiallyCompleted => 'Partially Completed',
            self::Failed => 'Failed',
            self::Cancelled => 'Cancelled',
            self::Refunded => 'Refunded',
            self::RequiresAttention => 'Requires Attention',
        };
    }
}
