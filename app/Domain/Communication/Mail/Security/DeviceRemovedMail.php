<?php

namespace App\Domain\Communication\Mail\Security;

use App\Models\SiteSetting;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Sent to the account owner when a device is signed out and removed from
 * their trusted devices, whether they did it themselves or someone holding
 * their session did. The owner who did not do this is the one who most
 * needs to hear about it.
 */
class DeviceRemovedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $name,
        public readonly string $deviceLabel,
        public readonly ?string $ipAddress,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'A device was signed out of your account',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.security.device-removed',
            with: [
                'name' => $this->name,
                'deviceLabel' => $this->deviceLabel,
                'ipAddress' => $this->ipAddress,
                'supportEmail' => 'info@rshoprefill.com',
                'siteName' => SiteSetting::get('site.name', 'RshopRefills'),
            ]
        );
    }
}
