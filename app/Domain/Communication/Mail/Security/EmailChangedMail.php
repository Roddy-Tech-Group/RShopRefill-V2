<?php

namespace App\Domain\Communication\Mail\Security;

use App\Domain\Communication\Enums\MailDomain;
use App\Models\SiteSetting;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Sent to the OLD address when the account email changes, so the person who
 * owned the inbox finds out even if it was not them who changed it. Carries
 * plain strings only; the user row already holds the new address.
 */
class EmailChangedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $name,
        public readonly string $newEmailMasked,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your account email was changed',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.security.email-changed',
            with: [
                'name' => $this->name,
                'newEmailMasked' => $this->newEmailMasked,
                'supportEmail' => MailDomain::replyTo(),
                'siteName' => SiteSetting::get('site.name', 'RshopRefill'),
            ]
        );
    }
}
