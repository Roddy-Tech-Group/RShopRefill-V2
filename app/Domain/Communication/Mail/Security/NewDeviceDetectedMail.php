<?php

namespace App\Domain\Communication\Mail\Security;

use App\Models\TrustedDevice;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\URL;

class NewDeviceDetectedMail extends Mailable
{
    use Queueable, SerializesModels;

    /** How long the "secure my account" link in this email stays valid. */
    public const LOCK_LINK_HOURS = 48;

    public function __construct(
        public readonly TrustedDevice $device
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'New device signed into your account',
        );
    }

    /**
     * The lock link is a temporary SIGNED url: it expires, and it cannot be
     * forged or replayed indefinitely. Its predecessor was a bare HMAC of the
     * device id with no expiry, so anyone who ever held one of these emails
     * could freeze the account at will, for life.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.security.new_device',
            with: [
                'lockUrl' => URL::temporarySignedRoute(
                    'security.compromise',
                    now()->addHours(self::LOCK_LINK_HOURS),
                    ['device' => $this->device->id],
                ),
            ],
        );
    }
}
