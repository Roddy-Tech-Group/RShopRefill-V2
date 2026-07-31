<?php

namespace App\Domain\Ledger\Services;

use App\Models\LedgerAccount;
use App\Models\LedgerEntry;
use App\Models\LedgerJournal;
use App\Models\WalletBalance;
use Exception;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;

class LedgerService
{
    /**
     * Records a strict double-entry transaction.
     * Ensures total debits equal total credits before committing to the database.
     *
     * @param  string  $description  E.g., 'Wallet Funding via Flutterwave'
     * @param  string  $currency  E.g., 'NGN'
     * @param  array  $entries  Array of entries: [['account_id' => 1, 'amount' => 1000, 'direction' => 'debit'], ...]
     * @param  string|null  $idempotencyKey  Optional UUID to prevent duplicate journal records
     * @param  string  $status  Default 'settled', can be 'pending', 'reversed', 'failed'
     * @param  array  $balanceChecks  Array of account_id => required_minimum_balance
     *
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
                    throw new Exception('Ledger entry amount must be greater than zero.');
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

            // 1. Create the Journal Header.
            //
            // `id` is a database auto-increment column (see the create_ledger_journals
            // migration), so it is deliberately not supplied here. A previous version
            // passed Str::uuid() for it — with Str never imported — which made every
            // call to this method fatal and is why the ledger tables were empty.
            $journal = LedgerJournal::create([
                'description' => $description,
                'currency' => $currency,
                'status' => $status,
                'idempotency_key' => $idempotencyKey,
                'correlation_id' => $correlationId ?? Context::get('correlation_id'),
            ]);

            // Lock the balance projections in a deterministic order (ascending
            // account id) so two concurrent transactions touching the same pair of
            // accounts can never deadlock by taking the locks in opposite orders.
            // De-duplicated because one journal may post twice to the same account.
            $accountIds = array_values(array_unique(array_column($entries, 'account_id')));
            sort($accountIds);

            // Account types are read once, up front, rather than stashed onto the
            // projection model. A previous version assigned $projection->account_type
            // as a "temporary property", which Eloquent then tried to persist to a
            // column that does not exist, failing every save.
            $accountTypes = LedgerAccount::whereIn('id', $accountIds)->pluck('type', 'id')->all();

            $lockedProjections = [];
            foreach ($accountIds as $accountId) {
                if (! isset($accountTypes[$accountId])) {
                    throw new Exception("Ledger account {$accountId} does not exist.");
                }

                WalletBalance::firstOrCreate(
                    ['ledger_account_id' => $accountId],
                    ['currency' => $currency, 'balance' => 0]
                );

                $lockedProjections[$accountId] = WalletBalance::where('ledger_account_id', $accountId)
                    ->lockForUpdate()
                    ->first();
            }

            // Perform atomic balance checks AFTER acquiring all locks, but BEFORE creating entries
            foreach ($balanceChecks as $accountId => $minRequiredBalance) {
                if (! isset($lockedProjections[$accountId])) {
                    // We must lock it if we're checking it, even if no entry is being written (rare)
                    throw new Exception('Cannot perform balance check on unlocked account.');
                }

                if ($lockedProjections[$accountId]->balance < $minRequiredBalance) {
                    throw new Exception('Insufficient ledger funds for deduction.');
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

                // Which way a debit moves the balance is defined once, on the
                // model, so this writer and the nightly integrity verifier can
                // never disagree about the sign.
                if (in_array($accountTypes[$entry['account_id']], LedgerAccount::DEBIT_POSITIVE_TYPES, true)) {
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
        $projection = WalletBalance::where('ledger_account_id', $accountId)->first();

        return $projection ? $projection->balance : 0;
    }
}
