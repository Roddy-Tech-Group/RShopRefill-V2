<?php

namespace App\Domain\Communication\Mail\Orders;

use App\Models\Order;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class RefundProcessedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly User $user,
        public readonly Order $order,
        public readonly float $amount,
        public readonly string $reason
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Your refund has been processed - #{$this->order->order_number}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.order.refunded',
            with: [
                'name' => $this->user->name,
                'orderNumber' => $this->order->order_number,
                'amount' => $this->amount,
                'currency' => $this->order->display_currency,
                'reason' => $this->reason,
            ]
        );
    }
}
