<?php

namespace App\Domain\Communication\Mail\Security;

use App\Models\TrustedDevice;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class NewDeviceDetectedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly TrustedDevice $device
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'New device signed into your account',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.security.new_device',
        );
    }
}
