<?php

namespace App\Domain\Notification\Mail;

use App\Models\SiteSetting;
use App\Models\User;
use App\Models\WalletTransaction;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class WalletDebitedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly User $user,
        public readonly WalletTransaction $transaction
    ) {}

    public function envelope(): Envelope
    {
        $brand = SiteSetting::get('site.name', 'RshopRefills');

        return new Envelope(
            subject: 'Wallet Debit Notification - '.$brand,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.wallet.debited',
            with: [
                'name' => $this->user->name,
                'amount' => $this->transaction->amount,
                'currency' => $this->transaction->currency->value,
                'description' => $this->transaction->description,
                'reference' => $this->transaction->reference,
                'balanceAfter' => $this->transaction->balance_after,
            ]
        );
    }
}
