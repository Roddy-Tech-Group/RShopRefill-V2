<?php

namespace App\Domain\Communication\Mail\System;

use App\Models\OrderItem;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AdminFulfillmentFailedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly OrderItem $item,
        public readonly string $reason,
        public readonly string $apiErrorLog
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'CRITICAL: Fulfillment Failed for Order #'.($this->item->order->order_number ?? 'Unknown'),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.admin.fulfillment-failed',
            with: [
                'item' => $this->item,
                'reason' => $this->reason,
                'apiErrorLog' => $this->apiErrorLog,
                'orderNumber' => $this->item->order->order_number ?? 'Unknown',
            ]
        );
    }
}
