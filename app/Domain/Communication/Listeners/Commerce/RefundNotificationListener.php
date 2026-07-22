<?php

namespace App\Domain\Communication\Listeners\Commerce;

use App\Domain\Communication\Enums\CommunicationCategory;
use App\Domain\Communication\Mail\Orders\RefundProcessedMail;
use App\Domain\Communication\Services\CommunicationManager;
use App\Domain\Order\Events\RefundIssued;

class RefundNotificationListener
{
    public function __construct(
        private readonly CommunicationManager $communication,
    ) {}

    public function handle(RefundIssued $event): void
    {
        $order = $event->order;
        $user = $order->user;

        $this->communication->send(
            recipient: $user,
            category: CommunicationCategory::Refund,
            title: 'Refund Processed',
            message: "A refund of {$event->amount} has been processed for order #{$order->order_number}.",
            mailable: new RefundProcessedMail($user, $order, $event->amount, $event->reason),
            correlationId: 'order_refund_'.$order->id
        );
    }
}
