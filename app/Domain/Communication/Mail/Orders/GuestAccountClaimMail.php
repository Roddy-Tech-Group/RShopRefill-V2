<?php

namespace App\Domain\Communication\Mail\Orders;

use App\Models\SiteSetting;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\URL;

/**
 * Sent to a guest buyer alongside their fulfilled order: a signed link (7
 * days) to claim the shadow account their order was placed under. Claiming
 * means setting a password + transaction PIN; the orders they placed as a
 * guest are already attached to the account.
 */
class GuestAccountClaimMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public readonly User $user) {}

    public function envelope(): Envelope
    {
        $brand = SiteSetting::get('site.name', 'RshopRefills');

        return new Envelope(
            subject: 'Claim your '.$brand.' account to track your order',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.auth.claim-account',
            with: [
                'name' => $this->user->name,
                'claimUrl' => URL::temporarySignedRoute('account.claim', now()->addDays(7), ['user' => $this->user->id]),
            ]
        );
    }
}
