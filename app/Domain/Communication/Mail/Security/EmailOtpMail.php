<?php

namespace App\Domain\Communication\Mail\Security;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class EmailOtpMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $otp,
        public readonly string $context = 'login'
    ) {}

    /**
     * The code leads the subject for the everyday codes, so it shows in the
     * phone's notification without opening the email, and mail apps that offer
     * a code to the keyboard or a "Copy code" button can find it. Account
     * recovery and data export keep a subject without it.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: match ($this->context) {
                'login' => "{$this->otp} is your RShopRefill sign-in code",
                'email_verification' => "{$this->otp} is your RShopRefill confirmation code",
                'password_reset' => "{$this->otp} is your RShopRefill password reset code",
                default => 'Your RshopRefill Authentication Code',
            },
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.security.otp',
        );
    }
}
