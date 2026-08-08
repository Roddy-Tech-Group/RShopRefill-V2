<?php

namespace App\Domain\Payment\Providers;

use App\Domain\Payment\Enums\PaymentStatus;
use App\Domain\Payment\Interfaces\PaymentProviderInterface;
use App\Domain\Payment\Support\GatewayFailure;
use App\Domain\Payment\Support\MockMode;
use App\Models\PaymentAttempt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Paystack, the fiat BACKUP gateway. Receives traffic only when
 * FiatGatewayRouter fails a method over to it (Flutterwave off, method and
 * currency inside Paystack's coverage).
 *
 * Every rail runs through Paystack's HOSTED checkout: we initialize a
 * transaction server-side (channel-restricted so the customer sees only the
 * method they picked), redirect them to the returned authorization_url, and
 * they come back through shop.checkout.return where verifyPayment() settles
 * the truth against GET /transaction/verify. Card data never touches us,
 * which is also why the PCI-restricted direct charge API is not used.
 *
 * Paystack specifics that differ from Flutterwave and WILL bite if forgotten:
 *  - amounts are integer SUBUNITS (kobo, cents): always x100 on the way out,
 *    /100 on the way in;
 *  - `status: true` in a response body means the API CALL worked, not that
 *    the payment succeeded - the payment verdict lives in data.status;
 *  - webhooks sign the RAW body with HMAC-SHA512 in x-paystack-signature
 *    (handled in VerifyWebhookSignature, not here).
 */
class PaystackPaymentProvider implements PaymentProviderInterface
{
    private string $secretKey;

    private string $baseUrl;

    public function __construct()
    {
        $secretKey = config('services.paystack.secret_key');

        // Fail closed, same contract as the Flutterwave provider: outside
        // local/testing a missing secret must hard-fail rather than silently
        // process payments against the mock gateway.
        if (empty($secretKey) && ! MockMode::allowed()) {
            throw new \RuntimeException('Paystack secret key (PAYSTACK_SECRET_KEY) is not configured. Refusing to fall back to mock credentials outside local/testing.');
        }

        $this->secretKey = $secretKey ?: 'PAYSTACK_SECRET_KEY_MOCK';
        $this->baseUrl = 'https://api.paystack.co';
    }

    /**
     * Cheap local init at order placement, mirroring Flutterwave: stamp our
     * reference and hand the wizard its routing facts. The real gateway call
     * happens at pay() time via chargeHosted(), once the customer has
     * committed to a method.
     */
    public function initializePayment(PaymentAttempt $attempt): array
    {
        $attempt->gateway_reference = $attempt->idempotency_key;
        $attempt->payment_url = null;
        $attempt->save();

        return [
            'provider' => 'paystack',
            'mode' => 'hosted',
            'tx_ref' => $attempt->idempotency_key,
            'gateway_reference' => $attempt->idempotency_key,
        ];
    }

    /**
     * Initialise a channel-restricted hosted checkout and hand back the
     * redirect. Same signature and return contract as the Flutterwave
     * chargeHosted(), so the pay() endpoint treats both gateways alike.
     *
     * @param  string  $methodKey  Our internal key from the checkout picker.
     * @param  string  $returnUrl  Where Paystack sends the customer afterwards.
     */
    public function chargeHosted(PaymentAttempt $attempt, string $methodKey, string $returnUrl): array
    {
        // Our method keys onto Paystack's channel names. One channel per
        // charge: the hosted page then renders exactly the method the
        // customer picked on OUR checkout, nothing else.
        $channel = match ($methodKey) {
            'card' => 'card',
            'apple_pay' => 'apple_pay',
            'bank_transfer' => 'bank_transfer',
            'pay_with_bank' => 'bank',
            'ussd' => 'ussd',
            'mobile_money' => 'mobile_money',
            'bank_qr' => 'qr',
            default => $methodKey,
        };

        if (str_contains($this->secretKey, 'MOCK')) {
            $mockUrl = $returnUrl.(str_contains($returnUrl, '?') ? '&' : '?').'reference='.$attempt->idempotency_key.'&status=successful&mock=1';

            return [
                'status' => 'awaiting_redirect',
                'redirect_url' => $mockUrl,
                'method' => $methodKey,
            ];
        }

        try {
            $response = Http::withToken($this->secretKey)
                ->post("{$this->baseUrl}/transaction/initialize", [
                    'reference' => $attempt->idempotency_key,
                    // Integer subunits (kobo / cents), never the major unit.
                    'amount' => (int) round(((float) $attempt->amount) * 100),
                    'currency' => strtoupper($attempt->currency),
                    'email' => $attempt->user->email,
                    'callback_url' => $returnUrl,
                    'channels' => [$channel],
                    'metadata' => [
                        'custom_fields' => [
                            [
                                'display_name' => 'Order',
                                'variable_name' => 'order_number',
                                'value' => $attempt->order?->order_number ?? $attempt->idempotency_key,
                            ],
                        ],
                    ],
                ]);

            if ($response->failed()) {
                Log::error('Paystack hosted init HTTP failed', [
                    'status' => $response->status(),
                    'body' => $response->json(),
                    'reference' => $attempt->idempotency_key,
                    'method_key' => $methodKey,
                ]);

                return [
                    'status' => 'failed',
                    'message' => 'Failed to initialise payment: '.($response->json('message') ?? 'Unknown error'),
                    'failure' => GatewayFailure::fromPaystack($response->json(), $response->status()),
                ];
            }

            $data = $response->json();
            $link = $data['data']['authorization_url'] ?? null;

            if (($data['status'] ?? false) !== true || ! $link) {
                Log::error('Paystack hosted init missing authorization url', [
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
            Log::error('Paystack hosted init exception', [
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
                ->get("{$this->baseUrl}/transaction/verify/".rawurlencode($attempt->idempotency_key));

            if ($response->failed()) {
                Log::error('Paystack transaction verification failed', [
                    'status' => $response->status(),
                    'reference' => $attempt->idempotency_key,
                ]);

                return false;
            }

            $data = $response->json('data') ?? [];

            // Cross-bind: the gateway-reported reference MUST match ours, the
            // same defence the Flutterwave provider carries against a relayed
            // id pointing at someone else's successful charge.
            $reportedRef = (string) ($data['reference'] ?? '');
            if ($reportedRef === '' || ! hash_equals((string) $attempt->idempotency_key, $reportedRef)) {
                Log::warning('Paystack verification rejected: reference mismatch', [
                    'attempt_id' => $attempt->id,
                    'expected' => $attempt->idempotency_key,
                    'reported' => $reportedRef,
                ]);

                return false;
            }

            $paidSubunits = (float) ($data['amount'] ?? 0);
            $expectedSubunits = round(((float) $attempt->amount) * 100);

            if (($data['status'] ?? null) === 'success'
                && $paidSubunits >= $expectedSubunits
                && strtoupper((string) ($data['currency'] ?? '')) === strtoupper((string) $attempt->currency)
            ) {
                $attempt->gateway_reference = (string) ($data['id'] ?? $attempt->idempotency_key);
                $attempt->payment_status = PaymentStatus::Paid;
                $attempt->confirmed_at = now();
                $attempt->verification_payload = $response->json();
                $attempt->save();

                return true;
            }

            return false;
        } catch (\Exception $e) {
            Log::error('Paystack verification error: '.$e->getMessage());

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
                ->post("{$this->baseUrl}/refund", [
                    'transaction' => $attempt->idempotency_key,
                    'amount' => (int) round($amount * 100),
                ]);

            if ($response->failed()) {
                Log::error('Paystack refund failed', ['body' => $response->json()]);

                return false;
            }

            $attempt->payment_status = PaymentStatus::Refunded;
            $attempt->save();

            return true;
        } catch (\Exception $e) {
            Log::error('Paystack refund error: '.$e->getMessage());

            return false;
        }
    }

    public function normalizeWebhook(array $payload): array
    {
        $data = $payload['data'] ?? [];

        return [
            'status' => $data['status'] ?? null,
            // Back to major units so callers compare like with like.
            'amount' => isset($data['amount']) ? ((float) $data['amount']) / 100 : 0,
            'currency' => $data['currency'] ?? 'NGN',
            'transaction_id' => $data['id'] ?? null,
            'reference' => $data['reference'] ?? null,
        ];
    }
}
