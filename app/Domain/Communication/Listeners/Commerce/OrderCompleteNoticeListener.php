<?php

namespace App\Domain\Communication\Listeners\Commerce;

use App\Domain\Communication\Enums\CommunicationCategory;
use App\Domain\Communication\Mail\Orders\OrderCompleteMail;
use App\Domain\Communication\Services\CommunicationManager;
use App\Domain\Fulfillment\Enums\FulfillmentStatus;
use App\Domain\Order\Events\OrderCompleted;
use App\Domain\Reviews\Services\ReviewInvitation;
use Illuminate\Support\Facades\Log;

/**
 * Sends the order-complete note, which exists to carry the review invitation
 * blind copy. With no invitation address set there is nothing for it to
 * carry, so nothing is sent.
 */
class OrderCompleteNoticeListener
{
    public function __construct(
        private readonly CommunicationManager $communication,
    ) {}

    public function handle(OrderCompleted $event): void
    {
        // A courtesy email must never break the delivery it follows: this
        // runs straight after the order commits, in the fulfillment job or an
        // admin request, so anything thrown here would surface there.
        try {
            $bcc = ReviewInvitation::bccAddress();

            if ($bcc === null) {
                return;
            }

            $order = $event->order->loadMissing(['user', 'items']);
            $user = $order->user;

            // Only an order that delivered in full: an invitation after a
            // partial delivery asks for a review of something the customer did
            // not get.
            if (! $user || $order->fulfillment_status !== FulfillmentStatus::Fulfilled) {
                return;
            }

            $this->communication->send(
                recipient: $user,
                category: CommunicationCategory::OrderComplete,
                title: "Your order #{$order->order_number} is complete",
                message: "Everything on order #{$order->order_number} has been delivered.",
                mailable: new OrderCompleteMail($user, $order, $bcc),
                correlationId: 'order_complete_'.$order->id,
                idempotencyKey: 'order_complete_'.$order->id,
            );
        } catch (\Throwable $e) {
            Log::error('Order-complete note failed.', [
                'order_id' => $event->order->id ?? null,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
