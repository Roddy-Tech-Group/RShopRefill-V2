<?php

namespace App\Domain\Wallet\Services;

use App\Domain\Auth\Exceptions\EmailVerificationRequiredException;
use App\Domain\Payment\Enums\PaymentStatus;
use App\Domain\Payment\Exceptions\GatewayDisabledException;
use App\Domain\Payment\Exceptions\PaymentCategoryIneligibleException;
use App\Domain\Payment\Services\PaymentEligibilityService;
use App\Domain\Payment\Services\PaymentGatewayFactory;
use App\Domain\Payment\Services\PaymentSessionService;
use App\Domain\Payment\Support\GatewayFailure;
use App\Domain\PaymentInsights\Enums\ProductLine;
use App\Domain\Risk\DTOs\RiskContext;
use App\Domain\Risk\Services\RiskGate;
use App\Domain\Shared\Enums\ComplianceCategory;
use App\Domain\Shared\Enums\Currency;
use App\Domain\Shared\Enums\FundingStatus;
use App\Domain\Shared\Enums\TransactionCategory;
use App\Domain\Transaction\Services\TransactionService;
use App\Domain\Wallet\Events\FundingCompleted;
use App\Domain\Wallet\Events\FundingFailed;
use App\Domain\Wallet\Exceptions\WalletFundingException;
use App\Models\PaymentAttempt;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletFunding;
use App\Support\FeatureFlag;
use App\Support\VerificationResendThrottle;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Orchestrates the wallet funding lifecycle (initiation, verification, and completion).
 */
class WalletFundingService
{
    public function __construct(
        private readonly TransactionService $transactionService,
        private readonly PaymentGatewayFactory $paymentGatewayFactory,
        private readonly WalletService $walletService,
        private readonly CurrencyRateService $currencyRateService,
        private readonly FundingVerificationService $verificationService,
        private readonly PaymentSessionService $paymentSessionService,
        private readonly PaymentEligibilityService $paymentEligibilityService,
        private readonly RiskGate $riskGate,
    ) {}

    /**
     * Initialize a funding request and generate a hosted payment link.
     */
    public function initializeFunding(User $user, Wallet $wallet, float $amount, Currency $currency, ?string $displayCurrency = null): array
    {
        // The wallet-funding kill switch, enforced at the ONE seam every
        // caller (API route, dashboard Livewire modal, any future surface)
        // funnels through. The flag previously only hid the Fund Wallet
        // button, so anyone replaying the API call could still open new
        // fundings with the switch off. Existing balance still spends and
        // in-flight fundings still land; only NEW money is refused.
        if (FeatureFlag::off('wallet_funding')) {
            throw new WalletFundingException('Wallet top-ups are temporarily unavailable. Please try again shortly.');
        }

        // Email verification is mandatory for money-in, enforced HERE so every
        // entry point (API route, dashboard Livewire component, any future
        // caller) shares one gate. The API route's verified-email middleware
        // still answers first there; this catches the paths middleware misses.
        // attemptSend() applies the shared escalating cooldown + flat budget, so
        // blocked attempts cannot become an email-spam vector and the frontend's
        // Resend countdown starts from the true schedule position.
        if (! $user->is_guest && ! $user->hasVerifiedEmail()) {
            $result = app(VerificationResendThrottle::class)->attemptSend($user);

            throw new EmailVerificationRequiredException(
                $user->email,
                $result['sent'],
                max($result['retry_after'], 30),
            );
        }
        // Gateway kill switch first: a funding charge needs a live fiat
        // gateway before any write, exactly like checkout. The router hands
        // back Flutterwave in normal operation and a backup (Paystack, Kora)
        // during an outage; with every fiat gateway dark, funding refuses
        // pre-write. In-flight fundings and their webhooks are never gated.
        // Category-aware gateway selection + eligibility gate for stored value.
        // Picks the first live fiat gateway APPROVED to fund a wallet, so if the
        // default is not the approved one, funding still routes to the backup
        // that is, instead of being refused. Throws a clean refusal (a plain
        // outage vs a wallet-funding cell no live gateway approves) when none
        // qualifies. Wallet funding is the most sensitive cell: the funding
        // gateway cannot see what the balance ultimately buys. Shadow mode picks
        // the plain default and does not block, so funding is unchanged until
        // enforcement is on. Crypto funding is re-stamped later at the pay step.
        try {
            $gateway = $this->paymentEligibilityService->requireFiatGatewayFor(
                [ComplianceCategory::Wallet],
                $currency->value,
            );
        } catch (GatewayDisabledException|PaymentCategoryIneligibleException $e) {
            // No usable fiat gateway - a full fiat outage, or (under
            // enforcement) fiat gateways alive but none approved for the
            // wallet cell. Either way crypto funding is definitionally
            // independent of the fiat rails, so it must not be held hostage by
            // them. If NOWPayments is alive (and, under enforcement, approved
            // to fund wallets), proceed with it as the provisional gateway:
            // its initializePayment() is a stateless scaffold, the fiat tiles
            // grey out client-side, and the pay step re-stamps crypto anyway.
            // With crypto also dark or unapproved, the original refusal stands.
            $cryptoCanFund = FeatureFlag::gatewayEnabled('nowpayments')
                && (! $this->paymentEligibilityService->enforcementEnabled()
                    || $this->paymentEligibilityService->allowsAll([ComplianceCategory::Wallet], 'nowpayments'));

            if (! $cryptoCanFund) {
                throw $e;
            }

            $gateway = 'nowpayments';
        }

        // Rate limit before the first write, matching CheckoutService. This
        // path was already safe (everything below runs inside one transaction)
        // but the guard belongs at the entry point so the rule holds uniformly:
        // no backoff check ever runs after a flow has started writing.
        $this->paymentSessionService->assertNotRateLimited($user, ProductLine::WalletFunding->value);

        if ($wallet->currency->value !== $currency->value) {
            throw new \InvalidArgumentException('Funding currency must match wallet currency.');
        }

        if ($amount < $currency->minimumFundingAmount()) {
            throw new \InvalidArgumentException("Minimum funding amount is {$currency->minimumFundingAmount()} {$currency->value}.");
        }

        // Hard upper cap — stops gateway-sandbox accidents and fat-finger UI
        // bugs (e.g. typing 100000 instead of 1000) before a payment session is
        // even created. Sized per-currency in Currency::maximumFundingAmount().
        if ($amount > $currency->maximumFundingAmount()) {
            throw new \InvalidArgumentException('Maximum funding amount per transaction is '.number_format($currency->maximumFundingAmount(), 2)." {$currency->value}.");
        }

        $displayCurrency = strtoupper(trim($displayCurrency ?: $currency->value));

        // 1. Resolve rates snapshot
        $rate = $this->currencyRateService->resolveRate($currency->value, $displayCurrency);
        $requestedAmount = round($amount * $rate, 4);

        // Convert back to USD for audit settled baseline
        $usdRate = $this->currencyRateService->resolveRate($currency->value, 'USD');
        $settledAmountUsd = round($amount * $usdRate, 4);

        // Unified risk + KYC gate for funding, same discipline as checkout. Runs
        // the risk engine (records an assessment); with risk enforcement on it
        // acts - identity step-up, review hold, or refusal - and KYC's value-based
        // step-up applies too. Inert with both flags off. The funding value in USD
        // is the exposure amount.
        $this->riskGate->guard(RiskContext::capture(
            user: $user,
            context: 'funding',
            amountUsd: (float) $settledAmountUsd,
            currency: $displayCurrency,
            subjectType: 'wallet',
            subjectId: (string) $user->getKey(),
        ));

        $reference = $this->transactionService->generateReference('FUND', $currency);
        $idempotencyKey = 'fund_init_'.Str::random(16);

        return DB::transaction(function () use ($user, $wallet, $amount, $currency, $displayCurrency, $rate, $requestedAmount, $settledAmountUsd, $reference, $idempotencyKey, $gateway) {

            // 2. Create immutable funding attempt
            $funding = WalletFunding::create([
                'user_id' => $user->id,
                'wallet_id' => $wallet->id,
                'reference' => $reference,
                'currency' => $currency->value,
                'display_currency' => $displayCurrency,
                'amount' => $amount,
                'requested_amount' => $requestedAmount,
                'settled_amount_usd' => $settledAmountUsd,
                'exchange_rate_snapshot' => $rate,
                'gateway' => $gateway,
                'status' => FundingStatus::Pending,
                'idempotency_key' => $idempotencyKey,
            ]);

            // 3. Create polymorphic PaymentAttempt
            $attempt = PaymentAttempt::create([
                'user_id' => $user->id,
                'payable_type' => WalletFunding::class,
                'payable_id' => $funding->id,
                'gateway' => $gateway,
                'idempotency_key' => $reference, // Use Reference for unique mapping on provider calls
                'currency' => $displayCurrency,
                'amount' => $requestedAmount,
                'exchange_rate_snapshot' => $rate,
                'payment_status' => PaymentStatus::Pending,
            ]);

            // 4. Resolve provider and initialize payment
            $provider = $this->paymentGatewayFactory->getProvider($gateway);
            $initData = $provider->initializePayment($attempt);

            // 5. Create PaymentSession
            $paymentSession = $this->paymentSessionService->createForWalletFunding($funding, $attempt, $initData);

            return [
                'funding' => $funding,
                'payment_session' => $paymentSession,
            ];
        });
    }

    /**
     * Process a successful webhook event and credit the user's wallet.
     * Uses pessimistic locking to ensure idempotency and prevent double-credits.
     *
     * @throws WalletFundingException
     */
    public function processSuccessfulFunding(string $txRef, string $flwTransactionId, array $rawPayload = []): void
    {
        // 1. Locate and lock funding outside transaction or verify first
        $funding = WalletFunding::where('reference', $txRef)->first();

        if (! $funding) {
            Log::error('Wallet funding record not found.', ['tx_ref' => $txRef]);
            throw new \RuntimeException('Funding record not found.');
        }

        if ($funding->status === FundingStatus::Completed) {
            Log::info('Wallet funding already processed.', ['tx_ref' => $txRef]);

            return;
        }

        if ($funding->status === FundingStatus::Failed || $funding->status === FundingStatus::Cancelled) {
            throw WalletFundingException::alreadyProcessed($txRef);
        }

        // 2. Server-to-server verification with gateway OUTSIDE transaction so failures are stored
        $verified = $this->verificationService->verify($funding);

        if (! $verified) {
            $reason = 'Gateway verification failed or payload tampered.';
            $this->failFunding($funding, $reason, $rawPayload);
            throw WalletFundingException::verificationFailed($txRef, $reason);
        }

        // 3. Now execute database transaction to credit the wallet under pessimistic lock
        DB::transaction(function () use ($funding, $flwTransactionId, $rawPayload) {
            // A REAL row lock. The previous `$funding->lockForUpdate()` built a
            // query builder and never executed it, locking nothing, so two
            // concurrent webhooks both sailed past the stale Pending check
            // above. The ledger's unique idempotency key still made a double
            // credit impossible, but the loser crashed out on a constraint
            // violation instead of exiting cleanly. Lock, re-read, re-check.
            $funding = WalletFunding::query()->lockForUpdate()->findOrFail($funding->id);

            if ($funding->status === FundingStatus::Completed) {
                Log::info('Wallet funding already completed; concurrent webhook stood down.', ['tx_ref' => $funding->reference]);

                return;
            }

            // Retrieve associated polymorphic attempt and mark confirmed
            $attempt = PaymentAttempt::where('payable_type', WalletFunding::class)
                ->where('payable_id', $funding->id)
                ->first();

            if ($attempt) {
                $attempt->update([
                    'payment_status' => PaymentStatus::Paid,
                    'confirmed_at' => now(),
                    'gateway_reference' => $flwTransactionId,
                    'webhook_payload' => $rawPayload,
                ]);

                // Sync payment session status
                $attempt->load('paymentSession');
                if ($attempt->paymentSession) {
                    $this->paymentSessionService->confirmSession($attempt->paymentSession, [
                        'transaction_id' => $flwTransactionId,
                        'payload' => $rawPayload,
                    ]);
                }
            }

            // Credit the wallet safely
            $wallet = $funding->wallet;

            $this->walletService->credit(
                wallet: $wallet,
                amount: (float) $funding->amount,
                category: TransactionCategory::Funding,
                description: 'Wallet top-up',
                reference: $funding->reference,
                idempotencyKey: "fund-{$funding->reference}",
                sourceType: 'wallet_fundings',
                sourceId: $funding->id,
                metadata: [
                    'gateway_transaction_id' => $flwTransactionId,
                    'display_currency' => $funding->display_currency,
                    'exchange_rate' => $funding->exchange_rate_snapshot,
                    'requested_amount' => $funding->requested_amount,
                ]
            );

            // Mark funding completed
            $funding->update([
                'status' => FundingStatus::Completed,
                'gateway_reference' => $flwTransactionId,
                'provider_payload_snapshot' => $rawPayload,
                'completed_at' => now(),
                'processed_at' => now(),
            ]);

            DB::afterCommit(function () use ($funding) {
                event(new FundingCompleted($funding));
            });
        });

        Log::info('Wallet funding successfully processed.', ['tx_ref' => $txRef, 'amount' => $funding->amount]);
    }

    /**
     * Mark a funding record as failed.
     */
    public function failFunding(WalletFunding $funding, string $reason, array $payload = []): void
    {
        // Callers on the webhook path hand us the raw gateway payload;
        // derive the structured failure block from it when none was supplied,
        // so the outcome classifier sees the gateway's verdict (e.g. the
        // issuer's processor_response) instead of our summary sentence. The
        // mapper follows whichever gateway this funding was stamped with.
        if (! isset($payload['failure']) && $payload !== []) {
            $payload['failure'] = match ($funding->gateway) {
                'paystack' => GatewayFailure::fromPaystack($payload),
                'korapay' => GatewayFailure::fromKorapay($payload),
                default => GatewayFailure::fromFlutterwave($payload),
            };
        }

        DB::transaction(function () use ($funding, $reason, $payload) {
            $funding->update([
                'status' => FundingStatus::Failed,
                'failed_reason' => $reason,
                'provider_payload_snapshot' => $payload,
                'processed_at' => now(),
            ]);

            // Fail polymorphic payment attempt
            $attempt = PaymentAttempt::where('payable_type', WalletFunding::class)
                ->where('payable_id', $funding->id)
                ->first();

            if ($attempt) {
                // App\Enums\PaymentStatus does not exist — this referenced a class that
                // was never created, so failFunding() threw a fatal Error the moment a
                // funding had an attempt attached, rolling the whole transaction back and
                // leaving the record stuck on Pending instead of Failed.
                $attempt->update([
                    'payment_status' => PaymentStatus::Failed,
                    'failed_at' => now(),
                ]);

                // Sync payment session status
                $attempt->load('paymentSession');
                if ($attempt->paymentSession) {
                    $this->paymentSessionService->failSession($attempt->paymentSession, $reason, $payload);
                }
            }

            DB::afterCommit(function () use ($funding, $reason) {
                event(new FundingFailed($funding, $reason));
            });
        });
    }
}
