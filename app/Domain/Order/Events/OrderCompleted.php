<?php

namespace App\Domain\Order\Events;

use App\Models\Order;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Every item on the order was delivered: either all at once, or an admin
 * finished an order that had only partly delivered. Not raised for a partly
 * fulfilled order, which also ends up Completed but left something
 * undelivered.
 *
 * Raised after the fulfillment transaction, and when that transaction sits
 * inside an outer one it still waits for the outer commit: a listener must
 * never act on a completion that rolls back.
 */
class OrderCompleted implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(public Order $order) {}
}
