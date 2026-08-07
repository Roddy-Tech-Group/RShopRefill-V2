<?php

namespace App\Domain\Communication\Mail\Transactions;

use App\Domain\PaymentInsights\Support\RecoveryCopy;
use App\Models\SiteSetting;
use App\Models\TransactionRecovery;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The recovery email, written to the reason the payment actually failed.
 *
 * Two touches share this mailable: the first email shortly after the
 * failure, and one reminder if the customer never opened the link. The
 * reminder carries softer copy, because repeating the same message louder
 * is how a helpful email becomes spam.
 */
class PaymentRecoveryMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly TransactionRecovery $recovery,
        public readonly bool $isReminder = false,
    ) {}

    public function envelope(): Envelope
    {
        $brand = SiteSetting::get('site.name', 'RshopRefills');
        $copy = RecoveryCopy::for($this->recovery->failure_reason_code);

        $subject = $this->isReminder
            ? RecoveryCopy::reminderSubject($this->recovery->failure_reason_code)
            : $copy['subject'];

        return new Envelope(
            subject: $subject.' · '.$brand,
        );
    }

    public function content(): Content
    {
        $copy = RecoveryCopy::for($this->recovery->failure_reason_code);
        $session = $this->recovery->paymentSession;
        $order = $session?->order;

        return new Content(
            view: 'emails.transactions.payment-recovery',
            with: [
                'resumeUrl' => route('recovery.show', ['token' => $this->recovery->token]),
                'expiresAt' => $this->recovery->expires_at,
                'isReminder' => $this->isReminder,
                'headline' => $copy['headline'],
                'guidance' => $copy['guidance'],
                'hint' => $copy['hint'],
                // Order context: a customer decides whether to bother based on
                // what is waiting for them, not on the fact that something is.
                'orderNumber' => $order?->order_number,
                'itemNames' => $order?->items
                    ->map(fn ($item) => $item->product_snapshot['name'] ?? null)
                    ->filter()->take(3)->values()->all() ?? [],
                'itemCount' => $order?->items->count() ?? 0,
                'amount' => $session?->amount,
                'currency' => $session?->display_currency ?: $session?->currency,
            ]
        );
    }
}
