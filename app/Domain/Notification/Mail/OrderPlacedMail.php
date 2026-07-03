<?php

namespace App\Domain\Notification\Mail;

use App\Models\Order;
use App\Models\SiteSetting;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class OrderPlacedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly User $user,
        public readonly Order $order
    ) {}

    public function envelope(): Envelope
    {
        $brand = SiteSetting::get('site.name', 'RshopRefills');

        return new Envelope(
            subject: "Your {$brand} Order confirmation - #{$this->order->order_number}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.order.placed',
            with: [
                'name' => $this->user->name,
                'orderNumber' => $this->order->order_number,
                'total' => $this->order->total_amount,
                'currency' => $this->order->display_currency,
                'itemsCount' => $this->order->items->count(),
            ]
        );
    }
}
