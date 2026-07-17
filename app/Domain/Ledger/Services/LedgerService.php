<?php

namespace App\Domain\Ledger\Services;

use App\Models\LedgerJournal;
use App\Models\LedgerEntry;
use Illuminate\Support\Facades\DB;
use Exception;

class LedgerService
{
    /**
     * Records a strict double-entry transaction.
     * Ensures total debits equal total credits before committing to the database.
     * 
     * @param string $description E.g., 'Wallet Funding via Flutterwave'
     * @param string $currency E.g., 'NGN'
     * @param array $entries Array of entries: [['account_id' => 1, 'amount' => 1000, 'direction' => 'debit'], ...]
     * @param string|null $idempotencyKey Optional UUID to prevent duplicate journal records
     * @param string $status Default 'settled', can be 'pending', 'reversed', 'failed'
     * @param array $balanceChecks Array of account_id => required_minimum_balance
     * @return LedgerJournal
     * @throws Exception
     */
    public function recordTransaction(
        string $description,
        string $currency,
        array $entries,
        ?string $idempotencyKey = null,
        string $status = 'settled',
        array $balanceChecks = [],
        ?string $correlationId = null
    ): LedgerJournal {
        return DB::transaction(function () use ($description, $currency, $entries, $idempotencyKey, $status, $balanceChecks, $correlationId) {
            
            // Handle Idempotency to prevent duplicate charges/funding
            if ($idempotencyKey) {
                $existing = LedgerJournal::where('idempotency_key', $idempotencyKey)->first();
                if ($existing) {
                    return $existing; // Return the already executed journal
                }
            }

            // Validate Double-Entry Rule
            $debits = 0;
            $credits = 0;

            foreach ($entries as $entry) {
                if ($entry['amount'] <= 0) {
                    throw new Exception("Ledger entry amount must be greater than zero.");
                }

                if ($entry['direction'] === 'debit') {
                    $debits += $entry['amount'];
                } elseif ($entry['direction'] === 'credit') {
                    $credits += $entry['amount'];
                } else {
                    throw new Exception("Invalid ledger entry direction: {$entry['direction']}");
                }
            }

            if ($debits !== $credits) {
                throw new Exception("Double-entry violation: Debits ({$debits}) do not equal Credits ({$credits}).");
            }

            // 1. Create the Journal Header
            $journal = LedgerJournal::create([
                'id' => Str::uuid()->toString(),
                'description' => $description,
                'currency' => $currency,
                'status' => $status,
                'idempotency_key' => $idempotencyKey,
                'correlation_id' => $correlationId ?? \Illuminate\Support\Facades\Context::get('correlation_id'),
            ]);

            // Prevent deadlocks by locking projections in order of account_id
            $accountIds = array_column($entries, 'account_id');
            sort($accountIds);
            
            $lockedProjections = [];
            foreach ($accountIds as $accountId) {
                $account = \App\Models\LedgerAccount::find($accountId);
                $projection = \App\Models\WalletBalance::firstOrCreate(
                    ['ledger_account_id' => $accountId],
                    ['currency' => $currency, 'balance' => 0]
                );
                
                $lockedProjections[$accountId] = \App\Models\WalletBalance::where('id', $projection->id)->lockForUpdate()->first();
                $lockedProjections[$accountId]->account_type = $account->type; // Temporary property
            }

            // Perform atomic balance checks AFTER acquiring all locks, but BEFORE creating entries
            foreach ($balanceChecks as $accountId => $minRequiredBalance) {
                if (!isset($lockedProjections[$accountId])) {
                    // We must lock it if we're checking it, even if no entry is being written (rare)
                    throw new Exception("Cannot perform balance check on unlocked account.");
                }
                
                if ($lockedProjections[$accountId]->balance < $minRequiredBalance) {
                    throw new Exception("Insufficient ledger funds for deduction.");
                }
            }

            // 2. Create the Ledger Entries and Update Projections
            foreach ($entries as $entry) {
                LedgerEntry::create([
                    'ledger_journal_id' => $journal->id,
                    'ledger_account_id' => $entry['account_id'],
                    'amount' => $entry['amount'],
                    'direction' => $entry['direction'],
                ]);

                $lockedProjection = $lockedProjections[$entry['account_id']];
                $amount = $entry['amount'];
                
                if (in_array($lockedProjection->account_type, ['asset', 'expense'])) {
                    $effect = ($entry['direction'] === 'debit') ? $amount : -$amount;
                } else {
                    $effect = ($entry['direction'] === 'credit') ? $amount : -$amount;
                }
                
                $lockedProjection->balance += $effect;
                $lockedProjection->save();
            }

            return $journal;
        });
    }

    public function getBalance(int $accountId, string $accountType): int
    {
        $projection = \App\Models\WalletBalance::where('ledger_account_id', $accountId)->first();
        return $projection ? $projection->balance : 0;
    }
}
