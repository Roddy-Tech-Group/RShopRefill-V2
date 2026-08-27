<?php

namespace App\Models;

use App\Domain\Shared\Enums\Currency;
use App\Domain\Shared\Enums\FundingStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Support\Carbon;

/**
 * Tracks a wallet funding attempt.
 *
 * @property int $id
 * @property int $user_id
 * @property int $wallet_id
 * @property string $reference
 * @property Currency $currency
 * @property string $amount
 * @property string $gateway
 * @property string|null $gateway_reference
 * @property array|null $gateway_payload
 * @property FundingStatus $status
 * @property string|null $idempotency_key
 * @property Carbon|null $processed_at
 * @property string|null $failed_reason
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class WalletFunding extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'wallet_id',
        'reference',
        'currency',
        'display_currency',
        'amount',
        'requested_amount',
        'settled_amount_usd',
        'exchange_rate_snapshot',
        'gateway',
        'gateway_reference',
        'gateway_payload',
        'payment_link',
        'provider_payload_snapshot',
        'verification_payload_snapshot',
        'status',
        'idempotency_key',
        'processed_at',
        'verified_at',
        'completed_at',
        'failed_reason',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'currency' => Currency::class,
            'amount' => 'decimal:4',
            'requested_amount' => 'decimal:4',
            'settled_amount_usd' => 'decimal:4',
            'exchange_rate_snapshot' => 'decimal:4',
            'gateway_payload' => 'array',
            'provider_payload_snapshot' => 'array',
            'verification_payload_snapshot' => 'array',
            'status' => FundingStatus::class,
            'processed_at' => 'datetime',
            'verified_at' => 'datetime',
            'completed_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }

    /**
     * The payment attempt collecting this funding (polymorphic payable). A
     * funding whose attempt never got a gateway reference never reached a
     * gateway: no address was issued, no charge was started.
     */
    public function paymentAttempt(): MorphOne
    {
        return $this->morphOne(PaymentAttempt::class, 'payable');
    }
}
