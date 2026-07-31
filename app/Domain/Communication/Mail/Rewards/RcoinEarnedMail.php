<?php

namespace App\Domain\Communication\Mail\Rewards;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Fired by RewardEngine after a successful cashback or referral credit.
 * One mailable handles both shapes - `$kind` switches the copy between
 * "cashback on Order #X" and "referral bonus from Y's order".
 */
class RcoinEarnedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $recipient,
        public int $rcoinAmount,
        public int $newBalance,
        public string $kind,          // 'cashback' | 'referral'
        public ?string $orderNumber = null,
        public ?string $referredName = null,
    ) {
        // Explicit queue: keeps one workload from starving unrelated ones (bulkhead).
        $this->onQueue('comm-transactional');
    }

    public function envelope(): Envelope
    {
        $subject = $this->kind === 'referral'
            ? '🎉 You earned '.number_format($this->rcoinAmount).' Rcoin from a referral'
            : '🪙 You earned '.number_format($this->rcoinAmount).' Rcoin cashback';

        return new Envelope(subject: $subject);
    }

    public function content(): Content
    {
        return new Content(view: 'emails.rcoin.earned');
    }
}
