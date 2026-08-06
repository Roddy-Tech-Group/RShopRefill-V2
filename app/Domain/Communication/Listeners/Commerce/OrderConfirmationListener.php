<?php

namespace App\Domain\Communication\Listeners\Commerce;

use App\Domain\Communication\Enums\CommunicationCategory;
use App\Domain\Communication\Mail\Orders\OrderPlacedMail;
use App\Domain\Communication\Services\CommunicationManager;
use App\Domain\Order\Events\OrderConfirmed;

/**
 * Sends the customer their order confirmation once payment has ARRIVED.
 *
 * Used to listen to OrderPlaced, which fires when the checkout skeleton is
 * created before any money moves; roughly nine in ten of those checkouts are
 * abandoned, so most confirmations announced purchases that never happened,
 * and then the sweeper cancelled the order the email had just confirmed.
 */
class OrderConfirmationListener
{
    public function __construct(
        private readonly CommunicationManager $communication,
    ) {}

    public function handle(OrderConfirmed $event): void
    {
        $order = $event->order;
        $user = $order->user;

        $this->communication->send(
            recipient: $user,
            category: CommunicationCategory::OrderPlaced,
            title: 'Order Confirmation',
            message: "Your order #{$order->order_number} is confirmed and being prepared.",
            mailable: new OrderPlacedMail($user, $order),
            // Keyed to the ORDER, not the session: a second session confirming
            // the same order (late webhook on a retried checkout) dedupes to
            // one email through the communication idempotency guard.
            correlationId: 'order_confirmed_'.$order->id
        );
    }
}
