<?php

namespace App\Domain\Transaction\Services;

use App\Domain\Wallet\Services\FinancialWalletService;
use App\Domain\Shared\Services\ProviderManager;
use App\Domain\Ledger\Services\LedgerService;
use Exception;
use Illuminate\Support\Str;

class WithdrawalService
{
    public function __construct(
        private FinancialWalletService $walletService,
        private ProviderManager $providerManager,
        private LedgerService $ledgerService
    ) {}

    /**
     * Execute a withdrawal from a user's wallet to an external bank account.
     * Integrates with the Provider API for the actual payout.
     * 
     * @param int $userId
     * @param int $amount
     * @param string $currency
     * @param array $bankDetails Array containing 'account_number' and 'bank_code'
     * @param string $providerName The provider to use for the payout (e.g. 'flutterwave')
     * @return array The provider's transaction response data
     * @throws Exception
     */
    public function withdrawToBank(int $userId, int $amount, string $currency, array $bankDetails, string $providerName = 'flutterwave'): array
    {
        if ($amount <= 0) {
            throw new Exception("Withdrawal amount must be greater than zero.");
        }

        $userAccount = $this->walletService->getWalletAccount($userId, $currency);
        $systemAccount = $this->walletService->getSystemAccount($currency, $providerName);
        $revenueAccount = $this->walletService->getSystemAccount($currency, 'System_Withdrawal_Revenue');

        $withdrawalFee = 5000; // E.g. 50 NGN fee (5000 kobo/cents)
        $totalDeduction = $amount + $withdrawalFee;

        $idempotencyKey = Str::uuid()->toString();

        // 1. Deduct funds from Ledger (Atomic)
        // User Liability decreases. System Asset decreases. System Revenue increases.
        $this->ledgerService->recordTransaction(
            description: "Bank Withdrawal via {$providerName}",
            currency: $currency,
            entries: [
                ['account_id' => $userAccount->id, 'amount' => $totalDeduction, 'direction' => 'debit'], // Decrease User Liability
                ['account_id' => $systemAccount->id, 'amount' => $amount, 'direction' => 'credit'], // Decrease System Asset (leaving the provider)
                ['account_id' => $revenueAccount->id, 'amount' => $withdrawalFee, 'direction' => 'credit'], // Increase System Revenue
            ],
            idempotencyKey: $idempotencyKey,
            status: 'settled',
            balanceChecks: [
                $userAccount->id => $totalDeduction // Atomic check
            ]
        );

        try {
            // 2. Execute Provider Payout API
            $providerResponse = $this->providerManager->wallet($providerName)->withdraw($amount, $currency, $bankDetails);
            
            return $providerResponse;

        } catch (Exception $e) {
            // 3. Rollback on Failure
            // Reverse the ledger entries
            $this->ledgerService->recordTransaction(
                description: "Refund: Failed Bank Withdrawal via {$providerName}",
                currency: $currency,
                entries: [
                    ['account_id' => $systemAccount->id, 'amount' => $amount, 'direction' => 'debit'], // Restore Asset
                    ['account_id' => $revenueAccount->id, 'amount' => $withdrawalFee, 'direction' => 'debit'], // Reverse Revenue
                    ['account_id' => $userAccount->id, 'amount' => $totalDeduction, 'direction' => 'credit'], // Restore User Liability
                ],
                idempotencyKey: $idempotencyKey . '_refund'
            );

            throw new Exception("Withdrawal failed at provider. Funds refunded. Error: " . $e->getMessage());
        }
    }
}
