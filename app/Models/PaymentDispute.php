<?php

namespace App\Models;

use App\Domain\Risk\Enums\DisputeStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A dispute or chargeback a gateway raised against one of our payments. See
 * DisputeRecorder for how rows are written and what a new one sets off.
 *
 * @property int $id
 * @property string $gateway
 * @property string $gateway_dispute_id
 * @property string|null $payment_attempt_id
 * @property string|null $order_id
 * @property int|null $user_id
 * @property string|null $gateway_payment_reference
 * @property string|null $amount
 * @property string|null $currency
 * @property string|null $reason
 * @property string|null $stage
 * @property string $gateway_status
 * @property DisputeStatus $status
 * @property Carbon|null $due_at
 * @property Carbon $opened_at
 * @property Carbon|null $resolved_at
 * @property Carbon|null $deadline_alerted_at
 * @property string $source
 * @property array<string, mixed>|null $last_payload
 */
class PaymentDispute extends Model
{
    use HasFactory;

    protected $fillable = [
        'gateway',
        'gateway_dispute_id',
        'payment_attempt_id',
        'order_id',
        'user_id',
        'gateway_payment_reference',
        'amount',
        'currency',
        'reason',
        'stage',
        'gateway_status',
        'status',
        'due_at',
        'opened_at',
        'resolved_at',
        'deadline_alerted_at',
        'source',
        'last_payload',
    ];

    protected function casts(): array
    {
        return [
            'status' => DisputeStatus::class,
            'amount' => 'decimal:2',
            'due_at' => 'datetime',
            'opened_at' => 'datetime',
            'resolved_at' => 'datetime',
            'deadline_alerted_at' => 'datetime',
            'last_payload' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function paymentAttempt(): BelongsTo
    {
        return $this->belongsTo(PaymentAttempt::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @param  Builder<PaymentDispute>  $query */
    public function scopeOpen(Builder $query): void
    {
        $query->where('status', DisputeStatus::Open->value);
    }

    public function isOpen(): bool
    {
        return $this->status === DisputeStatus::Open;
    }

    public function gatewayLabel(): string
    {
        return match ($this->gateway) {
            'flutterwave' => 'Flutterwave',
            'korapay' => 'Kora',
            'paystack' => 'Paystack',
            default => ucfirst($this->gateway),
        };
    }

    public function amountLabel(): string
    {
        if ($this->amount === null) {
            return 'amount not given';
        }

        return trim(($this->currency ?? '').' '.number_format((float) $this->amount, 2));
    }
}
