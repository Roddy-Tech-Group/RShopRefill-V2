<?php

namespace App\Domain\Payment\Providers;

use App\Domain\Payment\Enums\PaymentStatus;
use App\Domain\Payment\Interfaces\PaymentProviderInterface;
use App\Domain\Shared\Enums\Currency;
use App\Domain\Shared\Enums\TransactionCategory;
use App\Domain\Transaction\Exceptions\DuplicateTransactionException;
use App\Domain\Wallet\Exceptions\MissingTransactionPinException;
use App\Domain\Wallet\Exceptions\WalletOnHoldException;
use App\Domain\Wallet\Services\TransactionPinService;
use App\Domain\Wallet\Services\WalletService;
use App\Models\PaymentAttempt;
use Illuminate\Support\Facades\DB;

class WalletPaymentProvider implements PaymentProviderInterface
{
    public function __construct(
        private readonly WalletService $walletService
    ) {}

    public function initializePayment(PaymentAttempt $attempt): array
    {
        $user = $attempt->user;
        $currencyEnum = Currency::tryFrom(strtoupper($attempt->currency));

        if (! $currencyEnum) {
            throw new \Exception("Unsupported wallet currency: {$attempt->currency}");
        }

        $wallet = $this->walletService->getOrCreateWallet($user, $currencyEnum);

        if (! $wallet->is_active) {
            throw new WalletOnHoldException;
        }

        $this->walletService->ensureSufficientBalance($wallet, $attempt->amount);

        if (! $user->hasTransactionPin()) {
            throw new MissingTransactionPinException(
                'You must set up a Wallet Transaction PIN in your profile settings before you can pay with your wallet.'
            );
        }

        $attempt->payment_status = PaymentStatus::Pending;
        $attempt->gateway_reference = 'WL-AUTH-REQUIRED-'.uniqid();
        $attempt->save();

        return [
            'payment_url' => null,
            'gateway_reference' => $attempt->gateway_reference,
            'status' => 'awaiting_customer_action',
        ];
    }

    /**
     * Authorize a delayed wallet payment using an authorization token.
     */
    public function authorizeTransaction(PaymentAttempt $attempt, string $authToken): void
    {
        $user = $attempt->user;
        $pinService = app(TransactionPinService::class);

        // Claim BEFORE any funds move: the claim is atomic (first caller
        // wins), so a double-clicked Pay can never double-lock the wallet off
        // one PIN entry. If anything below fails after the claim, the token
        // is burned and the customer re-enters their PIN, which is the safe
        // direction to fail.
        if (! $pinService->claimAuthToken($user, $authToken)) {
            throw new \Exception('Invalid or expired authorization token.');
        }

        $currencyEnum = Currency::from(strtoupper($attempt->currency));
        $wallet = $this->walletService->getOrCreateWallet($user, $currencyEnum);

        DB::transaction(function () use ($wallet, $attempt) {
            // Lock the funds now that we have authorization
            $this->walletService->lockFunds($wallet, $attempt->amount);

            $attempt->payment_status = PaymentStatus::Reserved;
            $attempt->gateway_reference = 'WL-LOCK-'.uniqid();
            $attempt->save();
        });
    }

    public function verifyPayment(PaymentAttempt $attempt): bool
    {
        // For wallet, if the status is reserved or paid, it's verified.
        return $attempt->payment_status === PaymentStatus::Paid
            || $attempt->payment_status === PaymentStatus::Reserved;
    }

    /**
     * Finalize the debit: deduct the reserved funds and record the transaction.
     */
    public function finalizeDebit(PaymentAttempt $attempt): void
    {
        $user = $attempt->user;
        $currencyEnum = Currency::from(strtoupper($attempt->currency));
        $wallet = $this->walletService->getOrCreateWallet($user, $currencyEnum);

        DB::transaction(function () use ($wallet, $attempt) {
            // Unlock reserved funds first
            $this->walletService->unlockFunds($wallet, $attempt->amount);

            // Complete actual debit
            $this->walletService->debit(
                wallet: $wallet,
                amount: $attempt->amount,
                category: TransactionCategory::Purchase,
                description: "Debit for Order {$attempt->order->order_number}",
                reference: $attempt->order->order_number,
                idempotencyKey: "debit-{$attempt->id}"
            );

            $attempt->payment_status = PaymentStatus::Paid;
            $attempt->confirmed_at = now();
            $attempt->save();
        });
    }

    public function releaseFunds(PaymentAttempt $attempt): void
    {
        if ($attempt->payment_status !== PaymentStatus::Reserved) {
            return;
        }

        $user = $attempt->user;
        $currencyEnum = Currency::from(strtoupper($attempt->currency));
        $wallet = $this->walletService->getOrCreateWallet($user, $currencyEnum);

        DB::transaction(function () use ($wallet, $attempt) {
            $this->walletService->unlockFunds($wallet, $attempt->amount);

            $attempt->payment_status = PaymentStatus::Failed;
            $attempt->failed_at = now();
            $attempt->save();
        });
    }

    /**
     * Release a specific amount of previously locked/reserved funds (partial failure).
     */
    public function releasePartialFunds(PaymentAttempt $attempt, float $amount): void
    {
        if ($attempt->payment_status !== PaymentStatus::Reserved) {
            return;
        }

        $user = $attempt->user;
        $currencyEnum = Currency::from(strtoupper($attempt->currency));
        $wallet = $this->walletService->getOrCreateWallet($user, $currencyEnum);

        DB::transaction(function () use ($wallet, $attempt, $amount) {
            $this->walletService->unlockFunds($wallet, $amount);

            $attempt->amount -= $amount;
            $attempt->save();
        });
    }

    public function refundPayment(PaymentAttempt $attempt, float $amount, ?string $reference = null): bool
    {
        $user = $attempt->user;
        $currencyEnum = Currency::from(strtoupper($attempt->currency));
        $wallet = $this->walletService->getOrCreateWallet($user, $currencyEnum);

        // Reference-scoped key: one attempt can legitimately carry several
        // refunds (one per failed item, a final remainder, an admin partial).
        // The old per-attempt key made the SECOND failed item's refund throw
        // DuplicateTransaction out of the caller's transaction, so a customer
        // with two undelivered items was refunded for one.
        $idempotencyKey = $reference !== null
            ? "refund-{$attempt->id}-{$reference}"
            : "refund-{$attempt->id}";

        // The ledger reference carries the same scope: wallet_transactions
        // .reference is UNIQUE, so two refunds on one order need two
        // references just as they need two idempotency keys.
        $ledgerReference = $reference !== null
            ? "REF-{$attempt->order->order_number}-{$reference}"
            : "REF-{$attempt->order->order_number}";

        try {
            DB::transaction(function () use ($wallet, $attempt, $amount, $idempotencyKey, $ledgerReference) {
                $this->walletService->credit(
                    wallet: $wallet,
                    amount: $amount,
                    category: TransactionCategory::Refund,
                    description: "Refund for Order {$attempt->order->order_number}",
                    reference: $ledgerReference,
                    idempotencyKey: $idempotencyKey
                );

                $attempt->payment_status = PaymentStatus::Refunded;
                $attempt->save();
            });
        } catch (DuplicateTransactionException) {
            // This exact refund already landed (a retry replaying the same
            // key). The money moved once; report success, move no more.
            return true;
        }

        return true;
    }

    public function normalizeWebhook(array $payload): array
    {
        return $payload; // Wallet has no webhooks
    }
}
