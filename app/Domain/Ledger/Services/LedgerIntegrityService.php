<?php

namespace App\Domain\Ledger\Services;

use App\Models\LedgerEntry;
use App\Models\LedgerJournal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class LedgerIntegrityService
{
    /**
     * Run a comprehensive integrity check on the entire ledger system.
     * 
     * @return array Contains 'status' (passed/failed) and an array of 'violations'
     */
    public function verifyLedgerIntegrity(): array
    {
        $violations = [];

        $violations = array_merge($violations, $this->checkUnbalancedJournals());
        $violations = array_merge($violations, $this->checkOrphanedEntries());
        $violations = array_merge($violations, $this->checkDuplicateIdempotencyKeys());

        return [
            'status' => count($violations) === 0 ? 'passed' : 'failed',
            'violations' => $violations,
        ];
    }

    /**
     * Verify that every journal has strictly balancing debits and credits.
     */
    private function checkUnbalancedJournals(): array
    {
        $violations = [];

        // Group entries by journal_id and direction, then SUM
        $journals = LedgerJournal::with('entries')->get();

        foreach ($journals as $journal) {
            $debits = $journal->entries->where('direction', 'debit')->sum('amount');
            $credits = $journal->entries->where('direction', 'credit')->sum('amount');

            if ($debits !== $credits) {
                $violations[] = [
                    'type' => 'unbalanced_journal',
                    'journal_id' => $journal->id,
                    'message' => "Journal {$journal->id} is unbalanced. Debits: {$debits}, Credits: {$credits}",
                ];
            }
        }

        return $violations;
    }

    /**
     * Verify that there are no ledger entries without an associated journal.
     */
    private function checkOrphanedEntries(): array
    {
        $violations = [];

        $orphans = LedgerEntry::whereNull('ledger_journal_id')
            ->orWhereDoesntHave('journal')
            ->get();

        foreach ($orphans as $orphan) {
            $violations[] = [
                'type' => 'orphaned_entry',
                'entry_id' => $orphan->id,
                'message' => "Ledger entry {$orphan->id} has no valid journal.",
            ];
        }

        return $violations;
    }

    /**
     * Verify that no idempotency key has been used more than once.
     */
    private function checkDuplicateIdempotencyKeys(): array
    {
        $violations = [];

        $duplicates = LedgerJournal::select('idempotency_key')
            ->whereNotNull('idempotency_key')
            ->groupBy('idempotency_key')
            ->havingRaw('COUNT(id) > 1')
            ->get();

        foreach ($duplicates as $duplicate) {
            $violations[] = [
                'type' => 'duplicate_idempotency_key',
                'idempotency_key' => $duplicate->idempotency_key,
                'message' => "Idempotency key {$duplicate->idempotency_key} appears in multiple journals.",
            ];
        }

        return $violations;
    }
}
