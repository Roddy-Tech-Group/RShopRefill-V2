<?php

namespace App\Domain\Notification\Mail;

use App\Models\OrderItem;
use App\Models\SiteSetting;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class OrderFulfilledMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly User $user,
        public readonly OrderItem $item
    ) {}

    public function envelope(): Envelope
    {
        $brand = SiteSetting::get('site.name', 'RshopRefills');

        return new Envelope(
            subject: 'Your digital product is ready! - '.$brand,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.order.fulfilled',
            with: [
                'name' => $this->user->name,
                'orderNumber' => $this->item->order->order_number,
                'item' => $this->item,
            ]
        );
    }
}
