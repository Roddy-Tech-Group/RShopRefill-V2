<?php

namespace App\Domain\Communication\Mail\Security;

use App\Models\SiteSetting;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Carries the customer's name as a plain string on purpose: by the time a
 * queued copy renders, the user row has been anonymised.
 */
class AccountClosedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public readonly string $name) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your account has been closed',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.auth.account-closed',
            with: [
                'name' => $this->name,
                'siteName' => SiteSetting::get('site.name', 'RshopRefill'),
            ]
        );
    }
}
