<?php

namespace App\Domain\Order\Events;

use App\Models\Order;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class RefundIssued
{
    use Dispatchable, SerializesModels;

    /**
     * $tag identifies WHICH refund this is (the order's refund-ledger tag:
     * item-{id}, final-{order}, settle-{order}, admin-{uuid}), so each
     * distinct refund produces exactly one customer email. Without it, two
     * refunds of the same amount on one order deduped to a single email and
     * the customer was never told about the second one.
     */
    public function __construct(
        public Order $order,
        public float $amount,
        public string $reason,
        public ?string $tag = null
    ) {}
}
