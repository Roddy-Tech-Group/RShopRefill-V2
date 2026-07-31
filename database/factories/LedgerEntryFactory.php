<?php

namespace Database\Factories;

use App\Models\LedgerAccount;
use App\Models\LedgerEntry;
use App\Models\LedgerJournal;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LedgerEntry>
 */
class LedgerEntryFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'ledger_journal_id' => LedgerJournal::factory(),
            'ledger_account_id' => LedgerAccount::factory(),
            'amount' => 10000,
            'direction' => LedgerEntry::DIRECTION_DEBIT,
        ];
    }

    public function debit(int $amount = 10000): static
    {
        return $this->state(fn (): array => [
            'direction' => LedgerEntry::DIRECTION_DEBIT,
            'amount' => $amount,
        ]);
    }

    public function credit(int $amount = 10000): static
    {
        return $this->state(fn (): array => [
            'direction' => LedgerEntry::DIRECTION_CREDIT,
            'amount' => $amount,
        ]);
    }
}
