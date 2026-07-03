<?php

namespace App\Domain\Notification\Listeners;

use App\Domain\Notification\Mail\AdminFulfillmentFailedMail;
use App\Domain\Notification\Mail\OrderFulfilledMail;
use App\Domain\Notification\Services\NotificationDispatcher;
use App\Domain\Order\Events\FulfillmentFailed;
use App\Domain\Order\Events\FulfillmentSucceeded;
use App\Models\FulfillmentLog;
use App\Models\User;
use Illuminate\Support\Str;

class SendFulfillmentNotificationListener
{
    public function __construct(
        private readonly NotificationDispatcher $dispatcher
    ) {}

    /**
     * Handle FulfillmentSucceeded.
     */
    public function onFulfillmentSucceeded(FulfillmentSucceeded $event): void
    {
        $item = $event->item;
        $order = $item->order;
        $user = $order->user;

        $this->dispatcher->dispatch(
            user: $user,
            title: 'Your Refill Voucher is Ready!',
            message: "Fulfillment completed for item: {$item->product_name} in order #{$order->order_number}.",
            category: 'order',
            mailable: new OrderFulfilledMail($user, $item)
        );
    }

    /**
     * Handle FulfillmentFailed.
     */
    public function onFulfillmentFailed(FulfillmentFailed $event): void
    {
        $item = $event->item;
        $order = $item->order;
        $user = $order->user;

        // 1. Notify Customer of delay/failure
        $this->dispatcher->dispatch(
            user: $user,
            title: 'Fulfillment Delay Notification',
            message: "We encountered a temporary delay fulfilling {$item->product_name} for order #{$order->order_number}. Our support team has been notified and will resolve it shortly.",
            category: 'order'
        );

        // 2. Alert Admin
        $adminEmail = config('mail.admin_address') ?? 'admin@rshoprefills.com';
        $adminUser = User::firstOrCreate(
            ['email' => $adminEmail],
            [
                'name' => 'System Admin',
                'password' => bcrypt(Str::random(16)),
            ]
        );

        // Fetch the exact API error from the fulfillment log
        $log = FulfillmentLog::where('order_item_id', $item->id)->latest('processed_at')->first();
        $apiErrorLog = $log ? ($log->error_message ?? json_encode($log->response_payload, JSON_PRETTY_PRINT)) : 'No API log found.';

        $this->dispatcher->dispatch(
            user: $adminUser,
            title: 'CRITICAL: Refill Fulfillment Failed!',
            message: "Fulfillment failed for order #{$order->order_number}, item ID: {$item->id}. Reason: {$event->reason}. Urgent manual intervention required.",
            category: 'security',
            mailable: new AdminFulfillmentFailedMail($item, $event->reason, $apiErrorLog)
        );
    }

    /**
     * Register listeners for subscription.
     */
    public function subscribe($events): array
    {
        return [
            FulfillmentSucceeded::class => 'onFulfillmentSucceeded',
            FulfillmentFailed::class => 'onFulfillmentFailed',
        ];
    }
}
