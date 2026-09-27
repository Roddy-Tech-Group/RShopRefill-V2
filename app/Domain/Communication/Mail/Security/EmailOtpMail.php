<?php

namespace App\Domain\Communication\Mail\Security;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class EmailOtpMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $otp,
        public readonly string $context = 'login'
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your RshopRefill Authentication Code',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.security.otp',
        );
    }
}
