<?php

namespace App\Domain\Communication\Mail\Compliance;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Sent when a customer's identity is verified, whether by the verification
 * provider's result or by our review team.
 */
class IdentityVerifiedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $name,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: '🎉 You\'re verified! Time to shop',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.kyc.verified',
            with: [
                'name' => $this->name,
            ]
        );
    }
}
