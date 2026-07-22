<?php

namespace App\Domain\Communication\Mail\Security;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AccountRecoveredMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $ipAddress
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your account has been recovered',
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.security.account_recovered',
        );
    }
}
