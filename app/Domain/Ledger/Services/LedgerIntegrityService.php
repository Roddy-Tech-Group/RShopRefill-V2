<?php

namespace App\Domain\Ledger\Services;

use App\Models\LedgerAccount;
use Illuminate\Support\Facades\DB;

/**
 * Independently re-derives the truth of the ledger and reports every way it
 * disagrees with itself.
 *
 * This is deliberately a separate code path from LedgerService: the writer
 * enforces the double-entry rule at write time, and this verifier re-proves it
 * afterwards from raw rows. A bug shared by both would defeat the purpose, so
 * this class reads no domain services and trusts nothing the writer believes.
 *
 * Every check is a SQL aggregate rather than a hydrated collection. The previous
 * implementation loaded every journal and entry into memory, which is survivable
 * at a thousand rows and fatal at ten million — precisely when a money platform
 * can least afford its integrity check to be the thing that falls over.
 *
 * Run nightly via `ledger:verify`. Any violation is a SEV-1: stop wallet
 * operations and investigate before money moves again.
 */
class LedgerIntegrityService
{
    /**
     * @return array{status: string, violations: array<int, array<string, mixed>>, checks: array<string, int>}
     */
    public function verifyLedgerIntegrity(): array
    {
        $checks = [
            'unbalanced_journals' => $this->checkUnbalancedJournals(),
            'empty_journals' => $this->checkEmptyJournals(),
            'orphaned_entries' => $this->checkOrphanedEntries(),
            'invalid_amounts' => $this->checkInvalidAmounts(),
            'invalid_directions' => $this->checkInvalidDirections(),
            'currency_mismatches' => $this->checkCurrencyMismatches(),
            'duplicate_idempotency_keys' => $this->checkDuplicateIdempotencyKeys(),
            'projection_drift' => $this->checkProjectionDrift(),
        ];

        $violations = array_merge(...array_values($checks));

        return [
            'status' => $violations === [] ? 'passed' : 'failed',
            'violations' => $violations,
            'checks' => array_map(fn (array $found): int => count($found), $checks),
        ];
    }

    /**
     * The core double-entry invariant: for every journal, debits === credits.
     *
     * @return array<int, array<string, mixed>>
     */
    private function checkUnbalancedJournals(): array
    {
        $debits = "SUM(CASE WHEN direction = 'debit' THEN amount ELSE 0 END)";
        $credits = "SUM(CASE WHEN direction = 'credit' THEN amount ELSE 0 END)";

        $rows = DB::table('ledger_entries')
            ->select('ledger_journal_id')
            ->selectRaw("{$debits} AS total_debits")
            ->selectRaw("{$credits} AS total_credits")
            ->groupBy('ledger_journal_id')
            ->havingRaw("{$debits} <> {$credits}")
            ->get();

        return $rows->map(fn ($row): array => [
            'type' => 'unbalanced_journal',
            'journal_id' => $row->ledger_journal_id,
            'message' => "Journal {$row->ledger_journal_id} is unbalanced. Debits: {$row->total_debits}, Credits: {$row->total_credits}.",
        ])->all();
    }

    /**
     * A journal with no entries sums to 0 = 0 and therefore passes the balance
     * check while representing a transaction that never happened.
     *
     * @return array<int, array<string, mixed>>
     */
    private function checkEmptyJournals(): array
    {
        $rows = DB::table('ledger_journals')
            ->leftJoin('ledger_entries', 'ledger_entries.ledger_journal_id', '=', 'ledger_journals.id')
            ->whereNull('ledger_entries.id')
            ->select('ledger_journals.id', 'ledger_journals.description')
            ->get();

        return $rows->map(fn ($row): array => [
            'type' => 'empty_journal',
            'journal_id' => $row->id,
            'message' => "Journal {$row->id} ({$row->description}) has no entries.",
        ])->all();
    }

    /**
     * Foreign keys should make this impossible. It is checked anyway because a
     * dropped constraint during a migration is exactly the kind of silent change
     * this verifier exists to catch.
     *
     * @return array<int, array<string, mixed>>
     */
    private function checkOrphanedEntries(): array
    {
        $missingJournal = DB::table('ledger_entries')
            ->leftJoin('ledger_journals', 'ledger_journals.id', '=', 'ledger_entries.ledger_journal_id')
            ->whereNull('ledger_journals.id')
            ->select('ledger_entries.id')
            ->get()
            ->map(fn ($row): array => [
                'type' => 'orphaned_entry',
                'entry_id' => $row->id,
                'message' => "Ledger entry {$row->id} references a journal that does not exist.",
            ])->all();

        $missingAccount = DB::table('ledger_entries')
            ->leftJoin('ledger_accounts', 'ledger_accounts.id', '=', 'ledger_entries.ledger_account_id')
            ->whereNull('ledger_accounts.id')
            ->select('ledger_entries.id')
            ->get()
            ->map(fn ($row): array => [
                'type' => 'orphaned_entry',
                'entry_id' => $row->id,
                'message' => "Ledger entry {$row->id} references an account that does not exist.",
            ])->all();

        return array_merge($missingJournal, $missingAccount);
    }

    /**
     * Amounts are unsigned minor units; the direction carries the sign. A zero or
     * negative amount means something has written a signed value into a leg,
     * which can balance a journal that is economically nonsense.
     *
     * @return array<int, array<string, mixed>>
     */
    private function checkInvalidAmounts(): array
    {
        return DB::table('ledger_entries')
            ->where('amount', '<=', 0)
            ->select('id', 'amount', 'ledger_journal_id')
            ->get()
            ->map(fn ($row): array => [
                'type' => 'invalid_amount',
                'entry_id' => $row->id,
                'journal_id' => $row->ledger_journal_id,
                'message' => "Ledger entry {$row->id} has a non-positive amount ({$row->amount}).",
            ])->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function checkInvalidDirections(): array
    {
        return DB::table('ledger_entries')
            ->whereNotIn('direction', ['debit', 'credit'])
            ->select('id', 'direction')
            ->get()
            ->map(fn ($row): array => [
                'type' => 'invalid_direction',
                'entry_id' => $row->id,
                'message' => "Ledger entry {$row->id} has an unrecognised direction ({$row->direction}).",
            ])->all();
    }

    /**
     * Mixing currencies inside one journal makes its balance meaningless: 100 NGN
     * of debit does not offset 100 USD of credit, but the integers cancel.
     *
     * @return array<int, array<string, mixed>>
     */
    private function checkCurrencyMismatches(): array
    {
        return DB::table('ledger_entries')
            ->join('ledger_journals', 'ledger_journals.id', '=', 'ledger_entries.ledger_journal_id')
            ->join('ledger_accounts', 'ledger_accounts.id', '=', 'ledger_entries.ledger_account_id')
            ->whereColumn('ledger_accounts.currency', '!=', 'ledger_journals.currency')
            ->select(
                'ledger_entries.id',
                'ledger_accounts.currency as account_currency',
                'ledger_journals.currency as journal_currency',
                'ledger_journals.id as journal_id'
            )
            ->get()
            ->map(fn ($row): array => [
                'type' => 'currency_mismatch',
                'entry_id' => $row->id,
                'journal_id' => $row->journal_id,
                'message' => "Ledger entry {$row->id} posts a {$row->account_currency} account into a {$row->journal_currency} journal.",
            ])->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function checkDuplicateIdempotencyKeys(): array
    {
        return DB::table('ledger_journals')
            ->select('idempotency_key')
            ->selectRaw('COUNT(*) AS occurrences')
            ->whereNotNull('idempotency_key')
            ->groupBy('idempotency_key')
            ->havingRaw('COUNT(*) > 1')
            ->get()
            ->map(fn ($row): array => [
                'type' => 'duplicate_idempotency_key',
                'idempotency_key' => $row->idempotency_key,
                'message' => "Idempotency key {$row->idempotency_key} appears in {$row->occurrences} journals — a transaction was recorded more than once.",
            ])->all();
    }

    /**
     * The application reads balances from the wallet_balances projection, never
     * by summing the ledger. If the projection and the ledger disagree, customers
     * are being shown — and allowed to spend — a number that is not real. This is
     * the check most likely to catch a genuine production defect.
     *
     * @return array<int, array<string, mixed>>
     */
    private function checkProjectionDrift(): array
    {
        $debitPositive = "'".implode("','", LedgerAccount::DEBIT_POSITIVE_TYPES)."'";

        // Asset and expense accounts rise on debit; everything else rises on credit.
        $derived = "SUM(CASE
                WHEN ledger_accounts.type IN ({$debitPositive})
                    THEN CASE WHEN ledger_entries.direction = 'debit' THEN ledger_entries.amount ELSE -ledger_entries.amount END
                ELSE CASE WHEN ledger_entries.direction = 'credit' THEN ledger_entries.amount ELSE -ledger_entries.amount END
            END)";

        return DB::table('wallet_balances')
            ->join('ledger_accounts', 'ledger_accounts.id', '=', 'wallet_balances.ledger_account_id')
            ->leftJoin('ledger_entries', 'ledger_entries.ledger_account_id', '=', 'ledger_accounts.id')
            ->groupBy('wallet_balances.id', 'wallet_balances.balance', 'ledger_accounts.id', 'ledger_accounts.name')
            ->havingRaw("COALESCE({$derived}, 0) <> wallet_balances.balance")
            ->select('ledger_accounts.id AS account_id', 'ledger_accounts.name', 'wallet_balances.balance')
            ->selectRaw("COALESCE({$derived}, 0) AS derived_balance")
            ->get()
            ->map(fn ($row): array => [
                'type' => 'projection_drift',
                'account_id' => $row->account_id,
                'message' => "Account {$row->account_id} ({$row->name}) projection reads {$row->balance} but the ledger derives {$row->derived_balance}.",
            ])->all();
    }
}
