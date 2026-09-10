<?php

namespace App\Models;

use App\Domain\PaymentInsights\Enums\ProductLine;
use App\Domain\PaymentInsights\Enums\RecoveryStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TransactionRecovery extends Model
{
    protected $fillable = [
        'token',
        'payment_session_id',
        'payment_outcome_id',
        'user_id',
        'customer_email',
        'failure_reason_code',
        'status',
        'clicked_at',
        'retried_at',
        'recovered_at',
        'reminder_sent_at',
        'recovered_payment_session_id',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => RecoveryStatus::class,
            'expires_at' => 'datetime',
            'clicked_at' => 'datetime',
            'retried_at' => 'datetime',
            'recovered_at' => 'datetime',
            'reminder_sent_at' => 'datetime',
        ];
    }

    public function paymentSession(): BelongsTo
    {
        return $this->belongsTo(PaymentSession::class);
    }

    public function paymentOutcome(): BelongsTo
    {
        return $this->belongsTo(PaymentOutcome::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** The link is still live and the customer can still act on it. */
    public function isOpen(): bool
    {
        return in_array($this->status->value, RecoveryStatus::openValues(), true)
            && $this->expires_at?->isFuture();
    }

    /**
     * What the customer was paying for. From the attempt's target, never the
     * session type: a wallet top-up paid by card is still a top-up, and the
     * copy has to say "your top-up", not "your order".
     */
    public function productLine(): ProductLine
    {
        return $this->paymentSession?->paymentAttempt?->order_id
            ? ProductLine::Checkout
            : ProductLine::WalletFunding;
    }
}
