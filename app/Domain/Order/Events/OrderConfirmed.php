<?php

namespace App\Domain\Order\Events;

use App\Models\Order;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * The customer's money has arrived for this order.
 *
 * This, not OrderPlaced, is the moment customer-facing "order confirmed"
 * communication belongs to. OrderPlaced fires when the checkout skeleton is
 * created, before any payment, and most checkouts on this platform are
 * abandoned, so anything hung on it tells most recipients a purchase happened
 * when it did not. Fired from PaymentSessionService::confirmSession, the one
 * choke point every payment route funnels through (card, transfer, crypto,
 * wallet, and the sweeper's late-webhook rescue), after the transaction
 * commits.
 */
class OrderConfirmed
{
    use Dispatchable, SerializesModels;

    public function __construct(public Order $order) {}
}
