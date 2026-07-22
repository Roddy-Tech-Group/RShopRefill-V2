<?php

namespace App\Domain\Communication\Mail\Transactions;

use App\Models\SiteSetting;
use App\Models\TransactionRecovery;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PaymentRecoveryMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly TransactionRecovery $recovery,
    ) {}

    public function envelope(): Envelope
    {
        $brand = SiteSetting::get('site.name', 'RshopRefills');

        return new Envelope(
            subject: 'Complete your '.$brand.' Payment',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.transactions.payment-recovery',
            with: [
                'resumeUrl' => route('recovery.show', ['token' => $this->recovery->token]),
                'expiresAt' => $this->recovery->expires_at,
            ]
        );
    }
}
