<?php

namespace App\Domain\Payment\Providers;

use App\Domain\Payment\Enums\PaymentStatus;
use App\Domain\Payment\Interfaces\PaymentProviderInterface;
use App\Domain\Payment\Support\MockMode;
use App\Domain\Wallet\Services\CurrencyRateService;
use App\Models\PaymentAttempt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class NowPaymentsProvider implements PaymentProviderInterface
{
    private string $apiKey;

    private string $baseUrl;

    public function __construct()
    {
        $apiKey = config('services.nowpayments.api_key');

        // Fail closed: outside local/testing (and without PAYMENT_MOCK=true) a
        // missing API key must hard-fail rather than silently process crypto
        // payments against the mock gateway.
        if (empty($apiKey) && ! MockMode::allowed()) {
            throw new \RuntimeException('NowPayments API key (NOWPAYMENTS_API_KEY) is not configured. Refusing to fall back to mock credentials outside local/testing.');
        }

        $this->apiKey = $apiKey ?: 'NOWPAYMENTS_KEY_MOCK';
        $this->baseUrl = config('services.nowpayments.base_url') ?: 'https://api.nowpayments.io/v1';
    }

    public function initializePayment(PaymentAttempt $attempt): array
    {
        $txRef = $attempt->idempotency_key;

        // If mock key, return simulated crypto invoice
        if (str_contains($this->apiKey, 'MOCK')) {
            $gatewayRef = 'NP-MOCK-'.uniqid();
            $payAddress = '1A1zP1eP5QGefi2DMPTfTL5SLmv7DivfNa';
            $payAmount = round($attempt->amount * 0.000015, 6);
            $payCurrency = 'btc';
            $network = 'bitcoin';
            $expiresAt = now()->addMinutes(30)->toIso8601String();

            $attempt->gateway_reference = $gatewayRef;
            $attempt->payment_url = null;
            $attempt->save();

            return [
                'provider' => 'nowpayments',
                'mode' => 'embedded_crypto',
                'invoice_id' => $gatewayRef,
                'pay_address' => $payAddress,
                'pay_amount' => (string) $payAmount,
                'pay_currency' => strtolower($payCurrency),
                'network' => $network,
                'qr_payload' => "{$network}:{$payAddress}?amount={$payAmount}",
                'expires_at' => $expiresAt,
                'confirmations_required' => 2,
                'gateway_reference' => $gatewayRef,
            ];
        }

        $priceCurrency = strtoupper($attempt->currency);
        $priceAmount = $attempt->amount;

        // Convert to USD if the currency is not supported by NowPayments.
        $supportedFiats = ['USD', 'EUR', 'GBP', 'CAD', 'AUD', 'AED', 'CZK', 'DKK', 'HKD', 'HUF', 'ILS', 'INR', 'JPY', 'MXN', 'NOK', 'NZD', 'PLN', 'RUB', 'SEK', 'SGD', 'THB', 'TRY', 'ZAR'];
        if (! in_array($priceCurrency, $supportedFiats)) {
            $rateService = app(CurrencyRateService::class);
            $rate = $rateService->resolveRate($priceCurrency, 'USD');
            $priceAmount = round($priceAmount * $rate, 2);
            $priceCurrency = 'USD';
        }

        try {
            $response = Http::withHeaders([
                'x-api-key' => $this->apiKey,
                'Content-Type' => 'application/json',
            ])->post("{$this->baseUrl}/payment", [
                'price_amount' => $priceAmount,
                'price_currency' => $priceCurrency,
                'pay_currency' => 'btc', // default to BTC or allow selection in metadata
                'ipn_callback_url' => route('api.webhooks.nowpayments'),
                'order_id' => $attempt->order->id,
                'order_description' => "Order #{$attempt->order->order_number}",
                'is_fee_paid_by_user' => true,
            ]);

            if ($response->failed()) {
                Log::error('NowPayments invoice creation failed', [
                    'status' => $response->status(),
                    'body' => $response->json(),
                ]);
                throw new \Exception('Failed to initiate Crypto billing.');
            }

            $body = $response->json();
            $gatewayRef = $body['payment_id'] ?? null;
            $payAddress = $body['pay_address'] ?? '1A1zP1eP5QGefi2DMPTfTL5SLmv7DivfNa';
            $payAmount = $body['pay_amount'] ?? round($attempt->amount * 0.000015, 6);
            $payCurrency = $body['pay_currency'] ?? 'btc';
            $network = $this->resolveNetwork($payCurrency);
            $expiresAt = $body['time_limit'] ?? now()->addMinutes(30)->toIso8601String();

            $attempt->payment_url = null;
            $attempt->gateway_reference = $gatewayRef;
            $attempt->save();

            return [
                'provider' => 'nowpayments',
                'mode' => 'embedded_crypto',
                'invoice_id' => $gatewayRef,
                'pay_address' => $payAddress,
                'pay_amount' => (string) $payAmount,
                'pay_currency' => strtolower($payCurrency),
                'network' => $network,
                'qr_payload' => "{$network}:{$payAddress}?amount={$payAmount}",
                'expires_at' => $expiresAt,
                'confirmations_required' => 2,
                'gateway_reference' => $gatewayRef,
            ];
        } catch (\Exception $e) {
            Log::error('NowPayments API error: '.$e->getMessage());
            throw $e;
        }
    }

    /**
     * Normalize a coin code to the ticker NOWPayments accepts. The bare "usdt"
     * and "bnb" tickers are rejected as invalid currencies, so every outbound
     * call must translate them to a concrete chain (usdt -> usdttrc20,
     * bnb -> bnbbsc). All other tickers pass through lowercased.
     */
    private function normalizePayCurrency(string $payCurrency): string
    {
        $payCurrency = strtolower($payCurrency);

        return match ($payCurrency) {
            'usdt' => 'usdttrc20',
            'bnb' => 'bnbbsc',
            default => $payCurrency,
        };
    }

    private function resolveNetwork(string $currency): string
    {
        return match (strtolower($currency)) {
            'btc' => 'bitcoin',
            'eth' => 'ethereum',
            'usdt', 'usdttrc20' => 'tron',
            'usdterc20' => 'ethereum',
            'usdtbsc', 'usdtbep20' => 'bnb',
            'usdtmatic', 'usdtpolygon' => 'polygon',
            'usdtsol' => 'solana',
            'ltc' => 'litecoin',
            'bnb', 'bnbbsc' => 'bnb',
            'matic' => 'polygon',
            'sol' => 'solana',
            'trx' => 'tron',
            default => strtolower($currency),
        };
    }

    public function chargeCrypto(PaymentAttempt $attempt, string $payCurrency): array
    {
        $payCurrency = $this->normalizePayCurrency($payCurrency);
        $txRef = $attempt->idempotency_key;

        if (str_contains($this->apiKey, 'MOCK')) {
            $gatewayRef = 'NP-MOCK-'.uniqid();
            $payAddress = $this->resolveMockAddress($payCurrency);
            $rate = match ($payCurrency) {
                'btc' => 0.000015,
                'eth' => 0.0003,
                'usdt' => 1.0,
                'ltc' => 0.012,
                default => 1.0,
            };
            $payAmount = round($attempt->amount * $rate, 6);
            $network = $this->resolveNetwork($payCurrency);
            $expiresAt = now()->addMinutes(30)->toIso8601String();

            $attempt->gateway_reference = $gatewayRef;
            $attempt->save();

            return [
                'status' => 'awaiting_transfer',
                'invoice_id' => $gatewayRef,
                'pay_address' => $payAddress,
                'pay_amount' => (string) $payAmount,
                'pay_currency' => $payCurrency,
                'network' => $network,
                'qr_payload' => "{$network}:{$payAddress}?amount={$payAmount}",
                'expires_at' => now()->addMinutes(30)->toIso8601String(),
            ];
        }

        $priceCurrency = strtoupper($attempt->currency);
        $priceAmount = $attempt->amount;

        // NowPayments supported fiat currencies (approximate main list).
        // If it's an African local currency or unsupported fiat, we fall back to USD.
        $supportedFiats = ['USD', 'EUR', 'GBP', 'CAD', 'AUD', 'AED', 'CZK', 'DKK', 'HKD', 'HUF', 'ILS', 'INR', 'JPY', 'MXN', 'NOK', 'NZD', 'PLN', 'RUB', 'SEK', 'SGD', 'THB', 'TRY', 'ZAR'];
        if (! in_array($priceCurrency, $supportedFiats)) {
            $rateService = app(CurrencyRateService::class);
            $rate = $rateService->resolveRate($priceCurrency, 'USD');
            $priceAmount = round($priceAmount * $rate, 2);
            $priceCurrency = 'USD';
        }

        try {
            $orderId = $attempt->order ? $attempt->order->id : null;
            $description = $attempt->order ? "Order #{$attempt->order->order_number}" : "Wallet Deposit Ref #{$attempt->payable->reference}";

            $payload = [
                'price_amount' => $priceAmount,
                'price_currency' => $priceCurrency,
                'pay_currency' => $payCurrency,
                'ipn_callback_url' => route('api.webhooks.nowpayments'),
                'order_description' => $description,
                'is_fee_paid_by_user' => true,
            ];
            if ($orderId) {
                $payload['order_id'] = $orderId;
            }

            $response = Http::withHeaders([
                'x-api-key' => $this->apiKey,
                'Content-Type' => 'application/json',
            ])->post("{$this->baseUrl}/payment", $payload);

            if ($response->failed()) {
                Log::error('NowPayments invoice creation failed in chargeCrypto', [
                    'status' => $response->status(),
                    'body' => $response->json(),
                ]);

                return [
                    'status' => 'failed',
                    'message' => 'Failed to initiate Crypto billing: '.($response->json('message') ?? 'Unknown error'),
                ];
            }

            $body = $response->json();
            $gatewayRef = $body['payment_id'] ?? null;
            $payAddress = $body['pay_address'] ?? '';
            $payAmount = $body['pay_amount'] ?? 0;
            $network = $this->resolveNetwork($payCurrency);
            $expiresAt = $body['time_limit'] ?? now()->addMinutes(30)->toIso8601String();

            $attempt->gateway_reference = $gatewayRef;
            $attempt->save();

            return [
                'status' => 'awaiting_transfer',
                'invoice_id' => $gatewayRef,
                'pay_address' => $payAddress,
                'pay_amount' => (string) $payAmount,
                'pay_currency' => $payCurrency,
                'network' => $network,
                'qr_payload' => "{$network}:{$payAddress}?amount={$payAmount}",
                'expires_at' => $expiresAt,
            ];
        } catch (\Exception $e) {
            Log::error('NowPayments chargeCrypto API error: '.$e->getMessage());

            return [
                'status' => 'failed',
                'message' => 'Crypto initiation failed: '.$e->getMessage(),
            ];
        }
    }

    private function resolveMockAddress(string $currency): string
    {
        return match (strtolower($currency)) {
            'btc' => '1A1zP1eP5QGefi2DMPTfTL5SLmv7DivfNa',
            'eth' => '0x742d35Cc6634C0532925a3b844Bc454e4438f44e',
            'usdt' => 'TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t',
            'ltc' => 'LVKw7y21M3tA9JtFh69N2y3Y6L5w6M3Kfd',
            default => '1A1zP1eP5QGefi2DMPTfTL5SLmv7DivfNa',
        };
    }

    public function verifyPayment(PaymentAttempt $attempt): bool
    {
        if (str_contains($this->apiKey, 'MOCK')) {
            $attempt->payment_status = PaymentStatus::Paid;
            $attempt->confirmed_at = now();
            $attempt->save();

            return true;
        }

        try {
            // gateway_reference holds the NOWPayments payment_id, so the status
            // lives at GET /payment/{id} (the old /invoice/{id} endpoint is a
            // different flow and 404s here), and the field is payment_status.
            $paymentId = $attempt->gateway_reference;
            $response = Http::withHeaders([
                'x-api-key' => $this->apiKey,
            ])->get("{$this->baseUrl}/payment/{$paymentId}");

            if ($response->failed()) {
                Log::error("NowPayments verify failed for payment: {$paymentId}");

                return false;
            }

            $body = $response->json();
            $status = strtolower((string) ($body['payment_status'] ?? ''));

            // confirmed/sending/finished all mean the customer's crypto has landed
            // (mirrors the webhook trigger set); partially_paid/failed/expired do not.
            if (in_array($status, ['confirmed', 'sending', 'finished', 'paid', 'completed'])) {
                $attempt->payment_status = PaymentStatus::Paid;
                $attempt->confirmed_at = now();
                $attempt->verification_payload = $body;
                $attempt->save();

                return true;
            }

            return false;
        } catch (\Exception $e) {
            Log::error('NowPayments verify error: '.$e->getMessage());

            return false;
        }
    }

    public function refundPayment(PaymentAttempt $attempt, float $amount): bool
    {
        // NowPayments typically does not support automatic REST refunds without custom API integrations per wallet.
        // We will mark it as refunded locally for audit compliance.
        $attempt->payment_status = PaymentStatus::Refunded;
        $attempt->save();

        return true;
    }

    public function normalizeWebhook(array $payload): array
    {
        return [
            'status' => $payload['payment_status'] ?? null,
            'amount' => $payload['price_amount'] ?? 0,
            'currency' => $payload['price_currency'] ?? 'USD',
            'transaction_id' => $payload['payment_id'] ?? null,
            'reference' => $payload['invoice_id'] ?? null,
        ];
    }

    /**
     * Query NOWPayments /estimate endpoint for the approximate crypto amount
     * the customer would pay for a given fiat value. Used by the checkout
     * fee breakdown to show estimated costs BEFORE invoice creation.
     *
     * @return array{estimated_amount: float, rate: float}|null
     */
    public function estimatePayment(float $priceAmount, string $priceCurrency, string $payCurrency): ?array
    {
        if (str_contains($this->apiKey, 'MOCK')) {
            $mockRate = match (strtolower($payCurrency)) {
                'btc' => 0.000015,
                'eth' => 0.0003,
                'usdt', 'usdttrc20', 'usdterc20', 'usdtbsc', 'usdtmatic', 'usdtsol' => 1.0,
                'ltc' => 0.012,
                'bnb', 'bnbbsc' => 0.0016,
                'matic' => 1.25,
                'sol' => 0.006,
                'trx' => 7.5,
                default => 1.0,
            };

            return [
                'estimated_amount' => round($priceAmount * $mockRate, 8),
                'rate' => $mockRate,
            ];
        }

        try {
            $response = Http::withHeaders([
                'x-api-key' => $this->apiKey,
            ])->get("{$this->baseUrl}/estimate", [
                'amount' => $priceAmount,
                'currency_from' => strtolower($priceCurrency),
                'currency_to' => $this->normalizePayCurrency($payCurrency),
            ]);

            if ($response->failed()) {
                Log::warning('NowPayments estimate failed', [
                    'status' => $response->status(),
                    'body' => $response->json(),
                ]);

                return null;
            }

            $body = $response->json();

            return [
                'estimated_amount' => (float) ($body['estimated_amount'] ?? 0),
                'rate' => $priceAmount > 0 ? ((float) ($body['estimated_amount'] ?? 0)) / $priceAmount : 0,
            ];
        } catch (\Exception $e) {
            Log::warning('NowPayments estimate error: '.$e->getMessage());

            return null;
        }
    }
}
