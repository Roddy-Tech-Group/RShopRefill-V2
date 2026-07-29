<?php

namespace Tests\Feature\Ledger;

use App\Domain\Ledger\Services\LedgerIntegrityService;
use App\Models\LedgerAccount;
use App\Models\LedgerEntry;
use App\Models\LedgerJournal;
use App\Models\WalletBalance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The ledger is the platform's record of who is owed what. These tests assert
 * that the verifier actually detects each way that record can become a lie —
 * a verifier that always returns "passed" is worse than none, because it
 * manufactures confidence.
 */
class LedgerIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private function verify(): array
    {
        return app(LedgerIntegrityService::class)->verifyLedgerIntegrity();
    }

    /**
     * @param  array<int, array<string, mixed>>  $violations
     */
    private function types(array $violations): array
    {
        return array_column($violations, 'type');
    }

    public function test_an_empty_ledger_passes(): void
    {
        $result = $this->verify();

        $this->assertSame('passed', $result['status']);
        $this->assertSame([], $result['violations']);
    }

    public function test_a_balanced_journal_passes(): void
    {
        $journal = LedgerJournal::factory()->create(['currency' => 'NGN']);
        $cash = LedgerAccount::factory()->asset()->create(['currency' => 'NGN']);
        $wallet = LedgerAccount::factory()->liability()->create(['currency' => 'NGN']);

        LedgerEntry::factory()->debit(50000)->create([
            'ledger_journal_id' => $journal->id,
            'ledger_account_id' => $cash->id,
        ]);
        LedgerEntry::factory()->credit(50000)->create([
            'ledger_journal_id' => $journal->id,
            'ledger_account_id' => $wallet->id,
        ]);

        $this->assertSame('passed', $this->verify()['status']);
    }

    public function test_it_detects_an_unbalanced_journal(): void
    {
        $journal = LedgerJournal::factory()->create(['currency' => 'NGN']);
        $cash = LedgerAccount::factory()->asset()->create(['currency' => 'NGN']);
        $wallet = LedgerAccount::factory()->liability()->create(['currency' => 'NGN']);

        // 500.00 in, 400.00 out — 100.00 conjured from nowhere.
        LedgerEntry::factory()->debit(50000)->create([
            'ledger_journal_id' => $journal->id,
            'ledger_account_id' => $cash->id,
        ]);
        LedgerEntry::factory()->credit(40000)->create([
            'ledger_journal_id' => $journal->id,
            'ledger_account_id' => $wallet->id,
        ]);

        $result = $this->verify();

        $this->assertSame('failed', $result['status']);
        $this->assertContains('unbalanced_journal', $this->types($result['violations']));
    }

    public function test_it_detects_a_journal_with_no_entries(): void
    {
        // Sums to 0 = 0, so a naive balance check calls this healthy.
        LedgerJournal::factory()->create(['description' => 'Phantom transfer']);

        $result = $this->verify();

        $this->assertSame('failed', $result['status']);
        $this->assertContains('empty_journal', $this->types($result['violations']));
    }

    public function test_it_detects_a_non_positive_amount(): void
    {
        $journal = LedgerJournal::factory()->create(['currency' => 'NGN']);
        $account = LedgerAccount::factory()->asset()->create(['currency' => 'NGN']);

        // A signed amount smuggled into a leg: the journal balances arithmetically
        // while describing an economically impossible transaction.
        DB::table('ledger_entries')->insert([
            'ledger_journal_id' => $journal->id,
            'ledger_account_id' => $account->id,
            'amount' => -5000,
            'direction' => 'debit',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('ledger_entries')->insert([
            'ledger_journal_id' => $journal->id,
            'ledger_account_id' => $account->id,
            'amount' => -5000,
            'direction' => 'credit',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $result = $this->verify();

        $this->assertSame('failed', $result['status']);
        $this->assertContains('invalid_amount', $this->types($result['violations']));
    }

    public function test_it_detects_a_currency_mismatch_between_account_and_journal(): void
    {
        $journal = LedgerJournal::factory()->create(['currency' => 'NGN']);
        $ngn = LedgerAccount::factory()->asset()->create(['currency' => 'NGN']);
        $usd = LedgerAccount::factory()->liability()->create(['currency' => 'USD']);

        // 500 NGN "offset" by 500 USD: the integers cancel, the money does not.
        LedgerEntry::factory()->debit(50000)->create([
            'ledger_journal_id' => $journal->id,
            'ledger_account_id' => $ngn->id,
        ]);
        LedgerEntry::factory()->credit(50000)->create([
            'ledger_journal_id' => $journal->id,
            'ledger_account_id' => $usd->id,
        ]);

        $result = $this->verify();

        $this->assertSame('failed', $result['status']);
        $this->assertContains('currency_mismatch', $this->types($result['violations']));
    }

    public function test_it_detects_projection_drift_between_wallet_balance_and_the_ledger(): void
    {
        $journal = LedgerJournal::factory()->create(['currency' => 'NGN']);
        $cash = LedgerAccount::factory()->asset()->create(['currency' => 'NGN']);
        $wallet = LedgerAccount::factory()->liability()->create(['currency' => 'NGN']);

        LedgerEntry::factory()->debit(50000)->create([
            'ledger_journal_id' => $journal->id,
            'ledger_account_id' => $cash->id,
        ]);
        LedgerEntry::factory()->credit(50000)->create([
            'ledger_journal_id' => $journal->id,
            'ledger_account_id' => $wallet->id,
        ]);

        // The customer is shown — and can spend — 900.00 that the ledger says
        // they do not have. This is the check most likely to catch a real bug.
        WalletBalance::create([
            'ledger_account_id' => $wallet->id,
            'currency' => 'NGN',
            'balance' => 90000,
        ]);

        $result = $this->verify();

        $this->assertSame('failed', $result['status']);
        $this->assertContains('projection_drift', $this->types($result['violations']));
    }

    public function test_a_correct_projection_does_not_drift(): void
    {
        $journal = LedgerJournal::factory()->create(['currency' => 'NGN']);
        $cash = LedgerAccount::factory()->asset()->create(['currency' => 'NGN']);
        $wallet = LedgerAccount::factory()->liability()->create(['currency' => 'NGN']);

        LedgerEntry::factory()->debit(50000)->create([
            'ledger_journal_id' => $journal->id,
            'ledger_account_id' => $cash->id,
        ]);
        LedgerEntry::factory()->credit(50000)->create([
            'ledger_journal_id' => $journal->id,
            'ledger_account_id' => $wallet->id,
        ]);

        // Liability account: credits increase it, so the derived balance is 50000.
        WalletBalance::create([
            'ledger_account_id' => $wallet->id,
            'currency' => 'NGN',
            'balance' => 50000,
        ]);
        WalletBalance::create([
            'ledger_account_id' => $cash->id,
            'currency' => 'NGN',
            'balance' => 50000,
        ]);

        $this->assertSame('passed', $this->verify()['status']);
    }

    public function test_the_command_exits_non_zero_when_the_ledger_is_broken(): void
    {
        LedgerJournal::factory()->create();

        $this->artisan('ledger:verify')->assertExitCode(1);
    }

    public function test_the_command_exits_zero_on_a_clean_ledger(): void
    {
        $this->artisan('ledger:verify')->assertExitCode(0);
    }
}
