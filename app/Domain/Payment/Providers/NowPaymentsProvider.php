<?php

namespace App\Domain\Payment\Providers;

use App\Domain\Payment\Enums\PaymentStatus;
use App\Domain\Payment\Interfaces\PaymentProviderInterface;
use App\Domain\Payment\Support\GatewayFailure;
use App\Domain\Payment\Support\MockMode;
use App\Domain\Payment\Support\NowPaymentsStatus;
use App\Domain\Wallet\Services\CurrencyRateService;
use App\Models\PaymentAttempt;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class NowPaymentsProvider implements PaymentProviderInterface
{
    /**
     * Fiat currencies NOWPayments accepts directly as price_currency. Anything
     * outside this list is converted to USD before the call.
     *
     * NOTE: NGN is deliberately absent. NOWPayments lists it as a dashboard base
     * currency, but that is not confirmed to be the same set the API accepts on
     * price_currency, and guessing wrong silently changes what a customer is
     * charged. Verify in sandbox before adding it.
     */
    private const SUPPORTED_FIATS = [
        'USD', 'EUR', 'GBP', 'CAD', 'AUD', 'AED', 'CZK', 'DKK', 'HKD', 'HUF',
        'ILS', 'INR', 'JPY', 'MXN', 'NOK', 'NZD', 'PLN', 'RUB', 'SEK', 'SGD',
        'THB', 'TRY', 'ZAR',
    ];

    private string $apiKey;

    private string $baseUrl;

    private bool $mock;

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

        // Mock mode is decided by environment policy, never by inspecting the key.
        // The old str_contains($apiKey, 'MOCK') test meant a real production key
        // that happened to contain that substring would silently disable the live
        // gateway and start handing out fake payment addresses.
        $this->mock = empty($apiKey) && MockMode::allowed();

        $this->baseUrl = $this->resolveBaseUrl();
    }

    private function resolveBaseUrl(): string
    {
        if (config('services.nowpayments.sandbox')) {
            return config('services.nowpayments.sandbox_base_url') ?: 'https://api-sandbox.nowpayments.io/v1';
        }

        return config('services.nowpayments.base_url') ?: 'https://api.nowpayments.io/v1';
    }

    /**
     * Reserve the attempt WITHOUT contacting the gateway.
     *
     * A crypto payment cannot be created until the customer has chosen a coin —
     * the pay currency determines the address, the amount and the minimum. This
     * method previously created a payment hardcoded to `pay_currency => btc` at
     * order placement, before any coin was selected, and chargeCrypto() then
     * created a second payment and overwrote gateway_reference. That left an
     * orphan BTC invoice on every crypto order, and a customer who paid the first
     * address produced an IPN that matched no attempt and was lost.
     *
     * @return array{provider: string, mode: string, status: string, expires_at: null}
     */
    public function initializePayment(PaymentAttempt $attempt): array
    {
        return [
            'provider' => 'nowpayments',
            'mode' => 'embedded_crypto',
            'status' => 'awaiting_currency_selection',
            'expires_at' => null,
        ];
    }

    /**
     * Create the real NOWPayments payment once the customer has picked a coin.
     *
     * The returned pay_amount is the ONLY authoritative figure for what the
     * customer owes. NOWPayments does not publish the formula it uses to gross up
     * the amount when fees are passed to the user, so the amount cannot be
     * computed locally — it can only be read back from this response.
     *
     * @return array<string, mixed>
     */
    public function chargeCrypto(PaymentAttempt $attempt, string $payCurrency): array
    {
        $payCurrency = $this->normalizePayCurrency($payCurrency);

        // Outbound idempotency, enforced locally because NOWPayments publishes no
        // idempotency key or header. A double-click, a retried request or a
        // customer re-opening the payment step would otherwise create a second live
        // invoice for the same order, and whichever address they then paid would
        // leave the other one orphaned. If this attempt already holds a live quote
        // for the same coin, that quote is returned unchanged rather than replaced.
        if ($existing = $this->existingQuote($attempt, $payCurrency)) {
            return $existing;
        }

        if ($this->mock) {
            return $this->mockCryptoPayment($attempt, $payCurrency);
        }

        [$priceAmount, $priceCurrency] = $this->resolvePrice($attempt);

        try {
            $payload = [
                'price_amount' => $priceAmount,
                'price_currency' => $priceCurrency,
                'pay_currency' => $payCurrency,
                'ipn_callback_url' => route('api.webhooks.nowpayments'),
                'order_description' => $this->describe($attempt),

                // Both flags together are what make the quote trustworthy:
                // is_fee_paid_by_user grosses pay_amount up so we net price_amount,
                // and is_fixed_rate locks the exchange rate so the customer is not
                // judged underpaid because the market moved while their transaction
                // confirmed. NOWPayments forces fixed-rate on whenever fees are paid
                // by the user, so these are sent as a consistent pair.
                'is_fee_paid_by_user' => (bool) config('services.nowpayments.fee_paid_by_user', true),
                'is_fixed_rate' => (bool) config('services.nowpayments.fixed_rate', true),
            ];

            if ($attempt->order) {
                $payload['order_id'] = $attempt->order->id;
            }

            // Sandbox-only outcome simulator. Ignored by production, and only ever
            // sent when the sandbox flag is on so it can't leak into live traffic.
            if (config('services.nowpayments.sandbox') && ($case = config('services.nowpayments.sandbox_case'))) {
                $payload['case'] = $case;
            }

            $response = Http::withHeaders([
                'x-api-key' => $this->apiKey,
                'Content-Type' => 'application/json',
            ])->post("{$this->baseUrl}/payment", $payload);

            if ($response->failed()) {
                Log::error('NowPayments payment creation failed', [
                    'attempt_id' => $attempt->id,
                    'pay_currency' => $payCurrency,
                    'status' => $response->status(),
                    'body' => $response->json(),
                ]);

                // Gateway text is logged and carried in the failure block for
                // telemetry, never surfaced to the customer — it leaks internals
                // and reads as gibberish.
                return [
                    'status' => 'failed',
                    'message' => 'We could not start this crypto payment. Please try another coin or payment method.',
                    'failure' => GatewayFailure::fromNowPayments($response->json(), $response->status()),
                ];
            }

            return $this->hydrateFromPaymentResponse($attempt, $response->json(), $payCurrency);
        } catch (\Throwable $e) {
            Log::error('NowPayments chargeCrypto error', [
                'attempt_id' => $attempt->id,
                'error' => $e->getMessage(),
            ]);

            return [
                'status' => 'failed',
                'message' => 'We could not start this crypto payment. Please try again.',
                'failure' => GatewayFailure::fromException($e),
            ];
        }
    }

    /**
     * The live quote already on this attempt, if it still applies.
     *
     * Reusable only when it is for the same coin, still has an address, and has
     * not lapsed — a stale quote must not be handed back, because its amount was
     * locked against a rate that has since moved.
     *
     * @return array<string, mixed>|null
     */
    private function existingQuote(PaymentAttempt $attempt, string $payCurrency): ?array
    {
        if (! $attempt->gateway_reference || ! $attempt->pay_address) {
            return null;
        }

        if ($attempt->pay_currency !== $payCurrency) {
            return null;
        }

        if ($attempt->expires_at && $attempt->expires_at->isPast()) {
            return null;
        }

        Log::info('NowPayments: reusing live quote instead of creating a duplicate payment', [
            'attempt_id' => $attempt->id,
            'payment_id' => $attempt->gateway_reference,
            'pay_currency' => $payCurrency,
        ]);

        $displayAmount = $this->displayPayAmount((float) $attempt->pay_amount);

        return [
            'status' => 'awaiting_transfer',
            'invoice_id' => $attempt->gateway_reference,
            'gateway_reference' => $attempt->gateway_reference,
            'pay_address' => $attempt->pay_address,
            'pay_amount' => $displayAmount,
            'exact_pay_amount' => (string) $attempt->pay_amount,
            'pay_currency' => $attempt->pay_currency,
            'network' => $attempt->network,
            'payin_extra_id' => $attempt->payin_extra_id,
            'purchase_id' => $attempt->purchase_id,
            'qr_payload' => "{$attempt->network}:{$attempt->pay_address}?amount={$displayAmount}",
            'expires_at' => $attempt->expires_at?->toIso8601String(),
        ];
    }

    /**
     * Persist the gateway's answer and shape it for the payment session.
     *
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private function hydrateFromPaymentResponse(PaymentAttempt $attempt, array $body, string $payCurrency): array
    {
        $gatewayRef = $body['payment_id'] ?? null;
        $payAddress = $body['pay_address'] ?? '';
        $payAmount = $body['pay_amount'] ?? 0;

        // Prefer the network the gateway reports over our local lookup table —
        // the table is a fallback for display only and drifts as coins are added.
        $network = $body['network'] ?? $this->resolveNetwork($payCurrency);

        // expiration_estimate_date bounds the fixed-rate quote and is the value the
        // countdown must run on. time_limit is undocumented and null in every
        // published example, so it is only a fallback. Never fabricate an expiry:
        // a made-up 30-minute window is what previously cancelled orders that were
        // still mid-confirmation.
        $expiresAt = $body['expiration_estimate_date'] ?? $body['time_limit'] ?? null;

        $attempt->gateway_reference = $gatewayRef;
        $attempt->expires_at = $this->parseGatewayTimestamp($expiresAt);

        // Queryable projection. The JSON below stays as the raw record; these are
        // what reconciliation, reporting and the overpayment sweep actually read.
        $attempt->pay_currency = $payCurrency;
        $attempt->pay_amount = is_numeric($payAmount) ? (float) $payAmount : null;
        $attempt->network = $network;
        $attempt->pay_address = $payAddress;
        $attempt->payin_extra_id = $body['payin_extra_id'] ?? null;
        $attempt->purchase_id = $body['purchase_id'] ?? null;

        $attempt->metadata = array_merge($attempt->metadata ?? [], [
            'crypto' => [
                'pay_currency' => $payCurrency,
                'pay_amount' => (string) $payAmount,
                'pay_address' => $payAddress,
                'network' => $network,
                'payin_extra_id' => $body['payin_extra_id'] ?? null,
                'purchase_id' => $body['purchase_id'] ?? null,
                'network_precision' => $body['network_precision'] ?? null,
                'price_amount' => $body['price_amount'] ?? null,
                'price_currency' => $body['price_currency'] ?? null,
                'expiration_estimate_date' => $expiresAt,
            ],
        ]);
        $attempt->save();

        // What the customer is shown and what their wallet scans is the rounded-up
        // figure; the exact gateway quote stays on the attempt as the settlement
        // yardstick. Paying the displayed amount therefore always clears it.
        $displayAmount = $this->displayPayAmount((float) $payAmount);

        return [
            'status' => 'awaiting_transfer',
            'invoice_id' => $gatewayRef,
            'gateway_reference' => $gatewayRef,
            'pay_address' => $payAddress,
            'pay_amount' => $displayAmount,
            // Kept for support and reconciliation: the untouched gateway figure,
            // so a settlement query never has to reverse the rounding.
            'exact_pay_amount' => (string) $payAmount,
            'pay_currency' => $payCurrency,
            'network' => $network,
            // Memo/destination tag. Coins that require one cannot be credited
            // automatically without it, so it must reach the payment screen.
            'payin_extra_id' => $body['payin_extra_id'] ?? null,
            'purchase_id' => $body['purchase_id'] ?? null,
            'qr_payload' => "{$network}:{$payAddress}?amount={$displayAmount}",
            'expires_at' => $expiresAt,
        ];
    }

    /**
     * The amount to put in front of the customer: the gateway's figure, rounded UP
     * to a precision a person can actually type.
     *
     * The gateway quotes to full precision — 11.655811 USDT for an $11.20 order.
     * Nobody types that; they type 11.65, which is short by half a cent, and under
     * zero tolerance that holds the order. Worse, the resulting shortfall is far
     * below every coin's minimum, so the top-up flow cannot rescue it either and
     * the customer is stranded over fractions of a cent.
     *
     * Rounding UP removes the problem instead of tolerating it: the displayed
     * figure is always ≥ what the gateway requires, so paying exactly what we show
     * settles in full. The few thousandths of surplus land in settlement_delta as a
     * fractional overpayment. Nothing is absorbed and no tolerance is introduced.
     *
     * Precision scales with magnitude so the round-up stays negligible: a
     * stablecoin gets 2 decimals, a sub-unit amount keeps 8 so a BTC quote is not
     * coarsened into a real overpayment.
     */
    private function displayPayAmount(float $payAmount): string
    {
        if ($payAmount <= 0) {
            return '0';
        }

        $decimals = match (true) {
            $payAmount >= 1 => 2,
            $payAmount >= 0.01 => 4,
            default => 8,
        };

        $factor = 10 ** $decimals;
        $rounded = ceil($payAmount * $factor) / $factor;

        // Trailing zeros are noise on a figure someone is copying by hand.
        return rtrim(rtrim(number_format($rounded, $decimals, '.', ''), '0'), '.') ?: '0';
    }

    /**
     * @return array{0: float, 1: string}
     */
    private function resolvePrice(PaymentAttempt $attempt): array
    {
        $priceCurrency = strtoupper($attempt->currency);
        $priceAmount = (float) $attempt->amount;

        if (! in_array($priceCurrency, self::SUPPORTED_FIATS, true)) {
            $rate = app(CurrencyRateService::class)->resolveRate($priceCurrency, 'USD');
            $priceAmount = round($priceAmount * $rate, 2);
            $priceCurrency = 'USD';
        }

        return [$priceAmount, $priceCurrency];
    }

    private function describe(PaymentAttempt $attempt): string
    {
        if ($attempt->order) {
            return "Order #{$attempt->order->order_number}";
        }

        return 'Wallet Deposit Ref #'.($attempt->payable->reference ?? $attempt->id);
    }

    /**
     * Verify a payment server-to-server. This is the only place a payment is
     * allowed to be marked Paid — the webhook body is never trusted for money.
     */
    public function verifyPayment(PaymentAttempt $attempt): bool
    {
        if ($this->mock) {
            $attempt->payment_status = PaymentStatus::Paid;
            $attempt->confirmed_at = now();
            $attempt->save();

            return true;
        }

        if (! $attempt->gateway_reference) {
            return false;
        }

        try {
            $response = Http::withHeaders([
                'x-api-key' => $this->apiKey,
            ])->get("{$this->baseUrl}/payment/{$attempt->gateway_reference}");

            if ($response->failed()) {
                Log::error('NowPayments verify failed', [
                    'payment_id' => $attempt->gateway_reference,
                    'status' => $response->status(),
                ]);

                return false;
            }

            $body = $response->json();

            return $this->applyVerifiedState($attempt, $body);
        } catch (\Throwable $e) {
            Log::error('NowPayments verify error', [
                'payment_id' => $attempt->gateway_reference,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Translate a verified gateway payload into local state.
     *
     * @param  array<string, mixed>  $body
     */
    private function applyVerifiedState(PaymentAttempt $attempt, array $body): bool
    {
        $status = NowPaymentsStatus::normalize($body['payment_status'] ?? null);
        $paymentId = $attempt->gateway_reference;

        $attempt->verification_payload = $body;
        $this->recordSettlementFacts($attempt, $body);

        // Monotonic guard: NOWPayments gives no IPN ordering guarantee, so a
        // late `confirming` must never walk back a payment already settled.
        $recordedRank = (int) data_get($attempt->metadata, 'crypto.status_rank', 0);
        $incomingRank = NowPaymentsStatus::rank($status);

        if ($incomingRank < $recordedRank) {
            Log::info('NowPayments verify: ignoring out-of-order status', [
                'payment_id' => $paymentId,
                'incoming' => $status,
                'recorded_rank' => $recordedRank,
            ]);
            $attempt->save();

            return $attempt->payment_status === PaymentStatus::Paid;
        }

        $attempt->metadata = array_merge($attempt->metadata ?? [], [
            'crypto' => array_merge((array) data_get($attempt->metadata, 'crypto', []), [
                'status' => $status,
                'status_rank' => $incomingRank,
            ]),
        ]);

        if (NowPaymentsStatus::isSettled($status)) {
            // Zero tolerance, enforced in crypto terms. NOWPayments can report a
            // settled status while actually_paid is short, so the amount is checked
            // here rather than inferred from the status alone.
            if (! $this->isPaidInFull($body)) {
                Log::warning('NowPayments verify: settled status but short payment — holding', [
                    'payment_id' => $paymentId,
                    'pay_amount' => $body['pay_amount'] ?? null,
                    'actually_paid' => $body['actually_paid'] ?? null,
                ]);

                $attempt->payment_status = PaymentStatus::PartiallyPaid;
                $attempt->save();

                return false;
            }

            $attempt->payment_status = PaymentStatus::Paid;
            $attempt->confirmed_at = now();
            $attempt->save();

            return true;
        }

        if (NowPaymentsStatus::isUnderpaid($status)) {
            // Deliberate business rule: an underpaid order is NEVER auto-fulfilled,
            // however small the shortfall. On refill margins a 1% write-off can
            // exceed the profit on the order. The customer completes it via the
            // top-up flow, or it is resolved by an operator.
            Log::warning('NowPayments verify: underpaid, held for top-up', [
                'payment_id' => $paymentId,
                'pay_amount' => $body['pay_amount'] ?? null,
                'actually_paid' => $body['actually_paid'] ?? null,
                'shortfall' => $this->shortfall($body),
            ]);

            $attempt->payment_status = PaymentStatus::PartiallyPaid;
            $attempt->save();

            return false;
        }

        // Terminal non-settled states now write real state. Previously these all
        // fell through to a bare `return false`, leaving attempts stuck on Pending
        // forever with no signal to the customer, the sweeper or support.
        if (NowPaymentsStatus::isFailed($status)) {
            $attempt->payment_status = PaymentStatus::Failed;
            $attempt->failed_at = now();
            $attempt->save();

            return false;
        }

        if (NowPaymentsStatus::isExpired($status)) {
            $attempt->payment_status = PaymentStatus::Expired;
            $attempt->failed_at = now();
            $attempt->save();

            return false;
        }

        if (NowPaymentsStatus::isRefunded($status)) {
            $attempt->payment_status = PaymentStatus::Refunded;
            $attempt->save();

            return false;
        }

        if (! NowPaymentsStatus::isPending($status)) {
            // Unknown status. NOWPayments documents statuses in its help centre that
            // are absent from its API reference, so this is expected to fire
            // occasionally and must be visible rather than silently swallowed.
            Log::warning('NowPayments verify: unrecognised payment_status', [
                'payment_id' => $paymentId,
                'payment_status' => $status,
            ]);
        }

        $attempt->save();

        return false;
    }

    /**
     * Zero-tolerance full-payment check, compared in crypto terms.
     *
     * @param  array<string, mixed>  $body
     */
    private function isPaidInFull(array $body): bool
    {
        $payAmount = (float) ($body['pay_amount'] ?? 0);
        $actuallyPaid = (float) ($body['actually_paid'] ?? 0);

        if ($payAmount <= 0) {
            return false;
        }

        // SANDBOX ONLY. The sandbox simulates outcomes but never populates
        // actually_paid — it reports 0 even on a payment it has moved to
        // `finished` with a real outcome_amount. Compared strictly, every
        // simulated success therefore looks like a 100% underpayment, and the
        // settlement path cannot be exercised even once before going live.
        //
        // Accepting outcome_amount as the evidence of settlement here is what makes
        // pre-launch verification possible. It is deliberately narrow: it requires
        // the gateway to have already reported a settled status (this method is
        // only reached from that branch), and it can never run in production, where
        // a settled payment always carries actually_paid.
        //
        // The trade-off is real and worth stating: in sandbox this bypasses the
        // zero-tolerance amount check, so sandbox proves the plumbing — statuses,
        // fulfilment, session transitions — not the shortfall guard itself. That
        // guard is covered by the test suite instead.
        if ($actuallyPaid <= 0
            && config('services.nowpayments.sandbox')
            && (float) ($body['outcome_amount'] ?? 0) > 0) {
            Log::info('NowPayments: accepting sandbox settlement without actually_paid', [
                'payment_id' => $body['payment_id'] ?? null,
                'outcome_amount' => $body['outcome_amount'] ?? null,
            ]);

            return true;
        }

        return $actuallyPaid >= $payAmount;
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function shortfall(array $body): float
    {
        $payAmount = (float) ($body['pay_amount'] ?? 0);
        $actuallyPaid = (float) ($body['actually_paid'] ?? 0);

        return round(max(0, $payAmount - $actuallyPaid), 8);
    }

    /**
     * Persist the amounts and fee breakdown needed to reconcile against a
     * NOWPayments settlement export, and to detect overpayment after the fact.
     *
     * @param  array<string, mixed>  $body
     */
    private function recordSettlementFacts(PaymentAttempt $attempt, array $body): void
    {
        $payAmount = (float) ($body['pay_amount'] ?? 0);
        $actuallyPaid = (float) ($body['actually_paid'] ?? 0);

        $attempt->pay_amount = isset($body['pay_amount']) && is_numeric($body['pay_amount'])
            ? (float) $body['pay_amount']
            : $attempt->pay_amount;
        $attempt->actually_paid = isset($body['actually_paid']) && is_numeric($body['actually_paid'])
            ? (float) $body['actually_paid']
            : $attempt->actually_paid;
        $attempt->outcome_amount = isset($body['outcome_amount']) && is_numeric($body['outcome_amount'])
            ? (float) $body['outcome_amount']
            : null;
        $attempt->outcome_currency = $body['outcome_currency'] ?? null;
        $attempt->gateway_fee = $body['fee'] ?? null;
        $attempt->purchase_id = $body['purchase_id'] ?? $attempt->purchase_id;

        // Signed: positive means the customer overpaid and is owed the surplus,
        // negative means they are short and the order must not be fulfilled.
        //
        // Requires funds to have actually ARRIVED, not merely been invoiced. A
        // payment sitting at `waiting` reports actually_paid = 0, and measuring a
        // delta against that produced a full-value negative — which read as "your
        // payment was short by the entire amount" on a payment nobody had made yet.
        // Not-paid-yet and paid-too-little are different states and must not
        // collapse into one.
        $attempt->settlement_delta = ($payAmount > 0 && $actuallyPaid > 0)
            ? round($actuallyPaid - $payAmount, 12)
            : null;

        $attempt->metadata = array_merge($attempt->metadata ?? [], [
            'crypto' => array_merge((array) data_get($attempt->metadata, 'crypto', []), [
                'pay_amount' => $body['pay_amount'] ?? null,
                'actually_paid' => $body['actually_paid'] ?? null,
                'actually_paid_at_fiat' => $body['actually_paid_at_fiat'] ?? null,
                'outcome_amount' => $body['outcome_amount'] ?? null,
                'outcome_currency' => $body['outcome_currency'] ?? null,
                'purchase_id' => $body['purchase_id'] ?? data_get($attempt->metadata, 'crypto.purchase_id'),
                'fee' => $body['fee'] ?? null,
                // Overpayment is recorded rather than pocketed silently. Nothing
                // consumes this yet; the wallet-credit path lands in Phase 2.
                'overpaid_by' => $actuallyPaid > $payAmount
                    ? round($actuallyPaid - $payAmount, 8)
                    : null,
            ]),
        ]);
    }

    /**
     * Create a follow-up payment covering the shortfall on an underpaid attempt.
     *
     * Because we never auto-fulfil a short payment, a customer whose exchange
     * deducted its withdrawal fee would otherwise be stranded: their money has
     * arrived, their order has not shipped, and there is no way for them to make up
     * the difference. This is that way.
     *
     * The shortfall is derived in CRYPTO terms and then applied proportionally to
     * the original fiat price, because the crypto amounts are the ones we can
     * compare exactly — the fiat equivalent moves with the market.
     *
     * purchase_id ties the new payment to the original at the gateway, so both legs
     * reconcile as one purchase rather than two unrelated payments.
     *
     * @return array<string, mixed>
     */
    public function createTopUpPayment(PaymentAttempt $attempt): array
    {
        $payAmount = (float) $attempt->pay_amount;
        $actuallyPaid = (float) $attempt->actually_paid;

        if ($payAmount <= 0 || $actuallyPaid >= $payAmount) {
            return ['status' => 'failed', 'message' => 'This payment has no outstanding balance.', 'failure' => GatewayFailure::internal('no_outstanding_balance')];
        }

        if (! $attempt->purchase_id) {
            // Without it the gateway cannot bind the second payment to the first,
            // and we would be creating an unrelated invoice that reconciles as a
            // separate purchase.
            Log::warning('NowPayments top-up requested without a purchase_id', [
                'attempt_id' => $attempt->id,
            ]);

            return ['status' => 'failed', 'message' => 'This payment cannot be topped up automatically. Please contact support.', 'failure' => GatewayFailure::internal('missing_purchase_id', 'Top-up requested without a purchase_id to bind it to')];
        }

        $shortfallRatio = ($payAmount - $actuallyPaid) / $payAmount;
        $payCurrency = $this->normalizePayCurrency((string) $attempt->pay_currency);

        if ($this->mock) {
            return $this->mockCryptoPayment($attempt, $payCurrency);
        }

        [$originalPrice, $priceCurrency] = $this->resolvePrice($attempt);
        $topUpPrice = round($originalPrice * $shortfallRatio, 2);

        if ($topUpPrice <= 0) {
            return ['status' => 'failed', 'message' => 'This payment has no outstanding balance.', 'failure' => GatewayFailure::internal('no_outstanding_balance')];
        }

        try {
            $response = Http::withHeaders([
                'x-api-key' => $this->apiKey,
                'Content-Type' => 'application/json',
            ])->post("{$this->baseUrl}/payment", [
                'price_amount' => $topUpPrice,
                'price_currency' => $priceCurrency,
                'pay_currency' => $payCurrency,
                'purchase_id' => $attempt->purchase_id,
                'ipn_callback_url' => route('api.webhooks.nowpayments'),
                'order_description' => 'Balance for '.$this->describe($attempt),
                'is_fee_paid_by_user' => (bool) config('services.nowpayments.fee_paid_by_user', true),
                'is_fixed_rate' => (bool) config('services.nowpayments.fixed_rate', true),
            ]);

            if ($response->failed()) {
                Log::error('NowPayments top-up creation failed', [
                    'attempt_id' => $attempt->id,
                    'status' => $response->status(),
                    'body' => $response->json(),
                ]);

                return ['status' => 'failed', 'message' => 'We could not create a top-up payment. Please contact support.', 'failure' => GatewayFailure::fromNowPayments($response->json(), $response->status())];
            }

            // The top-up replaces the attempt's live quote: gateway_reference moves to
            // the new payment id so the IPN for it matches this attempt, and the
            // amounts are re-anchored to what is now outstanding.
            return $this->hydrateFromPaymentResponse($attempt, $response->json(), $payCurrency);
        } catch (\Throwable $e) {
            Log::error('NowPayments top-up error', [
                'attempt_id' => $attempt->id,
                'error' => $e->getMessage(),
            ]);

            return ['status' => 'failed', 'message' => 'We could not create a top-up payment. Please try again.', 'failure' => GatewayFailure::fromException($e)];
        }
    }

    /**
     * The minimum the gateway will accept for this pair, in the pay currency.
     *
     * Fixed-rate minimums run materially higher than floating ones, so the flags
     * passed here must match the flags used when the payment is actually created.
     *
     * @return array{min_amount: float, fiat_equivalent: float|null}|null
     */
    public function getMinimumAmount(string $payCurrency, string $priceCurrency = 'usd'): ?array
    {
        if ($this->mock) {
            return ['min_amount' => 0.0, 'fiat_equivalent' => 0.0];
        }

        try {
            $response = Http::withHeaders([
                'x-api-key' => $this->apiKey,
            ])->get("{$this->baseUrl}/min-amount", [
                'currency_from' => $this->normalizePayCurrency($payCurrency),
                'fiat_equivalent' => strtolower($priceCurrency),
                'is_fixed_rate' => config('services.nowpayments.fixed_rate', true) ? 'true' : 'false',
                'is_fee_paid_by_user' => config('services.nowpayments.fee_paid_by_user', true) ? 'true' : 'false',
            ]);

            if ($response->failed()) {
                Log::warning('NowPayments min-amount failed', [
                    'pay_currency' => $payCurrency,
                    'status' => $response->status(),
                ]);

                return null;
            }

            $body = $response->json();

            return [
                'min_amount' => (float) ($body['min_amount'] ?? 0),
                'fiat_equivalent' => isset($body['fiat_equivalent']) ? (float) $body['fiat_equivalent'] : null,
            ];
        } catch (\Throwable $e) {
            Log::warning('NowPayments min-amount error: '.$e->getMessage());

            return null;
        }
    }

    /**
     * Coins actually enabled on our merchant account.
     *
     * /merchant/coins is the correct source — the public /currencies list includes
     * tickers the account cannot accept, which fail at payment creation with
     * "payCurrency is not allowed".
     *
     * @return array<int, string>
     */
    public function getMerchantCurrencies(): array
    {
        if ($this->mock) {
            return ['btc', 'eth', 'ltc', 'sol', 'usdtbsc', 'usdttrc20', 'usdtsol', 'usdcbsc', 'usdcsol', 'usdcbase'];
        }

        try {
            $response = Http::withHeaders([
                'x-api-key' => $this->apiKey,
            ])->get("{$this->baseUrl}/merchant/coins");

            if ($response->failed()) {
                Log::warning('NowPayments merchant coins failed', ['status' => $response->status()]);

                return [];
            }

            return array_map('strtolower', (array) $response->json('selectedCurrencies', []));
        } catch (\Throwable $e) {
            Log::warning('NowPayments merchant coins error: '.$e->getMessage());

            return [];
        }
    }

    /**
     * NOWPayments has NO refund API — refunds are the merchant's responsibility
     * and can only be issued manually or off-platform.
     *
     * This previously wrote PaymentStatus::Refunded and returned true, so
     * RefundPaymentJob logged a successful refund for money that had never moved.
     * Returning false is the honest answer: the caller must escalate rather than
     * record a refund that did not happen.
     */
    public function refundPayment(PaymentAttempt $attempt, float $amount): bool
    {
        Log::critical('NowPayments refund requested but no refund API exists — manual action required', [
            'attempt_id' => $attempt->id,
            'payment_id' => $attempt->gateway_reference,
            'amount' => $amount,
        ]);

        return false;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function normalizeWebhook(array $payload): array
    {
        return [
            'status' => $payload['payment_status'] ?? null,
            'amount' => $payload['price_amount'] ?? 0,
            'currency' => $payload['price_currency'] ?? 'USD',
            'transaction_id' => $payload['payment_id'] ?? null,
            // The /payment flow keys everything by payment_id; invoice_id only
            // exists in the separate /invoice flow and is null here.
            'reference' => $payload['payment_id'] ?? null,
        ];
    }

    /**
     * Approximate conversion for the coin picker preview ONLY.
     *
     * This is explicitly documented as approximate and does not account for fee
     * or markup settings. It must never be used as the amount a customer is asked
     * to send — that figure comes from chargeCrypto()'s pay_amount and nowhere else.
     *
     * @return array{estimated_amount: float, rate: float}|null
     */
    public function estimatePayment(float $priceAmount, string $priceCurrency, string $payCurrency): ?array
    {
        if ($this->mock) {
            $mockRate = $this->mockRate($this->normalizePayCurrency($payCurrency));

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
                Log::warning('NowPayments estimate failed', ['status' => $response->status()]);

                return null;
            }

            $estimated = (float) ($response->json('estimated_amount') ?? 0);

            return [
                'estimated_amount' => $estimated,
                'rate' => $priceAmount > 0 ? $estimated / $priceAmount : 0,
            ];
        } catch (\Throwable $e) {
            Log::warning('NowPayments estimate error: '.$e->getMessage());

            return null;
        }
    }

    /**
     * Normalize a coin code to the ticker NOWPayments accepts. The bare "usdt"
     * and "bnb" tickers are rejected as invalid currencies, so every outbound
     * call must translate them to a concrete chain. All other tickers pass
     * through lowercased.
     */
    private function normalizePayCurrency(string $payCurrency): string
    {
        $payCurrency = strtolower(trim($payCurrency));

        return match ($payCurrency) {
            'usdt' => 'usdttrc20',
            'bnb' => 'bnbbsc',
            default => $payCurrency,
        };
    }

    /**
     * Display-only fallback for the chain name when the gateway response does not
     * carry one. The gateway's own `network` field is authoritative.
     */
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
            'usdtton' => 'ton',
            // Bare usdc is the ERC-20 ticker at NOWPayments — USDC has no Tron
            // chain, so unlike usdt it needs no TRC20 normalization.
            'usdc', 'usdcerc20' => 'ethereum',
            'usdcsol' => 'solana',
            'usdcbase' => 'base',
            'usdcbsc' => 'bnb',
            'usdcmatic' => 'polygon',
            'ltc' => 'litecoin',
            'bnb', 'bnbbsc' => 'bnb',
            'matic' => 'polygon',
            'sol' => 'solana',
            'trx' => 'tron',
            'ton' => 'ton',
            'doge' => 'dogecoin',
            'xrp' => 'ripple',
            default => strtolower($currency),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function mockCryptoPayment(PaymentAttempt $attempt, string $payCurrency): array
    {
        $gatewayRef = 'NP-MOCK-'.uniqid();
        $payAddress = $this->resolveMockAddress($payCurrency);
        $payAmount = round((float) $attempt->amount * $this->mockRate($payCurrency), 8);
        $network = $this->resolveNetwork($payCurrency);
        $expiresAt = now()->addMinutes(20)->toIso8601String();

        $attempt->gateway_reference = $gatewayRef;
        $attempt->expires_at = now()->addMinutes(20);
        $attempt->pay_currency = $payCurrency;
        $attempt->pay_amount = $payAmount;
        $attempt->network = $network;
        $attempt->pay_address = $payAddress;
        $attempt->metadata = array_merge($attempt->metadata ?? [], [
            'crypto' => [
                'pay_currency' => $payCurrency,
                'pay_amount' => (string) $payAmount,
                'pay_address' => $payAddress,
                'network' => $network,
                'expiration_estimate_date' => $expiresAt,
            ],
        ]);
        $attempt->save();

        $displayAmount = $this->displayPayAmount($payAmount);

        return [
            'status' => 'awaiting_transfer',
            'invoice_id' => $gatewayRef,
            'gateway_reference' => $gatewayRef,
            'pay_address' => $payAddress,
            'pay_amount' => $displayAmount,
            'exact_pay_amount' => (string) $payAmount,
            'pay_currency' => $payCurrency,
            'network' => $network,
            'payin_extra_id' => null,
            'purchase_id' => null,
            'qr_payload' => "{$network}:{$payAddress}?amount={$displayAmount}",
            'expires_at' => $expiresAt,
        ];
    }

    private function mockRate(string $payCurrency): float
    {
        return match ($payCurrency) {
            'btc' => 0.000015,
            'eth' => 0.0003,
            'ltc' => 0.012,
            'sol' => 0.006,
            'trx' => 7.5,
            'bnbbsc' => 0.0016,
            'matic' => 1.25,
            default => 1.0,
        };
    }

    private function resolveMockAddress(string $currency): string
    {
        return match (strtolower($currency)) {
            'btc' => '1A1zP1eP5QGefi2DMPTfTL5SLmv7DivfNa',
            'eth', 'usdc', 'usdcbase', 'usdcbsc', 'usdtbsc' => '0x742d35Cc6634C0532925a3b844Bc454e4438f44e',
            'usdttrc20', 'trx' => 'TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t',
            'usdcsol', 'usdtsol', 'sol' => '9WzDXwBbmkg8ZTbNMqUxvQRAyrZzDsGYdLVL9zYtAWWM',
            'ltc' => 'LVKw7y21M3tA9JtFh69N2y3Y6L5w6M3Kfd',
            default => '1A1zP1eP5QGefi2DMPTfTL5SLmv7DivfNa',
        };
    }

    /**
     * Safely parse a timestamp returned by the NowPayments API.
     *
     * NowPayments returns ISO-8601 strings in UTC (e.g. "2026-08-20T02:51:18.671Z").
     * A naive Carbon::parse() in an app whose timezone is not UTC will strip the
     * trailing "Z" and interpret the value as local time, silently shifting it by
     * the UTC offset. For Africa/Douala (UTC+1) this meant every session was born
     * ~60 minutes expired.
     *
     * This helper always interprets the incoming string as UTC first, then converts
     * to the application timezone so that Eloquent's date serialisation and all
     * downstream comparisons (e.g. isPast(), ExpireStalePaymentSessionsJob) work
     * against a consistent clock.
     */
    private function parseGatewayTimestamp(?string $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        return Carbon::parse($value, 'UTC')->setTimezone(config('app.timezone'));
    }
}
