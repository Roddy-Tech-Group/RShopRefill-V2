<?php

namespace App\Domain\Communication\Mail\Transactions;

use App\Domain\Communication\Enums\CommunicationCategory;
use App\Domain\Communication\Support\UnsubscribeLink;
use App\Domain\PaymentInsights\Enums\ProductLine;
use App\Domain\PaymentInsights\Support\RecoveryCopy;
use App\Models\SiteSetting;
use App\Models\TransactionRecovery;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Queue\SerializesModels;

/**
 * The recovery email, written to the reason the payment actually failed.
 *
 * Two touches share this mailable: the first email shortly after the
 * failure, and one reminder if the customer never opened the link. The
 * reminder carries softer copy, because repeating the same message louder
 * is how a helpful email becomes spam.
 *
 * It is optional mail, so it carries a List-Unsubscribe header and a footer
 * link that turns it off without a login. Mail sent through the Resend API
 * gets the same header from the job's metadata (the provider builds its own
 * payload); headers() covers every other transport.
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
        $productLine = $this->recovery->productLine();
        $copy = RecoveryCopy::for($this->recovery->failure_reason_code, $productLine);

        $subject = $this->isReminder
            ? RecoveryCopy::reminderSubject($this->recovery->failure_reason_code, $productLine)
            : $copy['subject'];

        return new Envelope(
            subject: $subject.' · '.$brand,
        );
    }

    public function headers(): Headers
    {
        $unsubscribe = $this->unsubscribeUrl();

        return new Headers(text: $unsubscribe === null ? [] : [
            'List-Unsubscribe' => "<{$unsubscribe}>",
            'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
        ]);
    }

    /**
     * The first email is sent moments after the link is minted, so it has the
     * full window. The reminder lands about a day in and says what is left,
     * to the nearest hour ("about").
     */
    public function expiryLine(): string
    {
        if (! $this->isReminder) {
            return 'This link works for the next 48 hours.';
        }

        $hoursLeft = $this->recovery->expires_at
            ? (int) round(now()->diffInHours($this->recovery->expires_at, false))
            : 0;

        return $hoursLeft > 1
            ? "This link works for about {$hoursLeft} more hours."
            : 'This link expires within the hour.';
    }

    public function unsubscribeUrl(): ?string
    {
        $user = $this->recovery->user;

        return $user ? UnsubscribeLink::for($user, CommunicationCategory::PaymentRecovery) : null;
    }

    public function content(): Content
    {
        $productLine = $this->recovery->productLine();
        $code = $this->recovery->failure_reason_code;
        $copy = RecoveryCopy::for($code, $productLine);
        $session = $this->recovery->paymentSession;
        $order = $session?->order;

        return new Content(
            view: 'emails.transactions.payment-recovery',
            with: [
                'name' => trim((string) ($this->recovery->user?->name ?? '')) ?: 'there',
                'resumeUrl' => route('recovery.show', ['token' => $this->recovery->token]),
                // Relative, never a clock time: customers sit in every timezone
                // and we store none, so "3:42pm" is nobody's time in particular.
                'expiryLine' => $this->expiryLine(),
                'isReminder' => $this->isReminder,
                'noun' => RecoveryCopy::subjectNoun($productLine),
                'isFunding' => $productLine === ProductLine::WalletFunding,
                // A swept session may already have been paid; asking that
                // customer to "complete" it invites a double charge.
                'uncertain' => RecoveryCopy::outcomeUncertain($code),
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
                'manageUrl' => route('dashboard.notifications'),
                'unsubscribeUrl' => $this->unsubscribeUrl(),
            ]
        );
    }
}
