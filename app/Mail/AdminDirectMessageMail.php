<?php

namespace App\Mail;

use App\Models\SiteSetting;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * A direct message from an admin to a single customer. The `type` flips the
 * email's tone + accent colour: 'notification' is informational (blue), 'warning'
 * is a flag the customer should act on (amber).
 */
class AdminDirectMessageMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /**
     * @param  'notification'|'warning'  $type
     */
    public function __construct(
        public User $recipient,
        public string $type,
        public string $body,
        public ?string $adminName = null,
    ) {}

    public function envelope(): Envelope
    {
        $brand = SiteSetting::get('site.name', 'RshopRefills');
        $subject = $this->type === 'warning'
            ? '⚠️ Account warning from '.$brand
            : 'A message from '.$brand;

        return new Envelope(subject: $subject);
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.admin.direct-message',
            with: [
                'name' => $this->recipient->name ?? 'Customer',
                'type' => $this->type,
                'body' => $this->body,
                'adminName' => $this->adminName,
            ],
        );
    }
}
