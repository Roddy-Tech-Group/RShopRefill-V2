<?php

namespace App\Domain\Notification\Listeners;

use App\Domain\Notification\Mail\AdminFulfillmentFailedMail;
use App\Domain\Notification\Mail\OrderFulfilledMail;
use App\Domain\Notification\Services\NotificationDispatcher;
use App\Domain\Order\Events\FulfillmentFailed;
use App\Domain\Order\Events\FulfillmentSucceeded;
use App\Mail\GuestAccountClaimMail;
use App\Models\FulfillmentLog;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
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

        // Gifting: when checkout captured a delivery email that is not the
        // buyer's own, the code email goes to that inbox instead. The buyer
        // keeps the in-app notification and the order (with its codes) on
        // their dashboard.
        $deliveryEmail = strtolower(trim((string) ($order->metadata['delivery_email'] ?? '')));
        $isGift = $deliveryEmail !== '' && $deliveryEmail !== strtolower((string) $user->email);

        $this->dispatcher->dispatch(
            user: $user,
            title: 'Your Refill Voucher is Ready!',
            message: "Fulfillment completed for item: {$item->product_name} in order #{$order->order_number}.",
            category: 'order',
            mailable: new OrderFulfilledMail($user, $item, $isGift),
            overrideEmail: $isGift ? $deliveryEmail : null
        );

        // Guest checkout: alongside the product, the guest gets a link to
        // claim their shadow account (set a password + transaction PIN) so
        // they can track this order and everything they buy later. Throttled
        // to one claim email per hour - multi-item orders fire one
        // fulfillment event per item.
        if (
            $user->is_guest
            && (! $user->guest_claim_last_sent_at || $user->guest_claim_last_sent_at->lt(now()->subHour()))
        ) {
            Mail::to($user->email)->queue(new GuestAccountClaimMail($user));
            $user->forceFill(['guest_claim_last_sent_at' => now()])->save();
        }
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
