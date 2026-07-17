<?php

namespace App\Domain\Wallet\Services;

use App\Domain\Ledger\Services\LedgerService;
use App\Models\LedgerAccount;
use Exception;

class FinancialWalletService
{
    public function __construct(private LedgerService $ledgerService)
    {
    }

    /**
     * Get or create a ledger account (wallet) for a user.
     * To the financial system, a user's balance is a Liability.
     */
    public function getWalletAccount(int $userId, string $currency): LedgerAccount
    {
        return LedgerAccount::firstOrCreate(
            [
                'reference_type' => 'App\Models\User',
                'reference_id' => $userId,
                'currency' => $currency,
                'type' => 'liability',
            ],
            [
                'name' => "User {$userId} {$currency} Wallet"
            ]
        );
    }

    /**
     * Get the system omnibus account for a specific provider and currency.
     * To the financial system, funds held at a provider (e.g. Maplerad) are an Asset.
     */
    public function getSystemAccount(string $currency, string $providerName): LedgerAccount
    {
        return LedgerAccount::firstOrCreate(
            [
                'reference_type' => 'System',
                'reference_id' => 0,
                'currency' => $currency,
                'type' => 'asset',
            ],
            [
                'name' => "System {$providerName} {$currency} Omnibus"
            ]
        );
    }

    /**
     * Fund a user's wallet (e.g. from a Flutterwave deposit).
     * Increases the System Asset and increases the User Liability.
     * Uses strict idempotency.
     */
    public function fundWallet(int $userId, int $amount, string $currency, string $providerName, string $idempotencyKey): void
    {
        if ($amount <= 0) {
            throw new Exception("Funding amount must be greater than zero.");
        }

        $userAccount = $this->getWalletAccount($userId, $currency);
        $systemAccount = $this->getSystemAccount($currency, $providerName);

        $this->ledgerService->recordTransaction(
            description: "Wallet Funding via {$providerName}",
            currency: $currency,
            entries: [
                ['account_id' => $systemAccount->id, 'amount' => $amount, 'direction' => 'debit'],
                ['account_id' => $userAccount->id, 'amount' => $amount, 'direction' => 'credit'],
            ],
            idempotencyKey: $idempotencyKey
        );
    }

    /**
     * Deduct funds from a user's wallet (e.g. to fund a virtual card).
     * Decreases the User Liability and decreases the System Asset.
     */
    public function deductWallet(int $userId, int $amount, string $currency, string $providerName, string $idempotencyKey, string $description = 'Wallet Deduction'): void
    {
        if ($amount <= 0) {
            throw new Exception("Deduction amount must be greater than zero.");
        }

        $userAccount = $this->getWalletAccount($userId, $currency);
        $systemAccount = $this->getSystemAccount($currency, $providerName);

        $this->ledgerService->recordTransaction(
            description: $description,
            currency: $currency,
            entries: [
                ['account_id' => $userAccount->id, 'amount' => $amount, 'direction' => 'debit'],
                ['account_id' => $systemAccount->id, 'amount' => $amount, 'direction' => 'credit'],
            ],
            idempotencyKey: $idempotencyKey,
            status: 'settled',
            balanceChecks: [
                $userAccount->id => $amount // User must have at least $amount before the deduction is processed
            ]
        );
    }

    /**
     * Get the user's exact wallet balance directly from the immutable ledger.
     */
    public function getBalance(int $userId, string $currency): int
    {
        $account = $this->getWalletAccount($userId, $currency);
        return $this->ledgerService->getBalance($account->id, $account->type);
    }
}
