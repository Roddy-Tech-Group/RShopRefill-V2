<?php

namespace App\Domain\Transaction\Services;

use App\Domain\Wallet\Services\FinancialWalletService;
use App\Domain\Ledger\Services\LedgerService;
use Exception;
use Illuminate\Support\Str;

class TransferService
{
    public function __construct(
        private FinancialWalletService $walletService,
        private LedgerService $ledgerService
    ) {}

    /**
     * Execute a Peer-to-Peer (P2P) transfer between two users in the same currency.
     * Since this is entirely internal, no external providers are called.
     * 
     * @param int $senderId
     * @param int $receiverId
     * @param int $amount
     * @param string $currency
     * @param string|null $note
     * @return void
     * @throws Exception
     */
    public function executeP2PTransfer(int $senderId, int $receiverId, int $amount, string $currency, ?string $note = null): void
    {
        if ($amount <= 0) {
            throw new Exception("Transfer amount must be greater than zero.");
        }

        if ($senderId === $receiverId) {
            throw new Exception("Cannot transfer funds to yourself.");
        }

        $senderAccount = $this->walletService->getWalletAccount($senderId, $currency);
        $receiverAccount = $this->walletService->getWalletAccount($receiverId, $currency);

        $idempotencyKey = Str::uuid()->toString();

        // 1. Internal P2P transfers are purely Liability reassignments.
        // Decrease Sender Liability (Debit) and Increase Receiver Liability (Credit).
        // Total system assets remain unchanged.
        $this->ledgerService->recordTransaction(
            description: "P2P Transfer" . ($note ? ": {$note}" : ""),
            currency: $currency,
            entries: [
                ['account_id' => $senderAccount->id, 'amount' => $amount, 'direction' => 'debit'], // Sender balance decreases
                ['account_id' => $receiverAccount->id, 'amount' => $amount, 'direction' => 'credit'], // Receiver balance increases
            ],
            idempotencyKey: $idempotencyKey,
            status: 'settled',
            balanceChecks: [
                $senderAccount->id => $amount // Atomic check
            ]
        );
    }
}
