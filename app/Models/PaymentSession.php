<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Represents a payment session container for inline and embedded checkouts/deposits.
 *
 * @property string $id
 * @property string $payment_attempt_id
 * @property string $provider
 * @property string $session_type
 * @property string $status
 * @property string $client_reference
 * @property string|null $provider_reference
 * @property string|null $provider_transaction_id
 * @property float $amount
 * @property string $currency
 * @property string $display_currency
 * @property float $exchange_rate_snapshot
 * @property array|null $payment_payload
 * @property array|null $checkout_context
 * @property string $customer_email
 * @property string|null $customer_ip
 * @property array|null $device_metadata
 * @property Carbon|null $expires_at
 * @property Carbon|null $confirmed_at
 * @property Carbon|null $failed_at
 * @property array|null $metadata
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class PaymentSession extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'payment_attempt_id',
        'provider',
        'session_type',
        'status',
        'client_reference',
        'provider_reference',
        'provider_transaction_id',
        'amount',
        'currency',
        'display_currency',
        'exchange_rate_snapshot',
        'payment_payload',
        'checkout_context',
        'customer_email',
        'customer_ip',
        'device_metadata',
        'expires_at',
        'confirmed_at',
        'failed_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:4',
            'exchange_rate_snapshot' => 'decimal:4',
            'payment_payload' => 'array',
            'checkout_context' => 'array',
            'device_metadata' => 'array',
            'metadata' => 'array',
            'expires_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    /**
     * Get the associated PaymentAttempt.
     */
    public function paymentAttempt(): BelongsTo
    {
        return $this->belongsTo(PaymentAttempt::class);
    }

    /**
     * Get the Order associated with this session.
     */
    public function order()
    {
        return $this->hasOneThrough(
            Order::class,
            PaymentAttempt::class,
            'id', // Foreign key on payment_attempts table
            'id', // Foreign key on orders table
            'payment_attempt_id', // Local key on payment_sessions table
            'order_id' // Local key on payment_attempts table
        );
    }

    /**
     * Get the WalletFunding associated with this session.
     */
    public function walletFunding()
    {
        return $this->hasOneThrough(
            WalletFunding::class,
            PaymentAttempt::class,
            'id', // Foreign key on payment_attempts table
            'id', // Foreign key on wallet_fundings table
            'payment_attempt_id', // Local key on payment_sessions table
            'payable_id' // Local key on payment_attempts table
        )->where('payable_type', WalletFunding::class);
    }

    /**
     * Advance the session status strictly via the allowed transition matrix.
     *
     * Allowed statuses:
     * pending, awaiting_method, awaiting_customer_action, awaiting_transfer,
     * awaiting_redirect, awaiting_confirmation, processing, confirmed, failed,
     * expired, cancelled.
     */
    public function transitionTo(string $newStatus): void
    {
        $allowedTransitions = [
            'pending' => ['awaiting_payment', 'awaiting_method', 'awaiting_transfer', 'awaiting_redirect', 'awaiting_customer_action', 'cancelled'],
            'awaiting_payment' => ['processing', 'expired', 'cancelled', 'confirmed', 'failed'],
            'awaiting_method' => ['awaiting_payment', 'awaiting_customer_action', 'awaiting_transfer', 'awaiting_redirect', 'awaiting_confirmation', 'processing', 'confirmed', 'failed', 'expired', 'cancelled'],
            'awaiting_customer_action' => ['processing', 'confirmed', 'failed', 'expired', 'cancelled'],
            'awaiting_transfer' => ['awaiting_confirmation', 'processing', 'confirmed', 'failed', 'expired', 'cancelled'],
            'awaiting_redirect' => ['processing', 'confirmed', 'failed', 'expired', 'cancelled'],
            'awaiting_confirmation' => ['processing', 'confirmed', 'failed', 'expired', 'cancelled'],
            'processing' => ['confirmed', 'failed'],
            'confirmed' => [],
            'failed' => [],
            'expired' => [],
            'cancelled' => [],
        ];

        $currentStatus = $this->status;

        // Idempotent transition
        if ($currentStatus === $newStatus) {
            return;
        }

        if (! isset($allowedTransitions[$currentStatus]) || ! in_array($newStatus, $allowedTransitions[$currentStatus])) {
            throw new \DomainException("Invalid payment session status transition from {$currentStatus} to {$newStatus}.");
        }

        $this->status = $newStatus;

        if ($newStatus === 'confirmed') {
            $this->confirmed_at = now();
        } elseif ($newStatus === 'failed') {
            $this->failed_at = now();
        }

        $this->save();
    }

    /**
     * Resolve the available payment methods dynamically based on session currency and configuration.
     */
    public function getAvailableMethods(): array
    {
        $currency = strtoupper($this->currency);
        $methods = [];

        // If Wallet session, return wallet payment
        if ($this->session_type === 'wallet' || $currency === 'WALLET') {
            return [
                [
                    'type' => 'wallet',
                    'provider' => 'wallet',
                    'label' => 'Wallet Balance',
                    'description' => 'Pay instantly from your wallet balance',
                ],
            ];
        }

        // If Crypto session
        $cryptoCurrencies = ['BTC', 'ETH', 'USDT', 'LTC'];
        if ($this->session_type === 'crypto' || in_array($currency, $cryptoCurrencies)) {
            $coin = strtolower($currency);
            if ($coin === 'crypto') {
                $coin = 'usdt'; // fallback/default
            }
            $payload = $this->payment_payload;

            return [
                [
                    'type' => 'crypto',
                    'provider' => 'nowpayments',
                    'label' => 'Crypto Transfer',
                    'description' => 'Pay via USDT, BTC, ETH, LTC',
                    'coin' => $coin,
                    'pay_address' => $payload['pay_address'] ?? null,
                    'pay_amount' => $payload['pay_amount'] ?? null,
                    'pay_currency' => $payload['pay_currency'] ?? $coin,
                    'network' => $payload['network'] ?? null,
                    'qr_payload' => $payload['qr_payload'] ?? null,
                    'expires_at' => $payload['expires_at'] ?? null,
                ],
            ];
        }

        // For Fiat currencies via Flutterwave
        // 1. Card is always available for fiat
        $methods[] = [
            'type' => 'card',
            'provider' => 'flutterwave',
            'label' => 'Card Payment',
            'description' => 'Visa, Mastercard, Verve',
            'supported_brands' => ['visa', 'mastercard', 'verve'],
        ];

        // 2. Apple Pay is supported globally (USD, EUR, GBP, NGN, GHS, XAF, XOF)
        if (in_array($currency, ['USD', 'EUR', 'GBP', 'NGN', 'GHS', 'XAF', 'XOF'])) {
            $methods[] = [
                'type' => 'apple_pay',
                'provider' => 'flutterwave',
                'label' => 'Apple Pay',
                'description' => 'Pay securely with Apple Wallet',
            ];
        }

        // 3. NGN specific payment methods
        if ($currency === 'NGN') {
            $payload = $this->payment_payload;
            $methods[] = [
                'type' => 'bank_transfer',
                'provider' => 'flutterwave',
                'label' => 'Bank Transfer',
                'description' => 'Transfer directly to a dynamic virtual account',
                'bank_name' => $payload['bank_name'] ?? null,
                'account_number' => $payload['account_number'] ?? null,
                'account_name' => $payload['account_name'] ?? null,
                'amount' => $payload['amount'] ?? $this->amount,
                'expires_at' => $payload['expires_at'] ?? null,
            ];
            $methods[] = [
                'type' => 'opay',
                'provider' => 'flutterwave',
                'label' => 'Opay / Pocket',
                'description' => 'Pay instantly with your Opay wallet',
            ];
            $methods[] = [
                'type' => 'ussd',
                'provider' => 'flutterwave',
                'label' => 'USSD Code',
                'description' => 'Dial a code to pay from your bank account',
            ];
        }

        // 4. XAF, GHS specific payment methods (Mobile Money)
        if (in_array($currency, ['XAF', 'XOF', 'GHS'])) {
            $methods[] = [
                'type' => 'mobile_money',
                'provider' => 'flutterwave',
                'label' => 'Mobile Money',
                'description' => $currency === 'GHS' ? 'MTN, Vodafone, AirtelTigo Mobile Money' : 'MTN Mobile Money or Orange Money',
                'supported_networks' => $currency === 'GHS' ? ['MTN', 'Vodafone', 'AirtelTigo'] : ['MTN', 'Orange'],
            ];
        }

        // 5. Crypto is supported globally (USD, EUR, GBP, NGN, GHS, XAF, XOF)
        if (in_array($currency, ['USD', 'EUR', 'GBP', 'NGN', 'GHS', 'XAF', 'XOF'])) {
            $methods[] = [
                'type' => 'crypto',
                'provider' => 'nowpayments',
                'label' => 'Crypto Transfer',
                'description' => 'Pay via USDT, BTC, ETH, LTC',
            ];
        }

        return $methods;
    }
}
