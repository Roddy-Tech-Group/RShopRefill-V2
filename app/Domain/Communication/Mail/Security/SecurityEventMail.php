<?php

namespace App\Domain\Communication\Mail\Security;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class SecurityEventMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $eventType,
        public readonly string $details
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Security Alert: '.$this->eventType,
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.security.event',
        );
    }
}
