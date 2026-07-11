<?php

namespace App\Domain\Wallet\Services;

use App\Domain\Ledger\Models\FinancialLedgerEvent;
use App\Domain\Shared\Enums\Currency;
use App\Domain\Shared\Enums\TransactionCategory;
use App\Domain\Shared\Enums\WalletTransactionType;
use App\Domain\Transaction\Services\TransactionService;
use App\Domain\Wallet\Events\WalletCredited;
use App\Domain\Wallet\Events\WalletDebited;
use App\Domain\Wallet\Exceptions\CurrencyMismatchException;
use App\Domain\Wallet\Exceptions\InsufficientBalanceException;
use App\Domain\Wallet\Exceptions\WalletOnHoldException;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Illuminate\Support\Facades\DB;

/**
 * Enterprise Wallet Engine.
 * Responsible for all mutations to wallet balances.
 * Strictly enforces row-locking and immutable transaction generation.
 */
class WalletService
{
    public function __construct(
        private readonly TransactionService $transactionService,
    ) {}

    /**
     * Get or create a wallet for a user in the specified currency.
     */
    public function getOrCreateWallet(User $user, Currency $currency): Wallet
    {
        return Wallet::firstOrCreate(
            ['user_id' => $user->id, 'currency' => $currency],
            ['balance' => 0, 'locked_balance' => 0, 'is_active' => true]
        );
    }

    /**
     * Get the available balance for a wallet.
     */
    public function getBalance(Wallet $wallet): float
    {
        return $wallet->availableBalance();
    }

    /**
     * Credit a wallet and record the transaction.
     *
     * @throws CurrencyMismatchException
     */
    public function credit(
        Wallet $wallet,
        float $amount,
        TransactionCategory $category,
        string $description,
        ?string $reference = null,
        ?string $idempotencyKey = null,
        ?string $sourceType = null,
        ?int $sourceId = null,
        ?array $metadata = null
    ): WalletTransaction {
        if ($amount <= 0) {
            throw new \InvalidArgumentException('Credit amount must be greater than zero.');
        }

        return DB::transaction(function () use ($wallet, $amount, $category, $description, $reference, $idempotencyKey, $sourceType, $sourceId, $metadata) {
            // Pessimistic lock the wallet row
            $lockedWallet = Wallet::where('id', $wallet->id)->lockForUpdate()->firstOrFail();

            $balanceBefore = (float) $lockedWallet->balance;
            $balanceAfter = $balanceBefore + $amount;

            // Update balance
            $lockedWallet->balance = $balanceAfter;
            $lockedWallet->last_activity_at = now();
            $lockedWallet->save();

            // Record transaction
            $tx = $this->transactionService->recordTransaction([
                'wallet_id' => $lockedWallet->id,
                'user_id' => $lockedWallet->user_id,
                'type' => WalletTransactionType::Credit,
                'currency' => $lockedWallet->currency,
                'amount' => $amount,
                'balance_before' => $balanceBefore,
                'balance_after' => $balanceAfter,
                'transaction_category' => $category,
                'description' => $description,
                'reference' => $reference,
                'idempotency_key' => $idempotencyKey,
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'metadata' => $metadata,
            ]);

            // Record Immutable Ledger Event
            $lastEvent = FinancialLedgerEvent::where('wallet_id', $lockedWallet->id)
                ->orderByDesc('id')
                ->first();

            $eventData = [
                'wallet_id' => $lockedWallet->id,
                'wallet_transaction_id' => $tx->id,
                'event_type' => 'credit',
                'amount' => $amount,
                'balance_after' => $balanceAfter,
                'currency' => $lockedWallet->currency->value,
            ];

            $eventData['hash'] = FinancialLedgerEvent::generateHash($eventData, $lastEvent?->hash);

            FinancialLedgerEvent::create($eventData);

            // Dispatch event after transaction successfully commits
            DB::afterCommit(function () use ($tx) {
                event(new WalletCredited($tx));
            });

            return $tx;
        });
    }

    /**
     * Debit a wallet and record the transaction.
     *
     * @throws CurrencyMismatchException
     * @throws InsufficientBalanceException
     */
    public function debit(
        Wallet $wallet,
        float $amount,
        TransactionCategory $category,
        string $description,
        ?string $reference = null,
        ?string $idempotencyKey = null,
        ?string $sourceType = null,
        ?int $sourceId = null,
        ?array $metadata = null
    ): WalletTransaction {
        if ($amount <= 0) {
            $debitEx = new \InvalidArgumentException('Debit amount must be greater than zero.');
            throw $debitEx;
        }

        return DB::transaction(function () use ($wallet, $amount, $category, $description, $reference, $idempotencyKey, $sourceType, $sourceId, $metadata) {
            $lockedWallet = Wallet::where('id', $wallet->id)->lockForUpdate()->firstOrFail();

            // Admin "hold funds" deactivates the wallet — block any spend from it.
            // Customer-friendly message lives on the exception itself; the payment
            // controller renders it verbatim instead of wrapping it in jargon.
            if (! $lockedWallet->is_active) {
                throw new WalletOnHoldException;
            }

            $this->ensureSufficientBalance($lockedWallet, $amount);

            $balanceBefore = (float) $lockedWallet->balance;
            $balanceAfter = $balanceBefore - $amount;

            $lockedWallet->balance = $balanceAfter;
            $lockedWallet->last_activity_at = now();
            $lockedWallet->save();

            $tx = $this->transactionService->recordTransaction([
                'wallet_id' => $lockedWallet->id,
                'user_id' => $lockedWallet->user_id,
                'type' => WalletTransactionType::Debit,
                'currency' => $lockedWallet->currency,
                'amount' => $amount,
                'balance_before' => $balanceBefore,
                'balance_after' => $balanceAfter,
                'transaction_category' => $category,
                'description' => $description,
                'reference' => $reference,
                'idempotency_key' => $idempotencyKey,
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'metadata' => $metadata,
            ]);

            // Record Immutable Ledger Event
            $lastEvent = FinancialLedgerEvent::where('wallet_id', $lockedWallet->id)
                ->orderByDesc('id')
                ->first();

            $eventData = [
                'wallet_id' => $lockedWallet->id,
                'wallet_transaction_id' => $tx->id,
                'event_type' => 'debit',
                'amount' => $amount,
                'balance_after' => $balanceAfter,
                'currency' => $lockedWallet->currency->value,
            ];

            $eventData['hash'] = FinancialLedgerEvent::generateHash($eventData, $lastEvent?->hash);

            FinancialLedgerEvent::create($eventData);

            // Dispatch event after transaction successfully commits
            DB::afterCommit(function () use ($tx) {
                event(new WalletDebited($tx));
            });

            return $tx;
        });
    }

    /**
     * Transfer funds between two wallets.
     *
     * @throws CurrencyMismatchException
     * @throws InsufficientBalanceException
     */
    public function transfer(
        Wallet $fromWallet,
        Wallet $toWallet,
        float $amount,
        string $description,
        ?string $idempotencyKey = null
    ): array {
        if ($fromWallet->currency->value !== $toWallet->currency->value) {
            throw CurrencyMismatchException::mismatch($fromWallet->currency->value, $toWallet->currency->value);
        }

        return DB::transaction(function () use ($fromWallet, $toWallet, $amount, $description, $idempotencyKey) {
            // Lock in consistent order to prevent deadlocks (lower ID first)
            $firstId = min($fromWallet->id, $toWallet->id);
            $secondId = max($fromWallet->id, $toWallet->id);

            Wallet::where('id', $firstId)->lockForUpdate()->firstOrFail();
            Wallet::where('id', $secondId)->lockForUpdate()->firstOrFail();

            $reference = $this->transactionService->generateReference('TRF', $fromWallet->currency);

            $debitTx = $this->debit(
                wallet: $fromWallet,
                amount: $amount,
                category: TransactionCategory::Transfer,
                description: "Transfer to {$toWallet->user->name}: {$description}",
                reference: $reference,
                idempotencyKey: $idempotencyKey ? "{$idempotencyKey}-debit" : null
            );

            $creditTx = $this->credit(
                wallet: $toWallet,
                amount: $amount,
                category: TransactionCategory::Transfer,
                description: "Transfer from {$fromWallet->user->name}: {$description}",
                reference: $reference,
                idempotencyKey: $idempotencyKey ? "{$idempotencyKey}-credit" : null
            );

            return ['debit' => $debitTx, 'credit' => $creditTx];
        });
    }

    /**
     * Lock funds for a pending operation (e.g. reserving funds while gateway processes).
     */
    public function lockFunds(Wallet $wallet, float $amount): void
    {
        if ($amount <= 0) {
            throw new \InvalidArgumentException('Lock amount must be greater than zero.');
        }

        DB::transaction(function () use ($wallet, $amount) {
            $lockedWallet = Wallet::where('id', $wallet->id)->lockForUpdate()->firstOrFail();

            // Held wallet: refuse the reservation up-front so the customer
            // never sees a "Reserved" state they can't progress out of.
            if (! $lockedWallet->is_active) {
                throw new WalletOnHoldException;
            }

            $this->ensureSufficientBalance($lockedWallet, $amount);

            $lockedWallet->locked_balance += $amount;
            $lockedWallet->save();

            // Record Immutable Ledger Event for locking
            $lastEvent = FinancialLedgerEvent::where('wallet_id', $lockedWallet->id)
                ->orderByDesc('id')
                ->first();

            $eventData = [
                'wallet_id' => $lockedWallet->id,
                'wallet_transaction_id' => null,
                'event_type' => 'lock',
                'amount' => $amount,
                'balance_after' => $lockedWallet->balance, // balance unchanged by lock
                'currency' => $lockedWallet->currency->value,
            ];

            $eventData['hash'] = FinancialLedgerEvent::generateHash($eventData, $lastEvent?->hash);
            FinancialLedgerEvent::create($eventData);
        });
    }

    /**
     * Unlock previously locked funds.
     */
    public function unlockFunds(Wallet $wallet, float $amount): void
    {
        if ($amount <= 0) {
            throw new \InvalidArgumentException('Unlock amount must be greater than zero.');
        }

        DB::transaction(function () use ($wallet, $amount) {
            $lockedWallet = Wallet::where('id', $wallet->id)->lockForUpdate()->firstOrFail();

            if ($lockedWallet->locked_balance < $amount) {
                throw new \RuntimeException('Cannot unlock more funds than currently locked.');
            }

            $lockedWallet->locked_balance -= $amount;
            $lockedWallet->save();

            // Record Immutable Ledger Event for unlocking
            $lastEvent = FinancialLedgerEvent::where('wallet_id', $lockedWallet->id)
                ->orderByDesc('id')
                ->first();

            $eventData = [
                'wallet_id' => $lockedWallet->id,
                'wallet_transaction_id' => null,
                'event_type' => 'unlock',
                'amount' => $amount,
                'balance_after' => $lockedWallet->balance, // balance unchanged by unlock
                'currency' => $lockedWallet->currency->value,
            ];

            $eventData['hash'] = FinancialLedgerEvent::generateHash($eventData, $lastEvent?->hash);
            FinancialLedgerEvent::create($eventData);
        });
    }

    /**
     * Throw exception if available balance is insufficient.
     *
     * @throws InsufficientBalanceException
     */
    public function ensureSufficientBalance(Wallet $wallet, float $amount): void
    {
        if ($wallet->availableBalance() < $amount) {
            throw InsufficientBalanceException::forWallet($wallet->id, $amount, $wallet->availableBalance());
        }
    }
}
