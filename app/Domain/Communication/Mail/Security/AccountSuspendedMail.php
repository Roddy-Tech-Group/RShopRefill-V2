<?php

namespace App\Domain\Communication\Mail\Security;

use App\Models\SiteSetting;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Sent when staff place an account under review. Suspension keeps the
 * customer signed in but refuses purchases, funding and checkout, so the
 * mail says what still works, why, and how to ask for a review.
 */
class AccountSuspendedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $name,
        public readonly ?string $reason,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your account is under review',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.security.account-suspended',
            with: [
                'name' => $this->name,
                'reason' => $this->reason,
                'supportEmail' => 'info@rshoprefill.com',
                'siteName' => SiteSetting::get('site.name', 'RshopRefills'),
            ]
        );
    }
}
