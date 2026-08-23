<?php

namespace App\Domain\Communication\Mail\Security;

use App\Models\SiteSetting;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;

/**
 * The account email-verification message, routed through the Communication
 * Platform (verify@) so it is branded, logged, and preference-audited like
 * every other transactional email, instead of Laravel's default notification.
 *
 * The signed verification link is minted here with the exact same route,
 * expiry, and hash Laravel's built-in VerifyEmail notification uses, so the
 * existing VerifyEmailController keeps validating it unchanged.
 */
class VerifyEmailMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly User $user
    ) {}

    public function envelope(): Envelope
    {
        $brand = SiteSetting::get('site.name', 'RshopRefills');

        return new Envelope(
            subject: 'Verify your email address - '.$brand,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.auth.verify-email',
            with: [
                'name' => $this->user->name,
                'verifyUrl' => $this->verificationUrl(),
                'siteName' => SiteSetting::get('site.name', 'RshopRefills'),
                'expireMinutes' => (int) config('auth.verification.expire', 60),
            ]
        );
    }

    /**
     * Mint the signed verification URL. Identical route, expiry, and hash to
     * Illuminate\Auth\Notifications\VerifyEmail so VerifyEmailController's
     * ['signed'] middleware + hash check accept it as-is.
     */
    private function verificationUrl(): string
    {
        return URL::temporarySignedRoute(
            'verification.verify',
            Carbon::now()->addMinutes((int) config('auth.verification.expire', 60)),
            [
                'id' => $this->user->getKey(),
                'hash' => sha1($this->user->getEmailForVerification()),
            ]
        );
    }
}
