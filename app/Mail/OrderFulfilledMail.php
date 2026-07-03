<?php

namespace App\Mail;

use App\Models\OrderItem;
use App\Models\SiteSetting;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class OrderFulfilledMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public OrderItem $item) {}

    public function envelope(): Envelope
    {
        $brand = SiteSetting::get('site.name', 'RshopRefills');

        return new Envelope(
            subject: 'Your '.$brand.' Order #'.$this->item->order->order_number.' is Ready!',
        );
    }

    public function content(): Content
    {
        $payload = $this->item->fulfillment_payload ?? [];
        $pinCode = $payload['pin'] ?? null;
        $voucherCode = $payload['code'] ?? $payload['redemption_url'] ?? $payload['esim_activation_code'] ?? $payload['esim_lpa'] ?? 'View dashboard for details';

        return new Content(
            view: 'emails.order.fulfilled',
            with: [
                'name' => $this->item->order->user->name ?? 'Customer',
                'orderNumber' => $this->item->order->order_number,
                'productName' => $this->item->product_snapshot['name'] ?? 'Digital Product',
                'voucherCode' => $voucherCode,
                'pinCode' => $pinCode,
            ]
        );
    }
}
