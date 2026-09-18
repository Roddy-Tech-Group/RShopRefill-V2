<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A card the gateway refused, as the gateway itself reported it.
 *
 * Pulled after a popup session ends (closed, cancelled, swept, or confirmed on
 * a later card), one row per refused charge. The rate limiter counts each row
 * as a failed attempt and counts distinct fraud-class fingerprints toward the
 * card-testing lock, the risk engine reads the same rows as its card-testing
 * and card-reuse signals, and the insights dashboard shows the verdict instead
 * of "walked away".
 *
 * @property int $id
 * @property int|null $user_id
 * @property string|null $payment_session_id
 * @property string|null $payment_attempt_id
 * @property string $product_line
 * @property string $gateway
 * @property string|null $gateway_transaction_id
 * @property string $tx_ref
 * @property string|null $payment_method
 * @property string|null $card_fingerprint
 * @property string|null $card_brand
 * @property string|null $card_issuer_country
 * @property string|null $verdict
 * @property string $verdict_class
 * @property string|null $reason_code
 * @property string $amount
 * @property string|null $currency
 * @property string|null $customer_ip
 * @property string|null $device_uuid
 * @property Carbon $declined_at
 */
class PaymentDecline extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:4',
            'declined_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function paymentSession(): BelongsTo
    {
        return $this->belongsTo(PaymentSession::class);
    }
}
