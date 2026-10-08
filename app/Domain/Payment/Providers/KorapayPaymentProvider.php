<?php

namespace App\Domain\Payment\Providers;

use App\Domain\Payment\Enums\PaymentStatus;
use App\Domain\Payment\Interfaces\PaymentProviderInterface;
use App\Domain\Payment\Support\GatewayFailure;
use App\Domain\Payment\Support\MobileMoneyApproval;
use App\Domain\Payment\Support\MobileMoneyRail;
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

    /** Decided by environment policy (MockMode), never by the key alone. */
    private bool $mock;

    private string $baseUrl;

    public function __construct()
    {
        $secretKey = config('services.korapay.secret_key');

        // Fail closed, same contract as the other fiat providers.
        if (empty($secretKey) && ! MockMode::allowed()) {
            throw new \RuntimeException('Kora Pay secret key (KORAPAY_SECRET_KEY) is not configured. Refusing to fall back to mock credentials outside local/testing.');
        }

        $this->secretKey = $secretKey ?: 'KORAPAY_SECRET_KEY_MOCK';
        $this->mock = MockMode::forKey($secretKey);
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

        if ($this->mock) {
            $mockUrl = $returnUrl.(str_contains($returnUrl, '?') ? '&' : '?').'reference='.$attempt->idempotency_key.'&mock=1';

            return [
                'status' => 'awaiting_redirect',
                'redirect_url' => $mockUrl,
                'method' => $methodKey,
            ];
        }

        // MAJOR units: ₦16,500 goes out as 16500. Francs have no minor unit,
        // so a franc figure with decimals (a wallet top-up priced off a rate)
        // is rounded UP to the whole franc and recorded on the attempt: verify
        // and any refund then read what Kora was actually asked for, and the
        // platform only ever gains the rounding.
        $chargeAmount = $this->chargeAmountFor($attempt);

        try {
            $response = Http::withToken($this->secretKey)
                ->post("{$this->baseUrl}/charges/initialize", [
                    'reference' => $attempt->idempotency_key,
                    'amount' => $chargeAmount,
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

    /**
     * Mobile money through Kora's own API, so the customer stays in our
     * checkout: we send the number, Kora pushes the prompt to the phone, and
     * the customer approves with their PIN there, exactly as on Flutterwave.
     * Kora answers with one of three authorisation models: STK_PROMPT (the
     * prompt is on the phone), OTP (a one-time code to type first, Ghana and
     * Côte d'Ivoire), or REDIRECT (the operator's own page, rare).
     *
     * @return array<string, mixed>
     */
    public function chargeMobileMoney(PaymentAttempt $attempt, string $phoneNumber, ?string $network = null, ?string $returnUrl = null): array
    {
        $currency = strtoupper((string) $attempt->currency);
        $number = MobileMoneyRail::internationalNumber($currency, $phoneNumber);
        $country = MobileMoneyRail::countryFor($currency, $phoneNumber) ?? MobileMoneyRail::homeCountry($currency);
        $chargeAmount = $this->chargeAmountFor($attempt);
        $chargeLabel = number_format($chargeAmount, MobileMoneyRail::precision($currency)).' '.$currency;
        $waiting = fn (?string $message = null): array => $this->mobileMoneyWaiting($phoneNumber, $chargeLabel, $network, $country, $message);

        if ($this->mock) {
            return $waiting();
        }

        try {
            $response = Http::withToken($this->secretKey)
                ->timeout(40)
                ->post("{$this->baseUrl}/charges/mobile-money", [
                    'reference' => $attempt->idempotency_key,
                    'amount' => $chargeAmount,
                    'currency' => $currency,
                    'customer' => [
                        'email' => $attempt->user->email,
                        'name' => $attempt->user->name,
                    ],
                    'mobile_money' => ['number' => $number],
                    'notification_url' => $this->webhookUrl(),
                    'description' => $attempt->order
                        ? "Order #{$attempt->order->order_number}"
                        : "Payment Ref #{$attempt->idempotency_key}",
                ]);
        } catch (\Exception $e) {
            return [
                'status' => 'failed',
                'message' => 'Failed to initialize mobile money: '.$e->getMessage(),
                'failure' => GatewayFailure::fromException($e),
            ];
        }

        if ($response->failed() || ($response->json('status') ?? false) !== true) {
            Log::warning('Korapay mobile money charge refused', [
                'reference' => $attempt->idempotency_key,
                'currency' => $currency,
                'http_status' => $response->status(),
                'message' => $response->json('message'),
            ]);

            return [
                'status' => 'failed',
                'message' => 'Failed to initialize mobile money: '.($response->json('message') ?? 'Unknown error'),
                'failure' => GatewayFailure::fromKorapay($response->json(), $response->status()),
            ];
        }

        $data = $response->json('data') ?? [];

        // Kora's own id is what its authorise call wants back; ours stays the
        // reference everything else is verified by.
        $attempt->metadata = array_merge($attempt->metadata ?? [], [
            'korapay' => [
                'transaction_reference' => $data['transaction_reference'] ?? null,
                'auth_model' => $data['auth_model'] ?? null,
                'number' => $number,
            ],
        ]);
        $attempt->save();

        return $this->mobileMoneyStep($data, $waiting);
    }

    /**
     * The one-time code the customer typed, sent to Kora. A valid code is
     * followed by the prompt on the phone; an invalid one is asked for again.
     *
     * @return array<string, mixed>
     */
    public function authorizeMobileMoney(PaymentAttempt $attempt, string $otp): array
    {
        $korapay = (array) data_get($attempt->metadata, 'korapay', []);
        $currency = strtoupper((string) $attempt->currency);
        $number = (string) ($korapay['number'] ?? '');
        $country = MobileMoneyRail::countryFor($currency, $number) ?? MobileMoneyRail::homeCountry($currency);
        $chargeLabel = number_format($this->chargeAmountFor($attempt), MobileMoneyRail::precision($currency)).' '.$currency;
        $waiting = fn (?string $message = null): array => $this->mobileMoneyWaiting($number, $chargeLabel, null, $country, $message);

        if ($this->mock) {
            return $waiting();
        }

        if (empty($korapay['transaction_reference'])) {
            return ['status' => 'failed', 'message' => 'This payment has no code to confirm. Please start again.', 'failure' => GatewayFailure::internal('korapay_otp_without_charge', 'authorize called with no transaction_reference on the attempt')];
        }

        try {
            $response = Http::withToken($this->secretKey)
                ->timeout(40)
                ->post("{$this->baseUrl}/charges/mobile-money/authorize", [
                    'reference' => $korapay['transaction_reference'],
                    'token' => trim($otp),
                ]);
        } catch (\Exception $e) {
            return ['status' => 'failed', 'message' => 'The code could not be checked: '.$e->getMessage(), 'failure' => GatewayFailure::fromException($e)];
        }

        if ($response->failed() || ($response->json('status') ?? false) !== true) {
            $message = (string) ($response->json('message') ?? '');

            // A wrong code is the customer's to retype, not the end of the payment.
            if (stripos($message, 'otp') !== false || stripos($message, 'token') !== false || stripos($message, 'invalid') !== false) {
                return [
                    'status' => 'awaiting_customer_action',
                    'action' => 'otp',
                    'message' => 'That code was not accepted. Check it and try again.',
                ];
            }

            return [
                'status' => 'failed',
                'message' => 'The code could not be confirmed: '.($message !== '' ? $message : 'Unknown error'),
                'failure' => GatewayFailure::fromKorapay($response->json(), $response->status()),
            ];
        }

        return $this->mobileMoneyStep($response->json('data') ?? [], $waiting);
    }

    /**
     * What a Kora mobile money answer asks of the customer next.
     *
     * @param  array<string, mixed>  $data
     * @param  callable(?string): array<string, mixed>  $waiting
     * @return array<string, mixed>
     */
    private function mobileMoneyStep(array $data, callable $waiting): array
    {
        if (($data['status'] ?? null) === 'success') {
            return ['status' => 'confirmed', 'transaction_id' => $data['transaction_reference'] ?? null];
        }

        return match ($data['auth_model'] ?? null) {
            'OTP' => [
                'status' => 'awaiting_customer_action',
                'action' => 'otp',
                'message' => $data['message'] ?? 'Enter the code sent to your phone to continue.',
            ],
            'REDIRECT' => isset($data['authorization']['redirect_url'])
                ? ['status' => 'awaiting_redirect', 'redirect_url' => $data['authorization']['redirect_url'], 'method' => 'mobile_money']
                : $waiting($data['message'] ?? null),
            default => $waiting($data['message'] ?? null),
        };
    }

    /**
     * The waiting state checkout shows while the prompt is on the phone, the
     * same shape Flutterwave's rail produces.
     *
     * @return array<string, mixed>
     */
    private function mobileMoneyWaiting(string $phoneNumber, string $chargeLabel, ?string $networkLabel, ?string $country, ?string $message = null): array
    {
        return [
            'status' => 'awaiting_confirmation',
            'message' => $message ?: __('checkout.momo_prompt_sent', ['amount' => $chargeLabel, 'phone' => $phoneNumber]),
            'mobile_money' => [
                'network' => $networkLabel,
                'country' => $country,
                'phone_number' => $phoneNumber,
                'charge_label' => $chargeLabel,
            ],
            'approval' => MobileMoneyApproval::for($country, $networkLabel),
        ];
    }

    /**
     * Kora's in-page checkout (an iframe opened by its script on our page),
     * restricted to the one method the customer picked. Cards never touch
     * our server, there is no redirect, and the payment is verified by our
     * reference when the modal reports success.
     *
     * @return array<string, mixed>
     */
    public function inlineConfig(PaymentAttempt $attempt, string $methodKey): array
    {
        $channel = match ($methodKey) {
            'card' => 'card',
            'bank_transfer' => 'bank_transfer',
            'pay_with_bank' => 'pay_with_bank',
            'mobile_money' => 'mobile_money',
            default => $methodKey,
        };

        return [
            'provider' => 'korapay',
            'key' => (string) config('services.korapay.public_key'),
            'reference' => $attempt->idempotency_key,
            'amount' => $this->chargeAmountFor($attempt),
            'currency' => strtoupper((string) $attempt->currency),
            'customer' => [
                'name' => $attempt->user->name,
                'email' => $attempt->user->email,
            ],
            'channels' => [$channel],
            'default_channel' => $channel,
            'notification_url' => $this->webhookUrl(),
            'narration' => $attempt->order
                ? "Order #{$attempt->order->order_number}"
                : "Payment Ref #{$attempt->idempotency_key}",
        ];
    }

    private function webhookUrl(): string
    {
        return (string) (config('services.korapay.webhook_url') ?: url('/api/webhooks/korapay'));
    }

    public function verifyPayment(PaymentAttempt $attempt): bool
    {
        if ($this->mock) {
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
            // reference must be OURS before any status is believed. Kora puts
            // its own KPY- id in `reference` on some responses and our
            // reference in `payment_reference` (its docs show both shapes), so
            // either field may carry ours; neither may carry someone else's.
            if (! $this->carriesOurReference($data, (string) $attempt->idempotency_key)) {
                Log::warning('Korapay verification rejected: reference mismatch', [
                    'attempt_id' => $attempt->id,
                    'expected' => $attempt->idempotency_key,
                    'reported' => $data['reference'] ?? null,
                    'reported_payment_reference' => $data['payment_reference'] ?? null,
                ]);

                return false;
            }

            // MAJOR units, arriving as strings ("2000.00"). Kora's docs say to
            // give value from `amount_accepted` (what it processed, after its
            // over/underpayment rules); `amount_paid` is 0 on a successful
            // mobile money charge in Kora's own example, so it only counts
            // when it is a real figure, and `amount` stands when neither is.
            $paid = $this->acceptedAmount($data);

            if (($data['status'] ?? null) === 'success'
                && $paid >= $this->chargeAmountFor($attempt)
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

    public function refundPayment(PaymentAttempt $attempt, float $amount, ?string $reference = null): bool
    {
        if ($this->mock) {
            $attempt->payment_status = PaymentStatus::Refunded;
            $attempt->save();

            return true;
        }

        // Kora requires OUR unique reference per refund. Derived from the
        // caller's reference (or the attempt) rather than minted afresh, so a
        // retry after a timeout asks for the same refund instead of a second
        // one. Kora's limit is 50 characters.
        $refundReference = Str::limit('RF-'.$attempt->id.'-'.($reference ?? 'refund'), 50, '');

        try {
            $response = Http::withToken($this->secretKey)
                ->post("{$this->baseUrl}/refunds/initiate", [
                    'payment_reference' => $attempt->idempotency_key,
                    'reference' => $refundReference,
                    'amount' => round($amount, MobileMoneyRail::precision((string) $attempt->currency)),
                    'reason' => 'RshopRefill refund',
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

    /**
     * The figure Kora is asked for: the attempt's amount, rounded up to the
     * currency's precision (whole francs for XAF/XOF), recorded on the attempt
     * the first time it differs so every later read agrees with the charge.
     */
    private function chargeAmountFor(PaymentAttempt $attempt): float
    {
        $recorded = $attempt->pay_amount;

        if ($recorded !== null && strtoupper((string) $attempt->pay_currency) === strtoupper((string) $attempt->currency)) {
            return (float) $recorded;
        }

        $precision = MobileMoneyRail::precision((string) $attempt->currency);
        $factor = 10 ** $precision;
        $amount = ceil(((float) $attempt->amount) * $factor - 1e-9) / $factor;

        if ($amount !== (float) $attempt->amount) {
            $attempt->pay_currency = strtoupper((string) $attempt->currency);
            $attempt->pay_amount = $amount;
            $attempt->save();
        }

        return $amount;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function carriesOurReference(array $data, string $ours): bool
    {
        foreach (['payment_reference', 'reference'] as $field) {
            $reported = (string) ($data[$field] ?? '');

            if ($reported !== '' && hash_equals($ours, $reported)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function acceptedAmount(array $data): float
    {
        if (isset($data['amount_accepted']) && (float) $data['amount_accepted'] > 0) {
            return (float) $data['amount_accepted'];
        }

        if (isset($data['amount_paid']) && (float) $data['amount_paid'] > 0) {
            return (float) $data['amount_paid'];
        }

        return (float) ($data['amount'] ?? 0);
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
