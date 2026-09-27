<?php

namespace App\Domain\Communication\Mail\Security;

use App\Models\SiteSetting;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Security notice, not the file: a copy of the customer's data was downloaded
 * from their account. The file itself only ever travels over the signed-in
 * session.
 */
class DataExportedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $name,
        public readonly string $when,
        public readonly string $device,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'A copy of your data was downloaded',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.security.data-exported',
            with: [
                'name' => $this->name,
                'when' => $this->when,
                'device' => $this->device,
                'securityUrl' => route('dashboard.security'),
                'siteName' => SiteSetting::get('site.name', 'RshopRefill'),
            ]
        );
    }
}
