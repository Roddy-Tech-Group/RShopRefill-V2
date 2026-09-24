<?php

namespace App\Domain\Payment\Providers;

use App\Domain\Payment\Enums\PaymentStatus;
use App\Domain\Payment\Interfaces\PaymentProviderInterface;
use App\Domain\Payment\Support\GatewayFailure;
use App\Domain\Payment\Support\MobileMoneyRail;
use App\Domain\Payment\Support\MockMode;
use App\Domain\PaymentInsights\Support\DeclineVerdict;
use App\Models\PaymentAttempt;
use App\Models\WalletFunding;
use Illuminate\Support\Facades\Http;
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

    public function initializePayment(PaymentAttempt $attempt): array
    {
        $txRef = $attempt->idempotency_key;
        $payable = $attempt->payable;

        $title = 'RshopRefills';
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
                'logo' => asset('assets/Rshoprefillslogo.webp'),
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
            $response = Http::withToken($this->secretKey)
                ->post("{$this->baseUrl}/charges?type=card", [
                    'client' => $encrypted,
                ]);

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
            $response = Http::withToken($this->secretKey)
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
                'bank_name' => 'Wema Bank (RshopRefills Mock)',
                'account_number' => '9982'.rand(100000, 999999),
                'account_name' => 'RshopRefills-Deposit-'.$attempt->user->id,
                'amount' => (float) $attempt->amount,
                'expires_at' => now()->addHour()->toIso8601String(),
            ];

            return [
                'status' => 'awaiting_transfer',
                'bank_details' => $bankDetails,
            ];
        }

        try {
            $response = Http::withToken($this->secretKey)
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

        if (MobileMoneyRail::requiresNetwork($currency)) {
            $gatewayNetwork = MobileMoneyRail::gatewayNetwork($currency, $network);

            if ($gatewayNetwork === null) {
                return [
                    'status' => 'failed',
                    'message' => "Choose a mobile money network available in {$currency}.",
                    'failure' => GatewayFailure::internal('mobile_money_unknown_network', "Network '{$network}' is not offered on the {$currency} rail"),
                ];
            }
        }

        if ($this->mock) {
            return [
                'status' => 'awaiting_confirmation',
                'message' => 'Please authorize the push notification sent to your phone ('.$phoneNumber.').',
            ];
        }

        $payload = [
            'amount' => (float) $attempt->amount,
            'currency' => $currency,
            'email' => $attempt->user->email,
            'phone_number' => $phone,
            'tx_ref' => $attempt->idempotency_key,
        ];

        if ($gatewayNetwork !== null) {
            $payload['network'] = $gatewayNetwork;
        }

        if (($country = MobileMoneyRail::countryFor($currency, $phone)) !== null) {
            $payload['country'] = $country;
        }

        if ($returnUrl !== null) {
            $payload['redirect_url'] = $returnUrl;
        }

        $chargeType = MobileMoneyRail::chargeType($currency);

        try {
            $response = Http::withToken($this->secretKey)
                ->post("{$this->baseUrl}/charges?type={$chargeType}", $payload);

            if ($response->failed()) {
                Log::warning('Flutterwave mobile money charge refused', [
                    'tx_ref' => $attempt->idempotency_key,
                    'rail' => $chargeType,
                    'currency' => $currency,
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
                if (($redirect = $this->mobileMoneyRedirect($data)) !== null) {
                    return [
                        'status' => 'awaiting_redirect',
                        'redirect_url' => $redirect,
                        'method' => 'mobile_money',
                    ];
                }

                return [
                    'status' => 'awaiting_confirmation',
                    'message' => $this->mobileMoneyInstruction($data, $phoneNumber),
                ];
            }

            return [
                'status' => 'failed',
                'message' => $data['message'] ?? 'Mobile money charge could not be initiated.',
                'failure' => GatewayFailure::fromFlutterwave($data),
            ];
        } catch (\Exception $e) {
            return [
                'status' => 'failed',
                'message' => 'Failed to initialize mobile money: '.$e->getMessage(),
                'failure' => GatewayFailure::fromException($e),
            ];
        }
    }

    /**
     * Build the on-screen confirmation instruction for a Francophone mobile-money
     * charge. Flutterwave returns the operator's own "dial *126# / approve the
     * prompt" wording in the authorization note (or processor_response), while the
     * top-level `message` is usually a terse "Charge initiated". Prefer the
     * actionable instruction so our modal mirrors what Flutterwave's hosted page
     * shows; fall back to a clear dial/approve prompt when none is present.
     *
     * @param  array<string, mixed>  $data  Decoded Flutterwave charge response.
     */
    /**
     * The rail's own authorization page, when it answers with one. Ghana,
     * Uganda and Rwanda do; the push-prompt rails leave it out.
     */
    private function mobileMoneyRedirect(array $data): ?string
    {
        $auth = $data['meta']['authorization']
            ?? $data['data']['meta']['authorization']
            ?? [];

        if (($auth['mode'] ?? null) !== 'redirect') {
            return null;
        }

        $redirect = $auth['redirect'] ?? null;

        return is_string($redirect) && str_starts_with($redirect, 'https://') ? $redirect : null;
    }

    private function mobileMoneyInstruction(array $data, string $phoneNumber): string
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
            // A real instruction is a sentence ("Dial *126# ..."), not a status
            // word like "initiated" / "pending" that some operators echo back.
            if (strlen($candidate) >= 12) {
                return $candidate;
            }
        }

        return "Dial your mobile money menu and approve the payment prompt sent to {$phoneNumber} to confirm. Keep this window open - it updates automatically once you approve.";
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
            $response = Http::withToken($this->secretKey)
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
            $response = Http::withToken($this->secretKey)
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
                        'title' => 'RshopRefills Payment',
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
            $response = Http::withToken($this->secretKey)
                ->get("{$this->baseUrl}/transactions/{$txId}/verify");

            if ($response->failed()) {
                Log::error("Flutterwave transaction verification failed: {$txId}");

                // Last resort: try the tx_ref lookup before giving up.
                return $this->verifyUsingReference($attempt);
            }

            $body = $response->json();

            return $this->applyVerificationResult($attempt, $body['data'] ?? [], $body);
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

        if ($status === 'successful'
            && (float) $amount >= (float) $attempt->amount
            && strtoupper((string) $currency) === strtoupper((string) $attempt->currency)
        ) {
            $attempt->payment_status = PaymentStatus::Paid;
            $attempt->confirmed_at = now();
            $attempt->verification_payload = $payload;

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

        return false;
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
                $response = Http::withToken($this->secretKey)
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
            $response = Http::withToken($this->secretKey)
                ->post("{$this->baseUrl}/refunds", [
                    'id' => $attempt->gateway_reference,
                    'amount' => $amount,
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
            $response = Http::withToken($this->secretKey)
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
                    'raw' => $body,
                ];
            }

            return null;
        } catch (\Exception $e) {
            Log::error('Flutterwave verifyByReference error: '.$e->getMessage());

            return null;
        }
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
