<?php

namespace App\Domain\Communication\Mail\System;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AdminNewOrderAlertMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly Order $order,
        public readonly bool $isLargeTransaction = false
    ) {}

    public function envelope(): Envelope
    {
        $subject = $this->isLargeTransaction
            ? 'CRITICAL ALERT: Large order placed!'
            : 'Notification: New digital order placed!';

        return new Envelope(
            subject: $subject,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.admin.new-order-alert',
            with: [
                'orderNumber' => $this->order->order_number,
                'customerName' => $this->order->user->name,
                'customerEmail' => $this->order->user->email,
                'totalAmount' => $this->order->total_amount,
                'currency' => $this->order->display_currency,
                'isLargeTransaction' => $this->isLargeTransaction,
            ]
        );
    }
}
