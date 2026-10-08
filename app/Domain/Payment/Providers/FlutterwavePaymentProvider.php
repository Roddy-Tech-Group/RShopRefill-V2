<?php

namespace App\Domain\Payment\Providers;

use App\Domain\Payment\Enums\PaymentStatus;
use App\Domain\Payment\Interfaces\PaymentProviderInterface;
use App\Domain\Payment\Support\FlutterwaveHttp;
use App\Domain\Payment\Support\GatewayFailure;
use App\Domain\Payment\Support\MobileMoneyApproval;
use App\Domain\Payment\Support\MobileMoneyRail;
use App\Domain\Payment\Support\MockMode;
use App\Domain\PaymentInsights\Support\DeclineVerdict;
use App\Domain\Wallet\Services\CurrencyRateService;
use App\Models\CurrencyRate;
use App\Models\PaymentAttempt;
use App\Models\WalletFunding;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;

class FlutterwavePaymentProvider implements PaymentProviderInterface
{
    /**
     * Pages of the charge listing read per reference. Each page is one gateway
     * round trip made while a customer request or the sweeper waits, so the
     * cap bounds that wait; three pages already covers far more refused cards
     * than one popup session produces.
     */
    private const MAX_CHARGE_LISTING_PAGES = 3;

    private string $secretKey;

    /** Decided by environment policy (MockMode), never by the key alone. */
    private bool $mock;

    private string $baseUrl;

    public function __construct()
    {
        $secretKey = config('services.flutterwave.secret_key');

        // Fail closed: outside local/testing (and without PAYMENT_MOCK=true) a
        // missing secret must hard-fail rather than silently process payments
        // against the mock gateway.
        if (empty($secretKey) && ! MockMode::allowed()) {
            throw new \RuntimeException('Flutterwave secret key (FLW_SECRET_KEY) is not configured. Refusing to fall back to mock credentials outside local/testing.');
        }

        $this->secretKey = $secretKey ?: 'FLW_SECRET_KEY_MOCK';
        $this->mock = MockMode::forKey($secretKey);
        $this->baseUrl = 'https://api.flutterwave.com/v3';
    }

    /** A single attempt with timeouts: charges, validations, refunds. See FlutterwaveHttp. */
    private function http(): PendingRequest
    {
        return FlutterwaveHttp::client($this->secretKey);
    }

    /** A read, retried with back-off on 429, 5xx and connection trouble. */
    private function httpRead(): PendingRequest
    {
        return FlutterwaveHttp::readClient($this->secretKey);
    }

    public function initializePayment(PaymentAttempt $attempt): array
    {
        $txRef = $attempt->idempotency_key;
        $payable = $attempt->payable;

        $title = 'RshopRefill';
        $description = $attempt->order ? "Order #{$attempt->order->order_number}" : "Payment Ref #{$txRef}";

        if ($payable instanceof WalletFunding) {
            $description = "Wallet Funding Ref #{$payable->reference}";
        }

        $publicKey = config('services.flutterwave.public_key');
        if (empty($publicKey) && ! MockMode::allowed()) {
            throw new \RuntimeException('Flutterwave public key (FLW_PUBLIC_KEY) is not configured. Refusing to fall back to mock credentials outside local/testing.');
        }
        $publicKey = $publicKey ?: 'FLW_PUB_KEY_MOCK';

        // Set attempt's gateway reference to tx_ref for tracking before webhook or capture
        $attempt->gateway_reference = $txRef;
        $attempt->payment_url = null;
        $attempt->save();

        return [
            'provider' => 'flutterwave',
            'mode' => 'inline',
            'tx_ref' => $txRef,
            'public_key' => $publicKey,
            'amount' => (float) $attempt->amount,
            'currency' => strtoupper($attempt->currency),
            'customer' => [
                'email' => $attempt->user->email,
                'name' => $attempt->user->name,
            ],
            'customizations' => [
                'title' => $title,
                'description' => $description,
                'logo' => asset('assets/logo.webp').'?v=4',
            ],
            'gateway_reference' => $txRef,
        ];
    }

    public function getEncryptionKey(): string
    {
        $key = config('services.flutterwave.encryption_key');
        if ($key) {
            return $key;
        }
        $secret = $this->secretKey;
        $secMD5 = md5($secret);

        // Strip the standard Flutterwave prefixes before extracting the first 12 chars
        $secretAdjusted = str_replace(['FLWSECK-', 'FLWSECK_TEST-'], '', $secret);

        return substr($secretAdjusted, 0, 12).substr($secMD5, -12);
    }

    public function encryptPayload(array $payload): string
    {
        $key = $this->getEncryptionKey();
        $data = json_encode($payload);
        $encrypted = openssl_encrypt($data, 'DES-EDE3', $key, OPENSSL_RAW_DATA);

        return base64_encode($encrypted);
    }

    public function chargeCard(PaymentAttempt $attempt, array $cardDetails, ?array $auth = null): array
    {
        if ($this->mock) {
            return $this->simulateMockCardCharge($attempt, $cardDetails, $auth);
        }

        $payload = [
            'card_number' => str_replace(' ', '', $cardDetails['card_number']),
            'cvv' => $cardDetails['cvv'],
            'expiry_month' => $cardDetails['expiry_month'],
            'expiry_year' => $cardDetails['expiry_year'],
            'currency' => strtoupper($attempt->currency),
            'amount' => (float) $attempt->amount,
            'email' => $attempt->user->email,
            'tx_ref' => $attempt->idempotency_key,
            'fullname' => $cardDetails['card_holder'] ?? $attempt->user->name,
        ];

        if ($auth && isset($auth['pin'])) {
            $payload['authorization'] = [
                'mode' => 'pin',
                'pin' => $auth['pin'],
            ];
        }

        $encrypted = $this->encryptPayload($payload);

        try {
            $response = $this->http()
                ->post("{$this->baseUrl}/charges?type=card", [
                    'client' => $encrypted,
                ]);

            FlutterwaveHttp::noteIfRateLimited($response);

            // The gateway gave up on its own request (it does so at 28 seconds
            // and answers 503). The charge may still have been taken, and its
            // guidance is to look the reference up before anything else.
            if ($response->serverError()) {
                return $this->afterChargeInterrupted($attempt, null, $response, fn (array $known): array => $this->cardPendingAfterInterruption());
            }

            if ($response->failed()) {
                // Log the full HTTP failure so the actual Flutterwave message is
                // visible in storage/logs/laravel.log instead of being swallowed
                // into the generic wizard "Transaction could not be completed."
                Log::error('Flutterwave card charge HTTP failed', [
                    'status' => $response->status(),
                    'body' => $response->json(),
                    'tx_ref' => $attempt->idempotency_key,
                ]);

                return [
                    'status' => 'failed',
                    // Customer-facing — strip the gateway brand (per [[feedback-no-provider-names]]).
                    'message' => 'Card charge request failed: '.($response->json('message') ?? 'Unknown error'),
                    'failure' => GatewayFailure::fromFlutterwave($response->json(), $response->status()),
                ];
            }

            $body = $response->json();
            $result = $this->handleCardChargeResponse($attempt, $body, $auth);

            // Flutterwave often returns HTTP 200 with `status: error` for things
            // like wrong currency / bad encryption key / invalid card. Surface
            // those to the log too — without this, charge-rejected failures are
            // silent (see prior bug: only NowPayments errors landed in the log).
            if (($result['status'] ?? '') === 'failed') {
                Log::error('Flutterwave card charge returned non-success', [
                    'message' => $result['message'] ?? null,
                    'body' => $body,
                    'tx_ref' => $attempt->idempotency_key,
                ]);
            }

            return $result;
        } catch (ConnectionException $e) {
            Log::warning('Flutterwave card charge did not answer in time', [
                'message' => $e->getMessage(),
                'tx_ref' => $attempt->idempotency_key,
            ]);

            return $this->afterChargeInterrupted($attempt, $e, null, fn (array $known): array => $this->cardPendingAfterInterruption());
        } catch (\Exception $e) {
            Log::error('Flutterwave card charge exception', [
                'message' => $e->getMessage(),
                'tx_ref' => $attempt->idempotency_key,
            ]);

            return [
                'status' => 'failed',
                'message' => 'Error processing card charge: '.$e->getMessage(),
                'failure' => GatewayFailure::fromException($e),
            ];
        }
    }

    private function handleCardChargeResponse(PaymentAttempt $attempt, array $data, ?array $auth): array
    {
        $status = $data['status'] ?? 'error';
        $message = $data['message'] ?? '';
        $innerData = $data['data'] ?? [];

        // Flutterwave returns auth metadata at different nesting depths depending
        // on the response stage — TOP-LEVEL `meta` for the initial auth challenge
        // (`Charge authorization data required` responses), nested `data.meta` for
        // the transaction-level snapshot. Check both so the PIN/OTP/3DS branches
        // actually fire instead of falling through to "failed".
        $authMeta = $innerData['meta']['authorization']
            ?? $data['meta']['authorization']
            ?? null;
        $authMode = $authMeta['mode'] ?? null;
        $suggestedAuth = $innerData['suggested_auth'] ?? $data['suggested_auth'] ?? null;
        $flwRef = $innerData['flw_ref'] ?? $data['flw_ref'] ?? null;

        if ($suggestedAuth === 'PIN') {
            return [
                'status' => 'awaiting_customer_action',
                'action' => 'pin',
                'message' => 'PIN is required',
            ];
        }

        if ($authMode === 'pin') {
            return [
                'status' => 'awaiting_customer_action',
                'action' => 'pin',
                'message' => 'PIN is required',
            ];
        }

        if ($authMode === 'otp') {
            return [
                'status' => 'awaiting_customer_action',
                'action' => 'otp',
                'flw_ref' => $flwRef,
                'message' => $authMeta['fields'][0] ?? 'OTP sent to your phone/email',
            ];
        }

        if ($authMode === 'redirect') {
            return [
                'status' => 'awaiting_customer_action',
                'action' => 'redirect',
                'redirect_url' => $authMeta['redirect'] ?? null,
                'message' => '3D Secure authentication required',
            ];
        }

        if ($status === 'success' && isset($innerData['status']) && $innerData['status'] === 'successful') {
            $attempt->gateway_reference = (string) $innerData['id'];
            $attempt->payment_status = PaymentStatus::Paid;
            $attempt->confirmed_at = now();
            $attempt->verification_payload = $data;
            $attempt->save();

            return [
                'status' => 'confirmed',
                'transaction_id' => (string) $innerData['id'],
                'message' => 'Payment successful',
            ];
        }

        if (isset($innerData['status']) && $innerData['status'] === 'send_otp') {
            return [
                'status' => 'awaiting_customer_action',
                'action' => 'otp',
                'flw_ref' => $flwRef,
                'message' => 'OTP sent to your phone/email',
            ];
        }

        // This is the path real card declines take: HTTP 200, but
        // data.processor_response carries the issuer's verdict.
        return [
            'status' => 'failed',
            'message' => $message ?: 'Card charge could not be processed',
            'failure' => GatewayFailure::fromFlutterwave($data),
        ];
    }

    public function validateOTP(PaymentAttempt $attempt, string $otp, string $flwRef): array
    {
        if ($this->mock) {
            if ($otp === '123456' || $otp === '1234') {
                $attempt->payment_status = PaymentStatus::Paid;
                $attempt->confirmed_at = now();
                $attempt->gateway_reference = 'FLW-MOCK-VAL-'.uniqid();
                $attempt->save();

                return [
                    'status' => 'confirmed',
                    'transaction_id' => $attempt->gateway_reference,
                    'message' => 'Payment validated successfully',
                ];
            }

            return [
                'status' => 'failed',
                'message' => 'Invalid OTP',
                'failure' => GatewayFailure::internal('invalid_otp', 'Mock OTP rejected'),
            ];
        }

        try {
            $response = $this->http()
                ->post("{$this->baseUrl}/validate-charge", [
                    'otp' => $otp,
                    'flw_ref' => $flwRef,
                    'type' => 'card',
                ]);

            if ($response->failed()) {
                return [
                    'status' => 'failed',
                    'message' => 'OTP validation failed: '.($response->json('message') ?? 'Unknown error'),
                    'failure' => GatewayFailure::fromFlutterwave($response->json(), $response->status()),
                ];
            }

            $data = $response->json();
            $innerData = $data['data'] ?? [];
            if ($data['status'] === 'success' && isset($innerData['status']) && $innerData['status'] === 'successful') {
                $attempt->gateway_reference = (string) $innerData['id'];
                $attempt->payment_status = PaymentStatus::Paid;
                $attempt->confirmed_at = now();
                $attempt->verification_payload = $data;
                $attempt->save();

                return [
                    'status' => 'confirmed',
                    'transaction_id' => (string) $innerData['id'],
                    'message' => 'Payment successful',
                ];
            }

            return [
                'status' => 'failed',
                'message' => $data['message'] ?? 'OTP validation failed',
                'failure' => GatewayFailure::fromFlutterwave($data),
            ];
        } catch (\Exception $e) {
            return [
                'status' => 'failed',
                'message' => 'OTP verification failed: '.$e->getMessage(),
                'failure' => GatewayFailure::fromException($e),
            ];
        }
    }

    public function chargeBankTransfer(PaymentAttempt $attempt): array
    {
        if ($this->mock) {
            $bankDetails = [
                'bank_name' => 'Wema Bank (RshopRefill Mock)',
                'account_number' => '9982'.rand(100000, 999999),
                'account_name' => 'RshopRefill-Deposit-'.$attempt->user->id,
                'amount' => (float) $attempt->amount,
                'expires_at' => now()->addHour()->toIso8601String(),
            ];

            return [
                'status' => 'awaiting_transfer',
                'bank_details' => $bankDetails,
            ];
        }

        try {
            $response = $this->http()
                ->post("{$this->baseUrl}/charges?type=bank_transfer", [
                    'tx_ref' => $attempt->idempotency_key,
                    'amount' => (float) $attempt->amount,
                    'currency' => 'NGN',
                    'email' => $attempt->user->email,
                    'fullname' => $attempt->user->name,
                ]);

            if ($response->failed()) {
                return [
                    'status' => 'failed',
                    'message' => 'Failed to initialize bank transfer: '.($response->json('message') ?? 'Unknown error'),
                    'failure' => GatewayFailure::fromFlutterwave($response->json(), $response->status()),
                ];
            }

            $data = $response->json();

            if (($data['status'] ?? '') !== 'success') {
                return [
                    'status' => 'failed',
                    'message' => 'Failed to initialize bank transfer: '.($data['message'] ?? 'Unknown error'),
                    'failure' => GatewayFailure::fromFlutterwave($data),
                ];
            }

            $innerData = $data['meta']['authorization'] ?? $data['data']['meta']['authorization'] ?? [];

            if (empty($innerData) || empty($innerData['transfer_account'])) {
                return [
                    'status' => 'failed',
                    'message' => 'Failed to generate bank transfer virtual account. Please try again or contact support.',
                    'failure' => GatewayFailure::internal('missing_virtual_account', 'Gateway returned success without a transfer account'),
                ];
            }

            $bankDetails = [
                'bank_name' => $innerData['transfer_bank'] ?? 'Unknown Bank',
                'account_number' => $innerData['transfer_account'],
                'account_name' => 'Roddy Technologies LTD',
                'amount' => $innerData['transfer_amount'] ?? $attempt->amount,
                'expires_at' => now()->addMinutes(30)->toIso8601String(),
            ];

            return [
                'status' => 'awaiting_transfer',
                'bank_details' => $bankDetails,
            ];
        } catch (\Exception $e) {
            return [
                'status' => 'failed',
                'message' => 'Failed to initialize bank transfer: '.$e->getMessage(),
                'failure' => GatewayFailure::fromException($e),
            ];
        }
    }

    /**
     * Charge a mobile-money wallet on the rail that serves the attempt's
     * currency (see MobileMoneyRail). Two outcomes short of failure:
     *
     *  - awaiting_confirmation: the rail pushed a prompt to the handset (the
     *    Francophone operators, M-Pesa) and the customer approves it there;
     *  - awaiting_redirect: the rail answered with an authorization page of
     *    its own (Ghana, Uganda, Rwanda), so the customer is sent there and
     *    comes back through the hosted return leg, which verifies by tx_ref.
     *
     * The network and the phone are checked against the rail BEFORE anything
     * leaves the building: a label the rail does not know is a client bug and
     * must not become a failed order.
     *
     * A currency with no rail of its own (USD) is charged in the rail's
     * currency: the attempt's total is converted into CFA francs at the live
     * rate and the franc figure is written to pay_currency / pay_amount before
     * the gateway is called, so verification, a webhook that lands ahead of
     * our own read, and any later refund all work from what the rail was
     * actually asked for. The attempt itself stays in its own currency.
     */
    public function chargeMobileMoney(PaymentAttempt $attempt, string $phoneNumber, ?string $network = null, ?string $returnUrl = null): array
    {
        $currency = strtoupper($attempt->currency);
        $phone = MobileMoneyRail::normalisePhone($phoneNumber);

        if (! MobileMoneyRail::supports($currency)) {
            return [
                'status' => 'failed',
                'message' => "Mobile money is not available in {$currency}.",
                'failure' => GatewayFailure::internal('mobile_money_unsupported_currency', "No mobile money rail serves {$currency}"),
            ];
        }

        $gatewayNetwork = null;

        // Rails that take a network code must be given one it knows; the
        // M-Pesa and Rwanda rails take the number alone whatever was picked.
        if (MobileMoneyRail::codeRequired($currency)) {
            $gatewayNetwork = MobileMoneyRail::gatewayNetwork($currency, $network);

            if ($gatewayNetwork === null) {
                return [
                    'status' => 'failed',
                    'message' => "Choose a mobile money network available in {$currency}.",
                    'failure' => GatewayFailure::internal('mobile_money_unknown_network', "Network '{$network}' is not offered on the {$currency} rail"),
                ];
            }
        }

        $chargeCurrency = MobileMoneyRail::chargeCurrency($currency, $phone);
        $chargeAmount = (float) $attempt->amount;

        // A charge already sent under this reference fixed the franc figure.
        // A retry (the customer taps Pay again while the first prompt is
        // live) must ask for the same francs: re-pricing at today's rate left
        // the approved prompt paying a figure our verification no longer
        // matched, so the money was taken and never credited.
        $alreadyCharged = $attempt->pay_currency !== null
            && strtoupper((string) $attempt->pay_currency) === $chargeCurrency
            && $attempt->pay_amount !== null;

        if ($chargeCurrency !== $currency && $alreadyCharged) {
            $chargeAmount = (float) $attempt->pay_amount;
        } elseif ($chargeCurrency !== $currency) {
            $converted = $this->convertForRail($attempt, $chargeCurrency);

            if ($converted === null) {
                return [
                    'status' => 'failed',
                    'message' => 'Mobile money is temporarily unavailable for this order. Please try another payment method.',
                    'failure' => GatewayFailure::internal('mobile_money_rate_unavailable', "No usable {$currency} to {$chargeCurrency} rate to convert the charge"),
                ];
            }

            $chargeAmount = $converted['amount'];
            $attempt->pay_currency = $chargeCurrency;
            $attempt->pay_amount = $chargeAmount;
            $attempt->metadata = array_merge($attempt->metadata ?? [], [
                'mobile_money' => [
                    'converted_from' => $currency,
                    'charge_currency' => $chargeCurrency,
                    'charge_amount' => $chargeAmount,
                    'rate' => $converted['rate'],
                    'converted_at' => now()->toIso8601String(),
                ],
            ]);
            $attempt->save();
        }

        $chargeLabel = number_format($chargeAmount, MobileMoneyRail::precision($chargeCurrency)).' '.$chargeCurrency;
        $networkLabel = MobileMoneyRail::networkLabel($currency, $network);
        $country = MobileMoneyRail::countryFor($currency, $phone);

        // The waiting state for a rail that prompts the handset, built from
        // whatever the gateway answered (nothing, for a charge it never
        // acknowledged).
        $waiting = fn (array $data = []): array => $this->mobileMoneyWaiting($data, $phoneNumber, $chargeLabel, $networkLabel, $country);

        if ($this->mock) {
            return $waiting();
        }

        $payload = [
            'amount' => $chargeAmount,
            'currency' => $chargeCurrency,
            'email' => $attempt->user->email,
            'phone_number' => $phone,
            'tx_ref' => $attempt->idempotency_key,
        ];

        if ($gatewayNetwork !== null) {
            $payload['network'] = $gatewayNetwork;
        }

        if ($country !== null) {
            $payload['country'] = $country;
        }

        if ($returnUrl !== null) {
            $payload['redirect_url'] = $returnUrl;
        }

        $chargeType = MobileMoneyRail::chargeType($currency);

        try {
            $response = $this->http()
                ->post("{$this->baseUrl}/charges?type={$chargeType}", $payload);
        } catch (ConnectionException $e) {
            // The prompt may already be on the customer's phone: the gateway
            // initiates the charge long before it gives up on the request.
            // Its guidance is to look the reference up before anything else.
            Log::warning('Flutterwave mobile money charge did not answer in time', [
                'tx_ref' => $attempt->idempotency_key,
                'rail' => $chargeType,
                'message' => $e->getMessage(),
            ]);

            return $this->afterChargeInterrupted($attempt, $e, null, $waiting);
        } catch (\Exception $e) {
            return [
                'status' => 'failed',
                'message' => 'Failed to initialize mobile money: '.$e->getMessage(),
                'failure' => GatewayFailure::fromException($e),
            ];
        }

        FlutterwaveHttp::noteIfRateLimited($response);

        if ($response->serverError()) {
            return $this->afterChargeInterrupted($attempt, null, $response, $waiting);
        }

        if ($response->failed()) {
            Log::warning('Flutterwave mobile money charge refused', [
                'tx_ref' => $attempt->idempotency_key,
                'rail' => $chargeType,
                'currency' => $currency,
                'charged' => $chargeLabel,
                'http_status' => $response->status(),
                'message' => $response->json('message'),
            ]);

            return [
                'status' => 'failed',
                'message' => 'Failed to initialize mobile money: '.($response->json('message') ?? 'Unknown error'),
                'failure' => GatewayFailure::fromFlutterwave($response->json(), $response->status()),
            ];
        }

        $data = $response->json();
        $status = $data['status'] ?? 'error';

        if ($status === 'success') {
            // Rails that answer with an authorization page of their own send
            // the customer there. The handset rails stay here: their prompt
            // is already on the phone, and the URL they echo back under the
            // redirect key is our own return page, not a step to take.
            if (! MobileMoneyRail::promptsOnHandset($currency)
                && ($redirect = $this->mobileMoneyRedirect($data)) !== null) {
                return [
                    'status' => 'awaiting_redirect',
                    'redirect_url' => $redirect,
                    'method' => 'mobile_money',
                ];
            }

            return $waiting($data);
        }

        return [
            'status' => 'failed',
            'message' => $data['message'] ?? 'Mobile money charge could not be initiated.',
            'failure' => GatewayFailure::fromFlutterwave($data),
        ];
    }

    /**
     * The rail's own authorization page, when it answers with one, under
     * either spelling the gateway uses: `redirect` with mode `redirect` on
     * the Ghana rail, `redirect_url` with mode `redirecturl` in the
     * Francophone reference. The old check knew one spelling only.
     *
     * @param  array<string, mixed>  $data  Decoded charge response.
     */
    private function mobileMoneyRedirect(array $data): ?string
    {
        $auth = $data['meta']['authorization']
            ?? $data['data']['meta']['authorization']
            ?? [];

        if (! in_array($auth['mode'] ?? null, ['redirect', 'redirecturl'], true)) {
            return null;
        }

        $redirect = $auth['redirect'] ?? $auth['redirect_url'] ?? null;

        return is_string($redirect) && str_starts_with($redirect, 'https://') ? $redirect : null;
    }

    /**
     * The waiting state for a rail that prompts the handset: what the
     * customer is told, the facts the screens and the admin page show, and
     * the operator's own way to approve when no prompt arrives.
     *
     * The gateway's answer is used only when it is an instruction. The
     * Francophone rail answers "Transaction in progress" in
     * processor_response, and that used to be shown as the whole instruction
     * (it passed a length check), so a customer waiting for an MTN prompt saw
     * a status phrase and no code to dial.
     *
     * @param  array<string, mixed>  $data  Decoded charge response, or empty.
     * @return array<string, mixed>
     */
    private function mobileMoneyWaiting(array $data, string $phoneNumber, string $chargeLabel, ?string $networkLabel, ?string $country): array
    {
        return [
            'status' => 'awaiting_confirmation',
            'message' => $this->gatewayInstruction($data)
                ?? __('checkout.momo_prompt_sent', ['amount' => $chargeLabel, 'phone' => $phoneNumber]),
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
     * The gateway's wording, when it tells the customer what to do: a dial
     * code, or a verb that asks for an action. Status phrases ("Transaction
     * in progress", "pending") are not instructions and are dropped.
     *
     * @param  array<string, mixed>  $data
     */
    private function gatewayInstruction(array $data): ?string
    {
        $auth = $data['meta']['authorization']
            ?? $data['data']['meta']['authorization']
            ?? [];

        $candidates = [
            $auth['note'] ?? null,
            $auth['instruction'] ?? null,
            $data['data']['processor_response'] ?? null,
            $data['processor_response'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            $candidate = is_string($candidate) ? trim($candidate) : '';

            if ($candidate === '') {
                continue;
            }

            if (preg_match('/[*#]\d[\d*#]*#/', $candidate) === 1
                || preg_match('/\b(dial|approve|authori[sz]e|confirm|enter|prompt|composez|approuvez|validez|confirmez)\b/i', $candidate) === 1) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * The rail's figure for an attempt priced in another currency, rounded UP
     * to the rail currency's precision (whole francs) so a rounding step can
     * never under-collect the order. Null when no usable rate exists.
     *
     * The rail currency's own active row is required before any rate is read:
     * CurrencyRate::resolve() answers a missing code with the USD row, which
     * would quietly charge 11 francs for an 11-dollar order.
     *
     * @return array{amount: float, rate: float}|null
     */
    private function convertForRail(PaymentAttempt $attempt, string $chargeCurrency): ?array
    {
        $currency = strtoupper($attempt->currency);

        if (! CurrencyRate::query()->active()->where('code', $chargeCurrency)->exists()) {
            Log::warning('Mobile money conversion refused: the rail currency has no active rate', [
                'attempt_id' => $attempt->id,
                'from' => $currency,
                'to' => $chargeCurrency,
            ]);

            return null;
        }

        try {
            $rate = app(CurrencyRateService::class)->resolveRate($currency, $chargeCurrency);
        } catch (\Throwable $e) {
            Log::warning('Mobile money conversion refused: rate lookup failed', [
                'attempt_id' => $attempt->id,
                'from' => $currency,
                'to' => $chargeCurrency,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        if ($rate <= 0) {
            return null;
        }

        $factor = 10 ** MobileMoneyRail::precision($chargeCurrency);
        $amount = ceil(round((float) $attempt->amount * $rate, 6) * $factor) / $factor;

        return ['amount' => $amount, 'rate' => $rate];
    }

    /**
     * What the gateway was asked to charge: the attempt's own figure, or the
     * one a converted mobile-money charge recorded on pay_currency /
     * pay_amount. Crypto never verifies or refunds through this provider, so
     * those two columns can only carry that conversion here.
     *
     * @return array{0: float, 1: string}
     */
    private function chargedFigures(PaymentAttempt $attempt): array
    {
        if ($attempt->pay_currency !== null && $attempt->pay_amount !== null) {
            return [(float) $attempt->pay_amount, strtoupper((string) $attempt->pay_currency)];
        }

        return [(float) $attempt->amount, strtoupper((string) $attempt->currency)];
    }

    /**
     * An attempt-currency amount (the whole attempt for a duplicate payment,
     * an item's share for a failed delivery) expressed in the currency the
     * gateway holds the charge in. Identity unless the attempt was charged
     * on a converted rail, where it is the same share of the charged figure.
     */
    private function onChargedScale(PaymentAttempt $attempt, float $amount): float
    {
        [$chargedAmount, $chargedCurrency] = $this->chargedFigures($attempt);

        if ($chargedCurrency === strtoupper((string) $attempt->currency) || (float) $attempt->amount <= 0) {
            return $amount;
        }

        return round($amount * $chargedAmount / (float) $attempt->amount, MobileMoneyRail::precision($chargedCurrency));
    }

    /**
     * Apple Pay through Flutterwave's hosted sheet. The charge carries the
     * customer's full name, phone and country alongside the amount: the
     * gateway's compliance step wants to know who is paying, and a charge
     * that named nobody was refused with "Transaction failed compliance
     * checks" on every production attempt (twelve of twelve by 2026-09-10).
     */
    public function chargeApplePay(PaymentAttempt $attempt, array $details = [], ?string $returnUrl = null): array
    {
        if ($this->mock) {
            $attempt->gateway_reference = 'FLW-MOCK-APPLE-'.uniqid();
            $attempt->payment_status = PaymentStatus::Paid;
            $attempt->confirmed_at = now();
            $attempt->save();

            return [
                'status' => 'confirmed',
                'transaction_id' => $attempt->gateway_reference,
                'message' => 'Apple Pay payment successful',
            ];
        }

        $user = $attempt->user;

        $payload = [
            'amount' => (float) $attempt->amount,
            'currency' => strtoupper($attempt->currency),
            'email' => $user->email,
            'fullname' => trim((string) $user->name) ?: 'Customer',
            'tx_ref' => $attempt->idempotency_key,
        ];

        if ($returnUrl !== null) {
            $payload['redirect_url'] = $returnUrl;
        }

        $phone = preg_replace('/\D+/', '', (string) $user->phone);
        if ($phone !== '') {
            $payload['phone_number'] = $phone;
        }

        if (filled($user->country)) {
            $payload['billing_country'] = strtoupper((string) $user->country);
        }

        try {
            $response = $this->http()
                ->post("{$this->baseUrl}/charges?type=applepay", $payload);

            if ($response->failed()) {
                Log::warning('Flutterwave Apple Pay charge refused', [
                    'tx_ref' => $attempt->idempotency_key,
                    'currency' => $payload['currency'],
                    'http_status' => $response->status(),
                    'message' => $response->json('message'),
                ]);

                return [
                    'status' => 'failed',
                    'message' => 'Failed to initialize Apple Pay: '.($response->json('message') ?? 'Unknown error'),
                    'failure' => GatewayFailure::fromFlutterwave($response->json(), $response->status()),
                ];
            }

            $data = $response->json();
            $status = $data['status'] ?? 'error';
            $innerData = $data['data'] ?? [];

            $authMeta = $innerData['meta']['authorization'] ?? $data['meta']['authorization'] ?? null;
            $authMode = $authMeta['mode'] ?? null;

            if ($status === 'success' && $authMode === 'redirect') {
                return [
                    'status' => 'awaiting_redirect',
                    'redirect_url' => $authMeta['redirect'] ?? null,
                    'message' => $data['message'] ?? 'Please complete the Apple Pay payment on the next screen.',
                ];
            }

            if ($status === 'success' && isset($innerData['status']) && $innerData['status'] === 'successful') {
                $attempt->gateway_reference = (string) $innerData['id'];
                $attempt->payment_status = PaymentStatus::Paid;
                $attempt->confirmed_at = now();
                $attempt->verification_payload = $data;
                $attempt->save();

                return [
                    'status' => 'confirmed',
                    'transaction_id' => (string) $innerData['id'],
                    'message' => 'Apple Pay payment successful',
                ];
            }

            return [
                'status' => 'failed',
                'message' => $data['message'] ?? 'Apple Pay payment could not be completed.',
                'failure' => GatewayFailure::fromFlutterwave($data),
            ];
        } catch (\Exception $e) {
            return [
                'status' => 'failed',
                'message' => 'Failed to charge Apple Pay: '.$e->getMessage(),
                'failure' => GatewayFailure::fromException($e),
            ];
        }
    }

    private function simulateMockCardCharge(PaymentAttempt $attempt, array $cardDetails, ?array $auth): array
    {
        $cardNumber = str_replace(' ', '', $cardDetails['card_number']);

        // Magic declining card: exercises the full failure-diagnostics
        // pipeline (GatewayFailure block → outcome row → insights drill-down)
        // without a real gateway. Mirrors a genuine issuer decline shape.
        if ($cardNumber === '4444444444444444') {
            return [
                'status' => 'failed',
                'message' => 'Card charge could not be processed',
                'failure' => GatewayFailure::fromFlutterwave([
                    'status' => 'success',
                    'message' => 'Charge attempted',
                    'data' => ['status' => 'failed', 'processor_response' => 'Insufficient Funds'],
                ]),
            ];
        }

        if ($cardNumber === '5555555555555555' && (! $auth || ! isset($auth['pin']))) {
            return [
                'status' => 'awaiting_customer_action',
                'action' => 'pin',
                'message' => 'PIN is required',
            ];
        }

        if ($cardNumber === '7777777777777777' && (! $auth || ! isset($auth['otp']))) {
            return [
                'status' => 'awaiting_customer_action',
                'action' => 'otp',
                'flw_ref' => 'FLW-MOCK-REF-'.uniqid(),
                'message' => 'OTP sent to your phone/email',
            ];
        }

        if ($cardNumber === '9999999999999999' && (! $auth || ! isset($auth['redirect_completed']))) {
            return [
                'status' => 'awaiting_customer_action',
                'action' => 'redirect',
                'redirect_url' => route('shop.coming-soon').'?mock_3ds='.$attempt->paymentSession->id,
                'message' => '3D Secure authentication required',
            ];
        }

        $attempt->gateway_reference = 'FLW-MOCK-TX-'.uniqid();
        $attempt->payment_status = PaymentStatus::Paid;
        $attempt->confirmed_at = now();
        $attempt->save();

        return [
            'status' => 'confirmed',
            'transaction_id' => $attempt->gateway_reference,
            'message' => 'Payment successful',
        ];
    }

    /**
     * Initialise a Flutterwave HOSTED checkout for a method that we don't render
     * inline ourselves: USSD, Pay With Bank, Bank QR (NQR), Mobile Wallets.
     *
     * Flutterwave's hosted /payments endpoint returns a `payment_link` we
     * redirect the customer to. The hosted page is pre-filtered by the
     * `payment_options` field so the customer only sees their chosen method.
     *
     * @param  string  $methodKey  Our internal key from the checkout picker
     *                             (ussd / pay_with_bank / bank_qr / mobile_wallet).
     * @param  string  $returnUrl  Where Flutterwave should send the customer
     *                             after they complete (or cancel) the flow.
     */
    public function chargeHosted(PaymentAttempt $attempt, string $methodKey, string $returnUrl): array
    {
        // Map our internal method keys onto Flutterwave's payment_options
        // strings. Multi-value strings are comma-separated per FW spec.
        $paymentOptions = match ($methodKey) {
            'ussd' => 'ussd',
            'pay_with_bank' => 'account',
            'bank_qr' => 'qr',
            'mobile_wallet' => 'opay,enaira,mobilemoneynigeria',
            default => $methodKey,
        };

        if ($this->mock) {
            // Sandbox / dev path - go straight to a mock-success return so the
            // wizard can be tested without real FW credentials.
            $mockUrl = $returnUrl.(str_contains($returnUrl, '?') ? '&' : '?').'tx_ref='.$attempt->idempotency_key.'&status=successful&mock=1';

            return [
                'status' => 'awaiting_redirect',
                'redirect_url' => $mockUrl,
                'method' => $methodKey,
            ];
        }

        try {
            $response = $this->http()
                ->post("{$this->baseUrl}/payments", [
                    'tx_ref' => $attempt->idempotency_key,
                    'amount' => (float) $attempt->amount,
                    'currency' => strtoupper($attempt->currency),
                    'redirect_url' => $returnUrl,
                    'payment_options' => $paymentOptions,
                    'customer' => [
                        'email' => $attempt->user->email,
                        'name' => $attempt->user->name,
                    ],
                    'customizations' => [
                        'title' => 'RshopRefill Payment',
                        'description' => $attempt->order
                            ? "Order #{$attempt->order->order_number}"
                            : "Payment Ref #{$attempt->idempotency_key}",
                    ],
                ]);

            if ($response->failed()) {
                Log::error('Flutterwave hosted init HTTP failed', [
                    'status' => $response->status(),
                    'body' => $response->json(),
                    'tx_ref' => $attempt->idempotency_key,
                    'method_key' => $methodKey,
                    'payment_options' => $paymentOptions,
                ]);

                return [
                    'status' => 'failed',
                    'message' => 'Failed to initialise payment: '.($response->json('message') ?? 'Unknown error'),
                    'failure' => GatewayFailure::fromFlutterwave($response->json(), $response->status()),
                ];
            }

            $data = $response->json();
            $link = $data['data']['link'] ?? null;

            if (! $link) {
                Log::error('Flutterwave hosted init missing payment link', [
                    'body' => $data,
                    'tx_ref' => $attempt->idempotency_key,
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
            Log::error('Flutterwave hosted init exception', [
                'message' => $e->getMessage(),
                'tx_ref' => $attempt->idempotency_key,
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
        if ($this->mock) {
            $attempt->payment_status = PaymentStatus::Paid;
            $attempt->confirmed_at = now();
            $attempt->save();

            return true;
        }

        $txId = (string) $attempt->gateway_reference;

        // Flutterwave's /transactions/{id}/verify needs the NUMERIC transaction
        // id. 3DS / hosted flows often leave gateway_reference as the tx_ref
        // string, so verify by reference (tx_ref) whenever we lack a numeric id.
        if (! ctype_digit($txId)) {
            return $this->verifyUsingReference($attempt);
        }

        try {
            $response = $this->httpRead()
                ->get("{$this->baseUrl}/transactions/{$txId}/verify");

            if ($response->failed()) {
                Log::error("Flutterwave transaction verification failed: {$txId}");

                // Last resort: try the tx_ref lookup before giving up.
                return $this->verifyUsingReference($attempt);
            }

            $body = $response->json();

            if ($this->applyVerificationResult($attempt, $body['data'] ?? [], $body)) {
                return true;
            }

            // The id we hold may be a declined charge under this reference (a
            // first card refused in the popup) while a later card paid. Only
            // then is the reference looked up as well: a charge still pending
            // is simply pending, and asking twice on every poll would spend
            // the gateway budget the sweeper and the status page share.
            if (($body['data']['status'] ?? null) === 'failed') {
                return $this->verifyUsingReference($attempt);
            }

            return false;
        } catch (\Exception $e) {
            Log::error('Flutterwave verification error: '.$e->getMessage());

            return false;
        }
    }

    /**
     * Verify an attempt by its tx_ref (used when we have no numeric transaction
     * id, e.g. after a 3DS challenge). Captures the real id on success.
     */
    private function verifyUsingReference(PaymentAttempt $attempt): bool
    {
        $reference = $attempt->idempotency_key ?: (string) $attempt->gateway_reference;
        $result = $this->verifyByReference($reference);

        if (! $result) {
            return false;
        }

        // Capture the real numeric transaction id for any later lookups/refunds.
        if (! empty($result['transaction_id'])) {
            $attempt->gateway_reference = (string) $result['transaction_id'];
        }

        return $this->applyVerificationResult($attempt, $result, $result['raw'] ?? $result);
    }

    /**
     * Mark the attempt paid when the gateway reports success for the matching
     * amount + currency. Shared by id- and reference-based verification.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $payload
     */
    private function applyVerificationResult(PaymentAttempt $attempt, array $data, array $payload): bool
    {
        $status = $data['status'] ?? null;
        $amount = $data['amount'] ?? 0;
        $currency = $data['currency'] ?? '';
        $reportedRef = (string) ($data['tx_ref'] ?? '');

        // Cross-bind: the gateway-reported tx_ref MUST match the reference we
        // generated for this attempt. Without this, a verified-but-unrelated
        // transaction (e.g. a client-relayed id pointing at someone else's
        // successful charge for the same amount/currency) could mark THIS
        // attempt paid. hash_equals to keep the compare constant-time.
        if ($reportedRef === '' || ! hash_equals((string) $attempt->idempotency_key, $reportedRef)) {
            Log::warning('Flutterwave verification rejected: tx_ref mismatch', [
                'attempt_id' => $attempt->id,
                'expected' => $attempt->idempotency_key,
                'reported' => $reportedRef,
            ]);

            return false;
        }

        // Against what the gateway was asked for, which for a converted
        // mobile-money charge is the franc figure, not the attempt's own.
        [$expectedAmount, $expectedCurrency] = $this->chargedFigures($attempt);

        if ($status === 'successful'
            && (float) $amount >= $expectedAmount
            && strtoupper((string) $currency) === $expectedCurrency
        ) {
            $attempt->payment_status = PaymentStatus::Paid;
            $attempt->confirmed_at = now();
            $attempt->verification_payload = $payload;

            // A refusal remembered on an earlier look is stale once the
            // reference has paid.
            if (isset($attempt->metadata['gateway_verdict'])) {
                $attempt->metadata = array_diff_key($attempt->metadata, ['gateway_verdict' => true]);
            }

            // The card that paid, as a keyed fingerprint only. The risk
            // engine's instrument-reuse signal reads this to spot one card
            // funding many accounts; without it the signal abstained forever.
            $card = is_array($data['card'] ?? null) ? $data['card'] : [];
            $fingerprint = DeclineVerdict::fingerprint($card['first_6digits'] ?? null, $card['last_4digits'] ?? null);
            if ($fingerprint !== null) {
                $attempt->metadata = array_merge($attempt->metadata ?? [], [
                    'card_fingerprint' => $fingerprint,
                    'card_brand' => $card['type'] ?? null,
                    'card_country' => $card['country'] ?? null,
                ]);
            }

            $attempt->save();

            return true;
        }

        if ($status === 'failed') {
            $this->rememberGatewayVerdict($attempt, $data);
        }

        return false;
    }

    /**
     * The gateway's terminal "failed" on this reference, kept on the attempt
     * so the job that asked can fail the session with the reason instead of
     * leaving the customer on a spinner until the sweeper. Only a verdict
     * against this attempt's own reference gets here (the tx_ref check
     * above), and a later paid verification clears it.
     *
     * @param  array<string, mixed>  $data
     */
    private function rememberGatewayVerdict(PaymentAttempt $attempt, array $data): void
    {
        $processorResponse = $data['processor_response'] ?? null;

        $attempt->metadata = array_merge($attempt->metadata ?? [], [
            'gateway_verdict' => [
                'status' => 'failed',
                'processor_response' => is_string($processorResponse) && trim($processorResponse) !== '' ? trim($processorResponse) : null,
                'transaction_id' => isset($data['id']) ? (string) $data['id'] : (isset($data['transaction_id']) ? (string) $data['transaction_id'] : null),
                'payment_type' => $data['payment_type'] ?? null,
                'seen_at' => now()->toIso8601String(),
            ],
        ]);
        $attempt->save();
    }

    /**
     * The gateway's own "failed" verdict on this attempt's charge, when the
     * last verification saw one. Null while the charge is open or paid.
     *
     * @return array{status: string, processor_response: ?string, transaction_id: ?string, payment_type: ?string, seen_at: string}|null
     */
    public function terminalFailure(PaymentAttempt $attempt): ?array
    {
        $verdict = $attempt->metadata['gateway_verdict'] ?? null;

        return is_array($verdict) && ($verdict['status'] ?? null) === 'failed' ? $verdict : null;
    }

    /**
     * Every charge the gateway attempted under one of our references.
     *
     * The inline popup lets a customer try several cards under a single
     * tx_ref and reports none of the refusals by webhook, so this is the only
     * way to learn that a card was declined. Returns one normalised entry per
     * charge, oldest first, or an empty list when the gateway cannot be asked
     * (mock credentials, network trouble): a missing answer must never break
     * the checkout that asked.
     *
     * @return list<array{
     *     id: ?string, status: ?string, processor_response: ?string, amount: float, currency: ?string,
     *     payment_type: ?string, created_at: ?string,
     *     card: array{first_6digits: ?string, last_4digits: ?string, type: ?string, issuer: ?string, country: ?string}
     * }>
     */
    public function listChargesByReference(string $txRef): array
    {
        if ($this->mock || trim($txRef) === '') {
            return [];
        }

        $charges = [];

        try {
            $page = 1;
            $totalPages = 1;

            // The listing is paginated. A tester can run more cards inside one
            // popup than a single page holds (seventeen, in one production
            // case), so later pages are read too, up to a cap that keeps a slow
            // gateway from holding the request that asked.
            do {
                // Shorter than the shared timeouts: this runs while a customer
                // request or the sweeper waits, and a slow page is worth
                // giving up on.
                $response = $this->httpRead()
                    ->timeout(8)
                    ->connectTimeout(4)
                    ->get("{$this->baseUrl}/transactions", ['tx_ref' => $txRef, 'page' => $page]);

                if ($response->failed()) {
                    Log::warning('Flutterwave charge listing failed', [
                        'tx_ref' => $txRef,
                        'page' => $page,
                        'status' => $response->status(),
                    ]);

                    break;
                }

                $rows = $response->json('data');
                if (! is_array($rows)) {
                    break;
                }

                foreach ($rows as $row) {
                    if (! is_array($row)) {
                        continue;
                    }

                    $card = is_array($row['card'] ?? null) ? $row['card'] : [];

                    $charges[] = [
                        'id' => isset($row['id']) ? (string) $row['id'] : null,
                        'status' => isset($row['status']) ? strtolower((string) $row['status']) : null,
                        'processor_response' => isset($row['processor_response']) ? trim((string) $row['processor_response']) : null,
                        'amount' => (float) ($row['amount'] ?? 0),
                        'currency' => isset($row['currency']) ? strtoupper((string) $row['currency']) : null,
                        'payment_type' => $row['payment_type'] ?? null,
                        'created_at' => $row['created_at'] ?? null,
                        'card' => [
                            'first_6digits' => $card['first_6digits'] ?? null,
                            'last_4digits' => $card['last_4digits'] ?? null,
                            'type' => $card['type'] ?? null,
                            'issuer' => $card['issuer'] ?? null,
                            'country' => $card['country'] ?? null,
                        ],
                    ];
                }

                $totalPages = max(1, (int) $response->json('meta.page_info.total_pages', 1));
                $page++;
            } while ($page <= min($totalPages, self::MAX_CHARGE_LISTING_PAGES));
        } catch (\Throwable $e) {
            // A timeout on a later page keeps what the earlier pages returned.
            Log::warning('Flutterwave charge listing error: '.$e->getMessage(), ['tx_ref' => $txRef]);
        }

        usort($charges, fn (array $a, array $b) => strcmp((string) $a['created_at'], (string) $b['created_at']));

        return $charges;
    }

    public function refundPayment(PaymentAttempt $attempt, float $amount, ?string $reference = null): bool
    {
        if ($this->mock) {
            $attempt->payment_status = PaymentStatus::Refunded;
            $attempt->save();

            return true;
        }

        try {
            $response = $this->http()
                ->post("{$this->baseUrl}/refunds", [
                    'id' => $attempt->gateway_reference,
                    // The gateway holds the charge in the currency it was
                    // asked for; a converted mobile-money payment is refunded
                    // in francs, as the same share of the franc figure.
                    'amount' => $this->onChargedScale($attempt, $amount),
                ]);

            if ($response->failed()) {
                Log::error('Flutterwave refund failed', ['body' => $response->json()]);

                return false;
            }

            $attempt->payment_status = PaymentStatus::Refunded;
            $attempt->save();

            return true;
        } catch (\Exception $e) {
            Log::error('Flutterwave refund error: '.$e->getMessage());

            return false;
        }
    }

    public function verifyByReference(string $txRef): ?array
    {
        if ($this->mock) {
            return [
                'status' => 'successful',
                'amount' => 100.0,
                'currency' => 'USD',
                'transaction_id' => 'FLW-MOCK-'.uniqid(),
                'tx_ref' => $txRef,
                'raw' => ['simulated' => true],
            ];
        }

        try {
            $response = $this->httpRead()
                ->get("{$this->baseUrl}/transactions/verify_by_reference", [
                    'tx_ref' => $txRef,
                ]);

            if ($response->failed()) {
                Log::error("Flutterwave transaction reference verification failed for: {$txRef}", [
                    'status' => $response->status(),
                    'body' => $response->json(),
                ]);

                return null;
            }

            $body = $response->json();
            $data = $body['data'] ?? null;

            if ($data) {
                return [
                    'status' => $data['status'] ?? null,
                    'amount' => $data['amount'] ?? 0.0,
                    'currency' => $data['currency'] ?? '',
                    'transaction_id' => $data['id'] ?? null,
                    'tx_ref' => $data['tx_ref'] ?? null,
                    'processor_response' => $data['processor_response'] ?? null,
                    'payment_type' => $data['payment_type'] ?? null,
                    'raw' => $body,
                ];
            }

            return null;
        } catch (\Exception $e) {
            Log::error('Flutterwave verifyByReference error: '.$e->getMessage());

            return null;
        }
    }

    /**
     * A charge request that timed out or met a 5xx may still have reached
     * the gateway. Its guidance is to look the reference up before treating
     * the charge as lost, and never to send a second one blind. A charge the
     * gateway reports successful is confirmed here; one still pending is
     * handed to the caller's waiting state; one it refused carries the
     * refusal; a reference it does not know at all is a timeout, and nothing
     * was charged.
     *
     * @param  \Closure(array<string, mixed>): array<string, mixed>  $stillPending  Builds the waiting state from the gateway's raw answer.
     * @return array<string, mixed>
     */
    private function afterChargeInterrupted(PaymentAttempt $attempt, ?\Throwable $exception, ?Response $response, \Closure $stillPending): array
    {
        $txRef = (string) $attempt->idempotency_key;
        $known = $this->verifyByReference($txRef);

        if ($known !== null && hash_equals($txRef, (string) ($known['tx_ref'] ?? ''))) {
            // The gateway's numeric id, for every later lookup and refund.
            if (! empty($known['transaction_id'])) {
                $attempt->gateway_reference = (string) $known['transaction_id'];
                $attempt->save();
            }

            if ($this->applyVerificationResult($attempt, $known, $known['raw'] ?? $known)) {
                return [
                    'status' => 'confirmed',
                    'transaction_id' => (string) $attempt->gateway_reference,
                    'message' => 'Payment successful',
                ];
            }

            if (($known['status'] ?? null) === 'failed') {
                return [
                    'status' => 'failed',
                    'message' => 'The payment was not approved. Please try again or use another payment method.',
                    'failure' => GatewayFailure::fromFlutterwave($known['raw'] ?? ['data' => $known]),
                ];
            }

            Log::info('Flutterwave charge reached the gateway despite the interrupted answer', [
                'tx_ref' => $txRef,
                'status' => $known['status'] ?? null,
            ]);

            return $stillPending(is_array($known['raw'] ?? null) ? $known['raw'] : []);
        }

        Log::warning('Flutterwave charge interrupted and unknown to the gateway', [
            'tx_ref' => $txRef,
            'http_status' => $response?->status(),
            'error' => $exception?->getMessage(),
        ]);

        return [
            'status' => 'failed',
            'message' => 'The payment service took too long to answer and nothing was charged. Please try again in a moment.',
            'failure' => $exception !== null
                ? GatewayFailure::fromException($exception)
                : GatewayFailure::fromFlutterwave($response?->json(), $response?->status()),
        ];
    }

    /**
     * A card charge the gateway holds open after an interrupted answer has
     * no authorization step this checkout can resume (no flw_ref reached
     * us), so it is reported as the timeout it was; the reference is on the
     * attempt for the webhook, should the gateway finish it.
     *
     * @return array<string, mixed>
     */
    private function cardPendingAfterInterruption(): array
    {
        return [
            'status' => 'failed',
            'message' => 'The payment service took too long to answer. Please try again in a moment.',
            'failure' => GatewayFailure::internal('charge_pending_after_timeout', 'Charge open at the gateway after the request timed out'),
        ];
    }

    public function normalizeWebhook(array $payload): array
    {
        return [
            'status' => $payload['status'] ?? ($payload['data']['status'] ?? null),
            'amount' => $payload['amount'] ?? ($payload['data']['amount'] ?? 0),
            'currency' => $payload['currency'] ?? ($payload['data']['currency'] ?? 'USD'),
            'transaction_id' => $payload['id'] ?? ($payload['data']['id'] ?? null),
            'reference' => $payload['tx_ref'] ?? ($payload['data']['tx_ref'] ?? null),
        ];
    }
}
