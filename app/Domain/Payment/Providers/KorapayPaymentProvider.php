<?php

namespace App\Domain\Payment\Providers;

use App\Domain\Payment\Enums\PaymentStatus;
use App\Domain\Payment\Interfaces\PaymentProviderInterface;
use App\Domain\Payment\Support\GatewayFailure;
use App\Domain\Payment\Support\MockMode;
use App\Models\PaymentAttempt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Kora Pay, the second fiat BACKUP gateway. Receives traffic only when
 * FiatGatewayRouter fails a method over to it (Flutterwave off, method and
 * currency inside Kora's coverage), which in practice means the francophone
 * mobile-money corridors (XAF/XOF plus GHS/KES/EGP/TZS) and the NGN bank
 * rails that Paystack does not front.
 *
 * Same hosted-redirect contract as the Paystack backup: initialize a
 * channel-restricted charge (POST /charges/initialize), redirect the
 * customer to the returned checkout_url, verify on return and on webhook
 * via GET /charges/:reference. Card data never touches us.
 *
 * Kora specifics that differ from Paystack and WILL bite if forgotten:
 *  - amounts are MAJOR units (₦1000 is 1000, not 100000) both out and in;
 *  - webhooks sign json_encode of the `data` OBJECT ONLY, not the raw body,
 *    HMAC-SHA256 with the secret key (handled in VerifyWebhookSignature);
 *  - the redirect back carries only `?reference=`, no status field, so the
 *    verify call is the only signal on the return leg.
 */
class KorapayPaymentProvider implements PaymentProviderInterface
{
    private string $secretKey;

    private string $baseUrl;

    public function __construct()
    {
        $secretKey = config('services.korapay.secret_key');

        // Fail closed, same contract as the other fiat providers.
        if (empty($secretKey) && ! MockMode::allowed()) {
            throw new \RuntimeException('Kora Pay secret key (KORAPAY_SECRET_KEY) is not configured. Refusing to fall back to mock credentials outside local/testing.');
        }

        $this->secretKey = $secretKey ?: 'KORAPAY_SECRET_KEY_MOCK';
        $this->baseUrl = 'https://api.korapay.com/merchant/api/v1';
    }

    /**
     * Cheap local init at order placement; the real gateway call happens at
     * pay() time via chargeHosted(), once the customer commits to a method.
     */
    public function initializePayment(PaymentAttempt $attempt): array
    {
        $attempt->gateway_reference = $attempt->idempotency_key;
        $attempt->payment_url = null;
        $attempt->save();

        return [
            'provider' => 'korapay',
            'mode' => 'hosted',
            'tx_ref' => $attempt->idempotency_key,
            'gateway_reference' => $attempt->idempotency_key,
        ];
    }

    /**
     * Initialise a channel-restricted hosted checkout and hand back the
     * redirect. Same signature and return contract as the other backups.
     */
    public function chargeHosted(PaymentAttempt $attempt, string $methodKey, string $returnUrl): array
    {
        // Kora's channel vocabulary: bank_transfer, card, pay_with_bank,
        // mobile_money. The router only routes methods Kora covers, so an
        // unknown key here means the capability matrix and this map drifted.
        $channel = match ($methodKey) {
            'card' => 'card',
            'bank_transfer' => 'bank_transfer',
            'pay_with_bank' => 'pay_with_bank',
            'mobile_money' => 'mobile_money',
            default => $methodKey,
        };

        if (str_contains($this->secretKey, 'MOCK')) {
            $mockUrl = $returnUrl.(str_contains($returnUrl, '?') ? '&' : '?').'reference='.$attempt->idempotency_key.'&mock=1';

            return [
                'status' => 'awaiting_redirect',
                'redirect_url' => $mockUrl,
                'method' => $methodKey,
            ];
        }

        try {
            $response = Http::withToken($this->secretKey)
                ->post("{$this->baseUrl}/charges/initialize", [
                    'reference' => $attempt->idempotency_key,
                    // MAJOR units: ₦16,500 goes out as 16500.
                    'amount' => (float) $attempt->amount,
                    'currency' => strtoupper($attempt->currency),
                    'customer' => [
                        'email' => $attempt->user->email,
                        'name' => $attempt->user->name,
                    ],
                    'redirect_url' => $returnUrl,
                    'channels' => [$channel],
                    'default_channel' => $channel,
                    'narration' => $attempt->order
                        ? "Order #{$attempt->order->order_number}"
                        : "Payment Ref #{$attempt->idempotency_key}",
                ]);

            if ($response->failed()) {
                Log::error('Korapay hosted init HTTP failed', [
                    'status' => $response->status(),
                    'body' => $response->json(),
                    'reference' => $attempt->idempotency_key,
                    'method_key' => $methodKey,
                ]);

                return [
                    'status' => 'failed',
                    'message' => 'Failed to initialise payment: '.($response->json('message') ?? 'Unknown error'),
                    'failure' => GatewayFailure::fromKorapay($response->json(), $response->status()),
                ];
            }

            $data = $response->json();
            $link = $data['data']['checkout_url'] ?? null;

            if (($data['status'] ?? false) !== true || ! $link) {
                Log::error('Korapay hosted init missing checkout url', [
                    'body' => $data,
                    'reference' => $attempt->idempotency_key,
                    'method_key' => $methodKey,
                ]);

                return [
                    'status' => 'failed',
                    'message' => 'Payment provider returned no checkout link.',
                    'failure' => GatewayFailure::internal('missing_checkout_link', 'Gateway returned success without a hosted checkout link'),
                ];
            }

            $attempt->payment_url = $link;
            $attempt->save();

            return [
                'status' => 'awaiting_redirect',
                'redirect_url' => $link,
                'method' => $methodKey,
            ];
        } catch (\Exception $e) {
            Log::error('Korapay hosted init exception', [
                'message' => $e->getMessage(),
                'reference' => $attempt->idempotency_key,
                'method_key' => $methodKey,
            ]);

            return [
                'status' => 'failed',
                'message' => 'Failed to initialise payment: '.$e->getMessage(),
                'failure' => GatewayFailure::fromException($e),
            ];
        }
    }

    public function verifyPayment(PaymentAttempt $attempt): bool
    {
        if (str_contains($this->secretKey, 'MOCK')) {
            $attempt->payment_status = PaymentStatus::Paid;
            $attempt->confirmed_at = now();
            $attempt->save();

            return true;
        }

        try {
            $response = Http::withToken($this->secretKey)
                ->get("{$this->baseUrl}/charges/".rawurlencode($attempt->idempotency_key));

            if ($response->failed()) {
                Log::error('Korapay transaction verification failed', [
                    'status' => $response->status(),
                    'reference' => $attempt->idempotency_key,
                ]);

                return false;
            }

            $data = $response->json('data') ?? [];

            // Cross-bind, same defence as every other provider: the reported
            // reference must be OURS before any status is believed.
            $reportedRef = (string) ($data['reference'] ?? '');
            if ($reportedRef === '' || ! hash_equals((string) $attempt->idempotency_key, $reportedRef)) {
                Log::warning('Korapay verification rejected: reference mismatch', [
                    'attempt_id' => $attempt->id,
                    'expected' => $attempt->idempotency_key,
                    'reported' => $reportedRef,
                ]);

                return false;
            }

            // MAJOR units, arrives as a string ("2000.00"). amount_paid can
            // trail amount on partial payments, so prefer it when present.
            $paid = (float) ($data['amount_paid'] ?? $data['amount'] ?? 0);

            if (($data['status'] ?? null) === 'success'
                && $paid >= (float) $attempt->amount
                && strtoupper((string) ($data['currency'] ?? '')) === strtoupper((string) $attempt->currency)
            ) {
                $attempt->payment_status = PaymentStatus::Paid;
                $attempt->confirmed_at = now();
                $attempt->verification_payload = $response->json();
                $attempt->save();

                return true;
            }

            return false;
        } catch (\Exception $e) {
            Log::error('Korapay verification error: '.$e->getMessage());

            return false;
        }
    }

    public function refundPayment(PaymentAttempt $attempt, float $amount): bool
    {
        if (str_contains($this->secretKey, 'MOCK')) {
            $attempt->payment_status = PaymentStatus::Refunded;
            $attempt->save();

            return true;
        }

        try {
            $response = Http::withToken($this->secretKey)
                ->post("{$this->baseUrl}/refunds/initiate", [
                    'payment_reference' => $attempt->idempotency_key,
                    'reference' => 'RF-'.Str::upper(Str::random(20)),
                    'amount' => $amount,
                    'reason' => 'RshopRefills refund',
                ]);

            if ($response->failed() || ($response->json('status') ?? false) !== true) {
                Log::error('Korapay refund failed', ['body' => $response->json()]);

                return false;
            }

            $attempt->payment_status = PaymentStatus::Refunded;
            $attempt->save();

            return true;
        } catch (\Exception $e) {
            Log::error('Korapay refund error: '.$e->getMessage());

            return false;
        }
    }

    public function normalizeWebhook(array $payload): array
    {
        $data = $payload['data'] ?? [];

        return [
            'status' => $data['status'] ?? null,
            'amount' => (float) ($data['amount'] ?? 0),
            'currency' => $data['currency'] ?? 'NGN',
            'transaction_id' => $data['reference'] ?? null,
            'reference' => $data['payment_reference'] ?? ($data['reference'] ?? null),
        ];
    }
}
