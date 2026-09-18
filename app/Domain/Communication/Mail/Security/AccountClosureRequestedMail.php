<?php

namespace App\Domain\Communication\Mail\Security;

use App\Models\SiteSetting;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;

class AccountClosureRequestedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $name,
        public readonly Carbon $closesOn,
        public readonly int $graceDays,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your account will close on '.$this->closesOn->format('j F Y'),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.auth.account-closure-requested',
            with: [
                'name' => $this->name,
                'closesOn' => $this->closesOn->format('l, j F Y'),
                'graceDays' => $this->graceDays,
                'loginUrl' => route('login'),
                'siteName' => SiteSetting::get('site.name', 'RshopRefill'),
            ]
        );
    }
}
