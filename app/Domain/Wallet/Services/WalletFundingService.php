<?php

namespace App\Domain\Wallet\Services;

use App\Domain\Payment\Enums\PaymentStatus;
use App\Domain\Payment\Services\PaymentGatewayFactory;
use App\Domain\Payment\Services\PaymentSessionService;
use App\Domain\Payment\Support\GatewayFailure;
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
        private readonly PaymentSessionService $paymentSessionService
    ) {}

    /**
     * Initialize a funding request and generate a hosted payment link.
     */
    public function initializeFunding(User $user, Wallet $wallet, float $amount, Currency $currency, ?string $displayCurrency = null): array
    {
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

        $reference = $this->transactionService->generateReference('FUND', $currency);
        $idempotencyKey = 'fund_init_'.Str::random(16);

        return DB::transaction(function () use ($user, $wallet, $amount, $currency, $displayCurrency, $rate, $requestedAmount, $settledAmountUsd, $reference, $idempotencyKey) {

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
                'gateway' => 'flutterwave',
                'status' => FundingStatus::Pending,
                'idempotency_key' => $idempotencyKey,
            ]);

            // 3. Create polymorphic PaymentAttempt
            $attempt = PaymentAttempt::create([
                'user_id' => $user->id,
                'payable_type' => WalletFunding::class,
                'payable_id' => $funding->id,
                'gateway' => 'flutterwave',
                'idempotency_key' => $reference, // Use Reference for unique mapping on provider calls
                'currency' => $displayCurrency,
                'amount' => $requestedAmount,
                'exchange_rate_snapshot' => $rate,
                'payment_status' => PaymentStatus::Pending,
            ]);

            // 4. Resolve provider and initialize payment
            $provider = $this->paymentGatewayFactory->getProvider('flutterwave');
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
            // Re-lock funding row
            $funding->lockForUpdate();

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
        // Callers on the webhook path hand us the raw Flutterwave payload;
        // derive the structured failure block from it when none was supplied,
        // so the outcome classifier sees the gateway's verdict (e.g. the
        // issuer's processor_response) instead of our summary sentence.
        if (! isset($payload['failure']) && $payload !== []) {
            $payload['failure'] = GatewayFailure::fromFlutterwave($payload);
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
