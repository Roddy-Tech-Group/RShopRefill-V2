<?php

namespace App\Domain\Notification\Mail;

use App\Models\SiteSetting;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class WelcomeMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly User $user,
        public readonly bool $isGoogleAuth = false
    ) {}

    public function envelope(): Envelope
    {
        $brand = SiteSetting::get('site.name', 'RshopRefills');

        return new Envelope(
            subject: $this->isGoogleAuth
                ? 'Welcome to '.$brand.' via Google!'
                : 'Welcome to '.$brand.'!',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.welcome',
            with: [
                'name' => $this->user->name,
                'email' => $this->user->email,
                'isGoogleAuth' => $this->isGoogleAuth,
            ]
        );
    }
}
