<?php

namespace App\Domain\Payment\Providers;

use App\Domain\Payment\Enums\PaymentStatus;
use App\Domain\Payment\Interfaces\PaymentProviderInterface;
use App\Domain\Shared\Enums\Currency;
use App\Domain\Shared\Enums\TransactionCategory;
use App\Domain\Transaction\Exceptions\DuplicateTransactionException;
use App\Domain\Wallet\Exceptions\InsufficientBalanceException;
use App\Domain\Wallet\Exceptions\MissingTransactionPinException;
use App\Domain\Wallet\Exceptions\WalletOnHoldException;
use App\Domain\Wallet\Services\CurrencyRateService;
use App\Domain\Wallet\Services\TransactionPinService;
use App\Domain\Wallet\Services\WalletService;
use App\Models\PaymentAttempt;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Support\Facades\DB;

/**
 * Pays an order from the customer's wallet balance.
 *
 * The order and its payment attempt are in the cart's currency. The wallet
 * that pays need not be: a customer who keeps money in XAF and shops in USD
 * pays from the XAF wallet, the way the eSIM top-up already lets them. At
 * initialisation the provider picks the wallet (the cart-currency wallet if
 * it covers the order, then USD, then any other wallet that covers it, or
 * the one the customer asked for) and records the choice on the attempt
 * together with the exchange rate at that moment:
 *
 *   metadata.wallet = {currency, rate, amount, requested_currency?,
 *                      refunded, refunded_wallet}
 *
 * Every later movement on the attempt (hold, debit, release, refund) goes to
 * that wallet at that frozen rate, so what goes back is exactly what came
 * out whatever the rate does in between. Partial movements telescope
 * (each is the difference between two rounded running totals) so a hold
 * released in pieces, or a charge refunded in pieces, adds up to the exact
 * amount charged and never strands a unit in the hold.
 *
 * An attempt with no recorded choice (placed before this shipped) uses the
 * wallet in its own currency at rate one, exactly as before.
 */
class WalletPaymentProvider implements PaymentProviderInterface
{
    public function __construct(
        private readonly WalletService $walletService,
        private readonly CurrencyRateService $currencyRateService,
    ) {}

    public function initializePayment(PaymentAttempt $attempt): array
    {
        $user = $attempt->user;

        // The order's currency need not be one a wallet is held in (XOF, KES,
        // UGX, RWF...): the order is paid from a wallet the customer does hold,
        // at the rate frozen here. Refusing the order currency itself turned
        // the wallet tile into a dead end in every such currency.
        $this->chooseWallet($attempt);

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

        $wallet = $this->payingWallet($attempt);

        DB::transaction(function () use ($wallet, $attempt) {
            // Lock the funds now that we have authorization
            $this->walletService->lockFunds($wallet, $this->walletAmount($attempt));

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
        $wallet = $this->payingWallet($attempt);
        $amount = $this->walletAmount($attempt);

        DB::transaction(function () use ($wallet, $attempt, $amount) {
            // Unlock reserved funds first
            $this->walletService->unlockFunds($wallet, $amount);

            // Complete actual debit
            $this->walletService->debit(
                wallet: $wallet,
                amount: $amount,
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

        $wallet = $this->payingWallet($attempt);
        $amount = $this->walletAmount($attempt);

        DB::transaction(function () use ($wallet, $attempt, $amount) {
            $this->walletService->unlockFunds($wallet, $amount);

            $attempt->payment_status = PaymentStatus::Failed;
            $attempt->failed_at = now();
            $attempt->save();
        });
    }

    /**
     * Release a specific amount of previously locked/reserved funds (partial failure).
     *
     * The amount is in the attempt's currency. What is unlocked is the
     * difference between the hold before and the hold after, each rounded
     * from the running attempt amount, so several partial releases followed
     * by the final debit always add up to exactly what was held.
     */
    public function releasePartialFunds(PaymentAttempt $attempt, float $amount): void
    {
        if ($attempt->payment_status !== PaymentStatus::Reserved) {
            return;
        }

        $wallet = $this->payingWallet($attempt);

        DB::transaction(function () use ($wallet, $attempt, $amount) {
            $heldBefore = $this->walletAmount($attempt);

            $attempt->amount -= $amount;
            $this->restampWalletAmount($attempt);

            $release = round($heldBefore - $this->walletAmount($attempt), 2);

            if ($release > 0) {
                $this->walletService->unlockFunds($wallet, $release);
            }

            $attempt->save();
        });
    }

    public function refundPayment(PaymentAttempt $attempt, float $amount, ?string $reference = null): bool
    {
        $wallet = $this->payingWallet($attempt);

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
                // The running refund totals live on the attempt row, so take
                // the row lock before reading them: two refunds landing at
                // once must each see the other's total.
                $locked = PaymentAttempt::query()->whereKey($attempt->getKey())->lockForUpdate()->firstOrFail();

                [$credit, $tally] = $this->refundCredit($locked, $amount);

                // A rounding remainder can leave nothing to credit on the last
                // slice of a refund; the ledger refuses a zero credit.
                if ($credit > 0) {
                    $this->walletService->credit(
                        wallet: $wallet,
                        amount: $credit,
                        category: TransactionCategory::Refund,
                        description: "Refund for Order {$attempt->order->order_number}",
                        reference: $ledgerReference,
                        idempotencyKey: $idempotencyKey
                    );
                }

                if ($tally !== null) {
                    $metadata = $locked->metadata ?? [];
                    $metadata['wallet'] = $tally;
                    $locked->metadata = $metadata;
                }

                $locked->payment_status = PaymentStatus::Refunded;
                $locked->save();

                $attempt->payment_status = PaymentStatus::Refunded;
                $attempt->metadata = $locked->metadata;
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

    // ------------------------------------------------ the paying wallet

    /**
     * The wallets a customer can pay an order from, for the checkout page:
     * every active wallet except Rcoin (a rewards balance, applied as a
     * discount, never a paying wallet), each with its balance and how many
     * of its units one USD buys, so the page can tell which wallets cover
     * the cart and what each would be charged. Wallets whose currency has no
     * usable rate right now are left out: no rate, no offer.
     *
     * @return list<array{currency: string, balance: float, per_usd: float}>
     */
    public function optionsFor(User $user): array
    {
        $options = [];

        foreach ($user->wallets()->where('is_active', true)->get() as $wallet) {
            if ($wallet->currency === Currency::RCOIN) {
                continue;
            }

            try {
                $perUsd = $this->currencyRateService->resolveRate('USD', $wallet->currency->value);
            } catch (\Throwable) {
                continue;
            }

            if ($perUsd <= 0) {
                continue;
            }

            $options[] = [
                'currency' => $wallet->currency->value,
                'balance' => round((float) $wallet->availableBalance(), 2),
                'per_usd' => $perUsd,
            ];
        }

        return $options;
    }

    /**
     * Pick the wallet that pays this attempt and record the choice on it.
     *
     * Order of preference: the wallet the customer asked for (refused, never
     * swapped, when it does not cover the order); otherwise the wallet in the
     * order's own currency, then USD, then any other wallet, the first that
     * covers the order winning. Rcoin never pays. A held wallet that would
     * have covered it is reported as held, not as short.
     *
     * @throws InsufficientBalanceException
     * @throws WalletOnHoldException
     */
    private function chooseWallet(PaymentAttempt $attempt): void
    {
        $user = $attempt->user;
        $attemptCurrency = strtoupper($attempt->currency);
        $requested = strtoupper((string) data_get($attempt->metadata, 'wallet.requested_currency', ''));

        $wallets = $user->wallets()->get()
            ->reject(fn (Wallet $wallet) => $wallet->currency === Currency::RCOIN)
            ->keyBy(fn (Wallet $wallet) => $wallet->currency->value);

        if ($requested !== '') {
            $requestedEnum = Currency::tryFrom($requested);

            if ($requestedEnum === null || $requestedEnum === Currency::RCOIN) {
                throw InsufficientBalanceException::forOrder();
            }

            // The customer named a wallet they have not opened (the page only
            // offers wallets that exist, but the API takes any code): a wallet
            // they do not hold covers nothing, and refusing must not open one.
            if (! isset($wallets[$requested])) {
                throw InsufficientBalanceException::forChosenWallet($requested, $this->neededIn($attempt, $requested));
            }

            $candidates = collect([$wallets[$requested]]);
        } else {
            $candidates = $wallets
                ->sortBy(fn (Wallet $wallet) => match ($wallet->currency->value) {
                    $attemptCurrency => '0',
                    'USD' => '1',
                    default => '2'.$wallet->currency->value,
                })
                ->values();
        }

        $held = null;

        foreach ($candidates as $wallet) {
            try {
                $rate = $this->currencyRateService->resolveRate($attemptCurrency, $wallet->currency->value);
            } catch (\Throwable) {
                // No usable exchange rate for this currency right now - skip it.
                continue;
            }

            $needed = round((float) $attempt->amount * $rate, 2);

            if ($rate <= 0 || $needed <= 0 || (float) $wallet->availableBalance() < $needed) {
                if ($requested !== '') {
                    throw InsufficientBalanceException::forChosenWallet(
                        $wallet->currency->value,
                        number_format($needed, 2).' '.$wallet->currency->value,
                    );
                }

                continue;
            }

            if (! $wallet->is_active) {
                $held ??= $wallet;

                continue;
            }

            $metadata = $attempt->metadata ?? [];
            $metadata['wallet'] = array_filter([
                'currency' => $wallet->currency->value,
                'rate' => $rate,
                'amount' => $needed,
                'requested_currency' => $requested !== '' ? $requested : null,
                'refunded' => 0.0,
                'refunded_wallet' => 0.0,
            ], fn ($value) => $value !== null);
            $attempt->metadata = $metadata;
            $attempt->save();

            return;
        }

        if ($held !== null) {
            throw new WalletOnHoldException;
        }

        throw InsufficientBalanceException::forOrder();
    }

    /**
     * The order's amount expressed in another currency, for a refusal
     * message. Falls back to the order's own figure when no rate is usable.
     */
    private function neededIn(PaymentAttempt $attempt, string $currency): string
    {
        try {
            $rate = $this->currencyRateService->resolveRate(strtoupper($attempt->currency), $currency);

            return number_format(round((float) $attempt->amount * $rate, 2), 2).' '.$currency;
        } catch (\Throwable) {
            return number_format((float) $attempt->amount, 2).' '.strtoupper($attempt->currency);
        }
    }

    /**
     * The wallet this attempt is paid from: the recorded choice, or, for an
     * attempt placed before choices were recorded, the wallet in the
     * attempt's own currency.
     */
    private function payingWallet(PaymentAttempt $attempt): Wallet
    {
        $code = strtoupper((string) (data_get($attempt->metadata, 'wallet.currency') ?: $attempt->currency));

        return $this->walletService->getOrCreateWallet($attempt->user, Currency::from($code));
    }

    /**
     * The exchange rate frozen on the attempt: wallet units per unit of the
     * attempt's currency. One when the two are the same or nothing was
     * recorded.
     */
    private function walletRate(PaymentAttempt $attempt): float
    {
        $rate = (float) data_get($attempt->metadata, 'wallet.rate', 1.0);

        return $rate > 0 ? $rate : 1.0;
    }

    /**
     * The attempt's whole amount in the paying wallet's currency.
     */
    private function walletAmount(PaymentAttempt $attempt): float
    {
        $recorded = data_get($attempt->metadata, 'wallet.amount');

        return $recorded !== null
            ? round((float) $recorded, 2)
            : round((float) $attempt->amount * $this->walletRate($attempt), 2);
    }

    /**
     * After the attempt's amount changed (a partial release), record the new
     * wallet-currency amount alongside it.
     */
    private function restampWalletAmount(PaymentAttempt $attempt): void
    {
        if (data_get($attempt->metadata, 'wallet.currency') === null) {
            return;
        }

        $metadata = $attempt->metadata;
        $metadata['wallet']['amount'] = round((float) $attempt->amount * $this->walletRate($attempt), 2);
        $attempt->metadata = $metadata;
    }

    /**
     * How much to credit the paying wallet for a refund of $amount in the
     * attempt's currency, plus the updated running totals to store.
     *
     * Same-currency and legacy attempts credit the amount as given and keep
     * no totals, exactly as before. Cross-currency refunds credit the
     * difference between two rounded running totals, so partial refunds add
     * up to precisely the amount charged.
     *
     * @return array{0: float, 1: array<string, mixed>|null}
     */
    private function refundCredit(PaymentAttempt $attempt, float $amount): array
    {
        $wallet = data_get($attempt->metadata, 'wallet');

        if (! is_array($wallet) || (float) ($wallet['rate'] ?? 1.0) === 1.0) {
            return [$amount, null];
        }

        $rate = $this->walletRate($attempt);
        $refunded = round((float) ($wallet['refunded'] ?? 0.0) + $amount, 2);
        $refundedWallet = round((float) ($wallet['refunded_wallet'] ?? 0.0), 2);
        $credit = round(round($refunded * $rate, 2) - $refundedWallet, 2);

        $wallet['refunded'] = $refunded;
        $wallet['refunded_wallet'] = round($refundedWallet + $credit, 2);

        return [$credit, $wallet];
    }
}
