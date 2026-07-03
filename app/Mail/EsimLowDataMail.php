<?php

namespace App\Mail;

use App\Models\OrderItem;
use App\Models\SiteSetting;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Branded "your eSIM is running low" email. Triggered by the Airalo low-data
 * webhook. The CTA links directly to /dashboard/esims/{orderItem}/top-up so
 * the customer is one tap from refilling.
 */
class EsimLowDataMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /**
     * @param  array<string, mixed>  $payload  Raw Airalo webhook body
     */
    public function __construct(
        public User $recipient,
        public OrderItem $orderItem,
        public string $iccid,
        public string $eventType,
        public array $payload,
    ) {}

    public function envelope(): Envelope
    {
        $brand = SiteSetting::get('site.name', 'RshopRefills');
        $isExpiry = $this->eventType === 'EXPIRY_SOON' || isset($this->payload['days_remaining']);
        $subject = $isExpiry
            ? '⏰ Your '.$brand.' eSIM expires soon'
            : '⚠️ Your '.$brand.' eSIM is running low';

        return new Envelope(subject: $subject);
    }

    public function content(): Content
    {
        $isExpiry = $this->eventType === 'EXPIRY_SOON' || isset($this->payload['days_remaining']);

        return new Content(
            view: 'emails.esim.low-data',
            with: [
                'name' => $this->recipient->name ?? 'Customer',
                'isExpiry' => $isExpiry,
                'iccid' => $this->iccid,
                'usagePercentage' => (int) ($this->payload['usage_percentage'] ?? 0),
                'daysRemaining' => (int) ($this->payload['days_remaining'] ?? 0),
                'packageData' => (string) ($this->payload['package_data'] ?? ''),
                'remainingData' => (string) ($this->payload['remaining_data'] ?? ''),
                'topupUrl' => route('dashboard.esim.topup', $this->orderItem),
                'esimName' => $this->orderItem->product?->name ?? 'eSIM',
            ],
        );
    }
}
