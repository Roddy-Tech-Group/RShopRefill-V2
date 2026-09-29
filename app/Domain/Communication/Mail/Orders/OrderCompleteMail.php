<?php

namespace App\Domain\Communication\Mail\Orders;

use App\Models\Order;
use App\Models\SiteSetting;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * A short note that the whole order was delivered, blind-copied to the review
 * platform so it can invite the customer to leave a review.
 *
 * It must never carry a code, PIN or activation detail: the blind copy goes
 * to a third party. The codes live in the delivery email and on the order
 * page, and this note only points there.
 */
class OrderCompleteMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly User $user,
        public readonly Order $order,
        public readonly string $reviewInviteBcc,
    ) {}

    public function envelope(): Envelope
    {
        $brand = SiteSetting::get('site.name', 'RshopRefill');

        return new Envelope(
            subject: "Your order #{$this->order->order_number} is complete - {$brand}",
            bcc: [$this->reviewInviteBcc],
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.order.complete',
            with: [
                'name' => $this->user->name,
                'orderNumber' => $this->order->order_number,
                'itemsCount' => $this->order->items->count(),
                'total' => $this->order->total_amount,
                'currency' => $this->order->display_currency,
            ]
        );
    }
}
