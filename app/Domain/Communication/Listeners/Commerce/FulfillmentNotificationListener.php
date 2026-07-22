<?php

namespace App\Domain\Communication\Listeners\Commerce;

use App\Domain\Communication\Enums\CommunicationCategory;
use App\Domain\Communication\Mail\Orders\GuestAccountClaimMail;
use App\Domain\Communication\Mail\Orders\OrderFulfilledMail;
use App\Domain\Communication\Services\CommunicationManager;
use App\Domain\Order\Events\FulfillmentSucceeded;

class FulfillmentNotificationListener
{
    public function __construct(
        private readonly CommunicationManager $communication,
    ) {}

    public function handle(FulfillmentSucceeded $event): void
    {
        $item = $event->item;
        $order = $item->order;
        $user = $order->user;

        $productName = $item->product_snapshot['name'] ?? 'your item';

        // Gifting: when checkout captured a delivery email that is not the
        // buyer's own, the code email goes to that inbox instead. The buyer
        // keeps the in-app notification and the order on their dashboard.
        $deliveryEmail = strtolower(trim((string) ($order->metadata['delivery_email'] ?? '')));
        $isGift = $deliveryEmail !== '' && $deliveryEmail !== strtolower((string) $user->email);

        $this->communication->send(
            recipient: $user,
            category: CommunicationCategory::OrderFulfilled,
            title: 'Your Refill Voucher is Ready!',
            message: "Fulfillment completed for {$productName} in order #{$order->order_number}.",
            mailable: new OrderFulfilledMail($user, $item, $isGift),
            correlationId: 'order_fulfilled_'.$item->id,
            overrideEmail: $isGift ? $deliveryEmail : null
        );

        // Guest checkout: alongside the product, the guest gets a link to
        // claim their shadow account. Throttled to one claim email per hour —
        // multi-item orders fire one fulfillment event per item.
        if (
            $user->is_guest
            && (! $user->guest_claim_last_sent_at || $user->guest_claim_last_sent_at->lt(now()->subHour()))
        ) {
            $this->communication->send(
                recipient: $user,
                category: CommunicationCategory::AccountClaim,
                title: 'Claim your RShopRefills account',
                message: 'Set a password to track this order and everything you buy later.',
                mailable: new GuestAccountClaimMail($user),
                correlationId: 'guest_claim_'.$user->id
            );

            $user->forceFill(['guest_claim_last_sent_at' => now()])->save();
        }
    }
}
