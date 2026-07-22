<?php

namespace App\Domain\Communication\Listeners\Commerce;

use App\Domain\Communication\Enums\CommunicationCategory;
use App\Domain\Communication\Mail\Orders\OrderPlacedMail;
use App\Domain\Communication\Services\CommunicationManager;
use App\Domain\Order\Events\OrderPlaced;

class OrderConfirmationListener
{
    public function __construct(
        private readonly CommunicationManager $communication,
    ) {}

    public function handle(OrderPlaced $event): void
    {
        $order = $event->order;
        $user = $order->user;

        $this->communication->send(
            recipient: $user,
            category: CommunicationCategory::OrderPlaced,
            title: 'Order Confirmation',
            message: "Your order #{$order->order_number} has been received.",
            mailable: new OrderPlacedMail($user, $order),
            correlationId: 'order_placed_'.$order->id
        );
    }
}
