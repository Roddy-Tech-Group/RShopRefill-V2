<?php

namespace App\Domain\Communication\Mail\Security;

use App\Models\SiteSetting;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Fulfils a written access request: the PDF export is attached by the admin
 * action that sends this, and it goes only to the address on the account.
 */
class DataExportRequestedByStaffMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public readonly string $name) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your data export is attached',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.security.data-export-attached',
            with: [
                'name' => $this->name,
                'siteName' => SiteSetting::get('site.name', 'RshopRefills'),
            ]
        );
    }
}
