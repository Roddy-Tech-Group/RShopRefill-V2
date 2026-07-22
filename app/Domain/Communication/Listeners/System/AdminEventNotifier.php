<?php

namespace App\Domain\Communication\Listeners\System;

use App\Domain\Communication\Services\AdminNotificationService;
use App\Domain\Order\Events\FulfillmentFailed;
use App\Domain\Order\Events\PaymentConfirmed;
use App\Domain\Order\Events\PaymentFailed;
use Illuminate\Auth\Events\Registered;
use Illuminate\Events\Dispatcher;

/**
 * Fans the platform's key events out to the admin-dashboard notification feed
 * (admin_notifications). Registered as a subscriber in CommunicationServiceProvider.
 */
class AdminEventNotifier
{
    public function __construct(
        private readonly AdminNotificationService $admin
    ) {}

    public function onRegistered(Registered $event): void
    {
        $user = $event->user;

        $this->admin->push(
            type: 'customer',
            title: 'New customer registered',
            message: ($user->name ?? 'A customer').' just created an account.',
            url: route('admin.customer', $user),
        );
    }

    public function onPaymentConfirmed(PaymentConfirmed $event): void
    {
        $order = $event->order;

        $this->admin->push(
            type: 'order',
            title: 'New order placed',
            message: 'Order #'.$order->order_number.' for '.$order->display_currency.' '.number_format((float) $order->total_amount, 2).'.',
            url: route('admin.order', $order),
        );
    }

    public function onPaymentFailed(PaymentFailed $event): void
    {
        $order = $event->order;

        $this->admin->push(
            type: 'payment',
            title: 'Payment failed',
            message: 'Payment failed for order #'.$order->order_number.'.',
            url: route('admin.order', $order),
        );
    }

    public function onFulfillmentFailed(FulfillmentFailed $event): void
    {
        $order = $event->item->order;

        $this->admin->push(
            type: 'fulfillment',
            title: 'Fulfillment needs attention',
            message: 'An item in order #'.($order->order_number ?? 'unknown').' failed to fulfil: '.$event->reason,
            url: $order ? route('admin.order', $order) : null,
        );
    }

    /**
     * @return array<class-string, string>
     */
    public function subscribe(Dispatcher $events): array
    {
        return [
            Registered::class => 'onRegistered',
            PaymentConfirmed::class => 'onPaymentConfirmed',
            PaymentFailed::class => 'onPaymentFailed',
            FulfillmentFailed::class => 'onFulfillmentFailed',
        ];
    }
}
