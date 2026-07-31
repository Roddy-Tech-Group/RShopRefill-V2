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
 * Sent when an admin marks a pending withdrawal `approved` OR `paid`. The
 * `$paid` flag drives the copy: approved-but-not-paid says "we'll send the
 * funds within 24h"; paid-with-reference says "here's your payout ref".
 */
class RcoinWithdrawalApprovedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public RcoinWithdrawal $withdrawal,
        public bool $paid = false,
    ) {
        // Explicit queue: keeps one workload from starving unrelated ones (bulkhead).
        $this->onQueue('comm-transactional');
    }

    public function envelope(): Envelope
    {
        $subject = $this->paid
            ? '✅ Your Rcoin withdrawal has been paid'
            : '👍 Your Rcoin withdrawal has been approved';

        return new Envelope(subject: $subject);
    }

    public function content(): Content
    {
        return new Content(view: 'emails.rcoin.withdrawal-approved');
    }
}
