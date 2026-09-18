<?php

namespace App\Domain\Communication\Mail\Security;

use App\Models\SiteSetting;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PasswordResetMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly User $user,
        public readonly string $token
    ) {}

    public function envelope(): Envelope
    {
        $brand = SiteSetting::get('site.name', 'RshopRefill');

        return new Envelope(
            subject: 'Reset Password Request - '.$brand,
        );
    }

    public function content(): Content
    {
        $resetUrl = url(route('password.reset', [
            'token' => $this->token,
            'email' => $this->user->email,
        ], false));

        return new Content(
            view: 'emails.auth.password-reset',
            with: [
                'name' => $this->user->name,
                'resetUrl' => $resetUrl,
                'siteName' => SiteSetting::get('site.name', 'RshopRefill'),
            ]
        );
    }
}
