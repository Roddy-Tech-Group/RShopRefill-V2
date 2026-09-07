<?php

namespace App\Domain\Communication\Mail\Security;

use App\Models\SiteSetting;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Sent when staff ban an account. The customer has just been signed out on
 * every device, so this is the only channel left to tell them why they can
 * no longer sign in and where to write.
 */
class AccountBannedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $name,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your account has been suspended',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.security.account-banned',
            with: [
                'name' => $this->name,
                'supportEmail' => 'info@rshoprefill.com',
                'siteName' => SiteSetting::get('site.name', 'RshopRefills'),
            ]
        );
    }
}
