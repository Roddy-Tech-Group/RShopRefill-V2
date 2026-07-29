<?php

namespace App\Domain\Communication\Mail\Rewards;

use App\Models\RcoinWithdrawal;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Sent when an admin rejects a pending withdrawal. Includes the admin's
 * stated reason so the customer knows what to fix. By the time this fires
 * the Rcoin has already been credited back to the user (RewardEngine
 * reverses inside the same transaction as the rejection).
 */
class RcoinWithdrawalRejectedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public RcoinWithdrawal $withdrawal,
    ) {
        // Explicit queue: keeps one workload from starving unrelated ones (bulkhead).
        $this->onQueue('comm-transactional');
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: '❌ Your Rcoin withdrawal was not approved');
    }

    public function content(): Content
    {
        return new Content(view: 'emails.rcoin.withdrawal-rejected');
    }
}
