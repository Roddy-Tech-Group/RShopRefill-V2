<?php

namespace App\Models;

use App\Domain\Payment\Enums\PaymentStatus;
use App\Domain\Payment\Support\PaymentMethodNormaliser;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * @property string $id
 * @property string $order_id
 * @property int $user_id
 * @property string $gateway
 * @property string|null $payment_method
 * @property string|null $gateway_reference
 * @property string $idempotency_key
 * @property string $currency
 * @property float $amount
 * @property float $exchange_rate_snapshot
 * @property PaymentStatus $payment_status
 * @property string|null $payment_url
 * @property array|null $verification_payload
 * @property array|null $webhook_payload
 * @property Carbon|null $confirmed_at
 * @property Carbon|null $failed_at
 * @property Carbon|null $expires_at
 * @property array|null $metadata
 */
class PaymentAttempt extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'order_id',
        'user_id',
        'payable_type',
        'payable_id',
        'gateway',
        'payment_method',
        'gateway_reference',
        'idempotency_key',
        'currency',
        'amount',
        'exchange_rate_snapshot',
        'payment_status',
        'payment_url',
        'verification_payload',
        'webhook_payload',
        'confirmed_at',
        'failed_at',
        'expires_at',
        'metadata',

        // Settlement facts for a payment made in a currency other than the
        // attempt's: crypto (the coin and how much of it), and mobile money on
        // a converted rail (a USD order charged in CFA francs). Projected out
        // of metadata so they can be queried, indexed and reconciled against a
        // gateway settlement export.
        'pay_currency',
        'pay_amount',
        'actually_paid',
        'network',
        'pay_address',
        'payin_extra_id',
        'purchase_id',
        'outcome_amount',
        'outcome_currency',
        'gateway_fee',
        'settlement_delta',
    ];

    protected function casts(): array
    {
        return [
            'payment_status' => PaymentStatus::class,
            'amount' => 'decimal:4',
            'exchange_rate_snapshot' => 'decimal:4',
            'verification_payload' => 'array',
            'webhook_payload' => 'array',
            'confirmed_at' => 'datetime',
            'failed_at' => 'datetime',
            'expires_at' => 'datetime',
            'metadata' => 'array',
            'gateway_fee' => 'array',
            // Crypto amounts are NOT cast to a fixed scale. Coins differ by orders
            // of magnitude — 8 decimals for BTC against 2 for a stablecoin — and
            // rounding a satoshi-scale amount to 4 places would silently corrupt
            // the very comparison that decides whether a customer paid in full.
        ];
    }

    /**
     * Customer sent more than invoiced. Surplus is owed back to them.
     */
    public function isOverpaid(): bool
    {
        return $this->hasSettled() && (float) $this->settlement_delta > 0;
    }

    /**
     * Customer sent less than invoiced and the shortfall is still outstanding.
     * Never fulfilled automatically — however small the shortfall, absorbing
     * it can erase the margin on a refill order.
     *
     * Requires funds to have actually arrived. An unpaid payment reports
     * actually_paid = 0, and treating that as a shortfall told customers their
     * payment was short before they had made one.
     *
     * A short payment that has since been settled (see wasSettledShort()) is
     * not underpaid any more: the customer owes nothing, the order fulfils,
     * and no top-up may be offered against it.
     */
    public function isUnderpaid(): bool
    {
        return $this->hasSettled()
            && (float) $this->settlement_delta < 0
            && $this->payment_status !== PaymentStatus::Paid;
    }

    /**
     * Paid despite a recorded shortfall: either the dust rule absorbed a
     * sub-cent remainder, or an operator settled it with a stated reason.
     * The negative settlement_delta is kept as the record of what was short.
     */
    public function wasSettledShort(): bool
    {
        return $this->payment_status === PaymentStatus::Paid
            && ($this->settledAsDust() || $this->settledManually());
    }

    public function settledAsDust(): bool
    {
        return data_get($this->metadata, 'crypto.dust_settlement') !== null;
    }

    public function settledManually(): bool
    {
        return data_get($this->metadata, 'crypto.manual_settlement') !== null;
    }

    /**
     * Whether any funds have arrived against this attempt. Distinguishes
     * "not paid yet" from "paid the wrong amount" — the two look identical if you
     * only compare amounts.
     */
    public function hasSettled(): bool
    {
        return $this->settlement_delta !== null && (float) $this->actually_paid > 0;
    }

    /**
     * Record how the customer chose to pay, canonicalised.
     *
     * The method is not always known when the attempt is created: wallet funding
     * mints an attempt first and only learns the method when the customer picks
     * one on the funding sheet. So this is called from every point that learns
     * it, and is safe to call repeatedly.
     *
     * Only a confidently identified method is stored, and only when it differs
     * from what is already there — an unrecognised value must never overwrite a
     * method we already know to be true.
     *
     * This never throws. It is called on the live payment path, immediately
     * before the gateway is charged, and it records observability rather than
     * money: a write that fails here must cost us a row of telemetry, never a
     * customer's payment.
     */
    public function recordPaymentMethod(?string $method): bool
    {
        $canonical = PaymentMethodNormaliser::normalise($method);

        if ($canonical === null || $canonical === $this->payment_method) {
            return false;
        }

        try {
            $this->payment_method = $canonical;
            $this->save();

            return true;
        } catch (\Throwable $e) {
            Log::warning('Could not record payment method on attempt.', [
                'payment_attempt_id' => $this->id,
                'method' => $canonical,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function payable()
    {
        return $this->morphTo();
    }

    public function paymentSession(): HasOne
    {
        return $this->hasOne(PaymentSession::class);
    }

    /**
     * Customer-facing label for how this attempt was paid. Generic wording
     * only - gateway/provider names never reach customer surfaces. Derived
     * from the gateway's payment_type when the verification/webhook payload
     * carries one, otherwise from the gateway family.
     */
    public function customerMethodLabel(): string
    {
        $type = strtolower((string) (
            data_get($this->verification_payload, 'data.payment_type')
            ?? data_get($this->webhook_payload, 'data.payment_type')
            ?? ''
        ));

        return match (true) {
            str_contains($type, 'mobilemoney') || str_contains($type, 'momo') => 'Mobile Money payment',
            str_contains($type, 'ussd') => 'USSD payment',
            str_contains($type, 'bank') => 'Bank transfer payment',
            $type === 'card' => 'Card payment',
            in_array($this->gateway, ['nowpayments', 'crypto'], true) => 'Crypto payment',
            $this->gateway === 'wallet' => 'Wallet payment',
            default => 'Card payment',
        };
    }

    /**
     * The channel this attempt went through, in customer words: "Mobile
     * Money", "Card", "Bank transfer", "Crypto", "Wallet". Never a provider
     * name. The recorded method is trusted first, then whatever the gateway
     * reported in its payload, then the gateway family.
     */
    public function customerGatewayLabel(): string
    {
        $method = PaymentMethodNormaliser::normalise($this->payment_method)
            ?? PaymentMethodNormaliser::fromGatewayPayload($this->verification_payload)
            ?? PaymentMethodNormaliser::fromGatewayPayload($this->webhook_payload)
            ?? PaymentMethodNormaliser::normalise($this->gateway);

        return match ($method) {
            'mobile_money' => 'Mobile Money',
            'mobile_wallet' => 'Mobile wallet',
            'bank_transfer' => 'Bank transfer',
            'pay_with_bank' => 'Bank app',
            'bank_qr' => 'Bank QR',
            'ussd' => 'USSD',
            'apple_pay' => 'Apple Pay',
            'crypto' => 'Crypto',
            'wallet' => 'Wallet',
            default => 'Card',
        };
    }
}
