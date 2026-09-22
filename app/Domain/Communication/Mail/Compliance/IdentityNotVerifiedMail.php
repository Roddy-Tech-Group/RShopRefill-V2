<?php

namespace App\Domain\Communication\Mail\Compliance;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Sent when an identity verification does not pass. Deliberately generic: a
 * provider's technical result text is never passed through.
 */
class IdentityNotVerifiedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $name,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'We could not verify your identity',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.kyc.not-verified',
            with: [
                'name' => $this->name,
            ]
        );
    }
}
