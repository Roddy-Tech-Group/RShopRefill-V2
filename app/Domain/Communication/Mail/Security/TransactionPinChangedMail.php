<?php

namespace App\Domain\Communication\Mail\Security;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class TransactionPinChangedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $user
    ) {
        // Explicit queue: keeps one workload from starving unrelated ones (bulkhead).
        $this->onQueue('comm-critical');
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Wallet Transaction PIN Changed',
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.wallet.pin_changed',
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
