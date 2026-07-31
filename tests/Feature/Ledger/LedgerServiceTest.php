<?php

namespace Tests\Feature\Ledger;

use App\Domain\Ledger\Services\LedgerIntegrityService;
use App\Domain\Ledger\Services\LedgerService;
use App\Models\LedgerAccount;
use App\Models\LedgerEntry;
use App\Models\WalletBalance;
use Exception;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers the ledger write path, which until now could not execute at all: it
 * referenced an unimported Str facade and mass-assigned into models with no
 * $fillable, so every call was fatal and both ledger tables stayed empty.
 */
class LedgerServiceTest extends TestCase
{
    use RefreshDatabase;

    private LedgerAccount $cash;

    private LedgerAccount $wallet;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cash = LedgerAccount::factory()->asset()->create(['currency' => 'NGN']);
        $this->wallet = LedgerAccount::factory()->liability()->create(['currency' => 'NGN']);
    }

    public function test_it_records_a_balanced_transaction(): void
    {
        $journal = app(LedgerService::class)->recordTransaction(
            description: 'Wallet funding via Flutterwave',
            currency: 'NGN',
            entries: [
                ['account_id' => $this->cash->id, 'amount' => 50000, 'direction' => 'debit'],
                ['account_id' => $this->wallet->id, 'amount' => 50000, 'direction' => 'credit'],
            ],
        );

        $this->assertDatabaseHas('ledger_journals', [
            'id' => $journal->id,
            'description' => 'Wallet funding via Flutterwave',
            'currency' => 'NGN',
        ]);

        $this->assertSame(2, LedgerEntry::where('ledger_journal_id', $journal->id)->count());
    }

    public function test_a_recorded_transaction_passes_the_integrity_verifier(): void
    {
        // The writer and the verifier are separate code paths on purpose; this
        // asserts they agree about what a valid transaction looks like.
        app(LedgerService::class)->recordTransaction(
            description: 'Wallet funding',
            currency: 'NGN',
            entries: [
                ['account_id' => $this->cash->id, 'amount' => 50000, 'direction' => 'debit'],
                ['account_id' => $this->wallet->id, 'amount' => 50000, 'direction' => 'credit'],
            ],
        );

        $this->assertSame('passed', app(LedgerIntegrityService::class)->verifyLedgerIntegrity()['status']);
    }

    public function test_it_updates_the_balance_projection_in_the_correct_direction(): void
    {
        app(LedgerService::class)->recordTransaction(
            description: 'Wallet funding',
            currency: 'NGN',
            entries: [
                ['account_id' => $this->cash->id, 'amount' => 50000, 'direction' => 'debit'],
                ['account_id' => $this->wallet->id, 'amount' => 50000, 'direction' => 'credit'],
            ],
        );

        // Asset rises on debit; liability rises on credit.
        $this->assertSame(50000, (int) WalletBalance::where('ledger_account_id', $this->cash->id)->value('balance'));
        $this->assertSame(50000, (int) WalletBalance::where('ledger_account_id', $this->wallet->id)->value('balance'));
    }

    public function test_it_refuses_an_unbalanced_transaction(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Double-entry violation');

        app(LedgerService::class)->recordTransaction(
            description: 'Broken',
            currency: 'NGN',
            entries: [
                ['account_id' => $this->cash->id, 'amount' => 50000, 'direction' => 'debit'],
                ['account_id' => $this->wallet->id, 'amount' => 40000, 'direction' => 'credit'],
            ],
        );

        $this->assertDatabaseCount('ledger_journals', 0);
    }

    public function test_it_refuses_a_non_positive_amount(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('greater than zero');

        app(LedgerService::class)->recordTransaction(
            description: 'Broken',
            currency: 'NGN',
            entries: [
                ['account_id' => $this->cash->id, 'amount' => 0, 'direction' => 'debit'],
                ['account_id' => $this->wallet->id, 'amount' => 0, 'direction' => 'credit'],
            ],
        );
    }

    public function test_the_same_idempotency_key_does_not_record_the_transaction_twice(): void
    {
        $entries = [
            ['account_id' => $this->cash->id, 'amount' => 50000, 'direction' => 'debit'],
            ['account_id' => $this->wallet->id, 'amount' => 50000, 'direction' => 'credit'],
        ];

        $first = app(LedgerService::class)->recordTransaction('Funding', 'NGN', $entries, 'idem-key-1');
        $second = app(LedgerService::class)->recordTransaction('Funding', 'NGN', $entries, 'idem-key-1');

        $this->assertSame($first->id, $second->id);
        $this->assertDatabaseCount('ledger_journals', 1);

        // A duplicate credit would double the customer's spendable balance.
        $this->assertSame(50000, (int) WalletBalance::where('ledger_account_id', $this->wallet->id)->value('balance'));
    }

    public function test_it_blocks_a_debit_that_would_overdraw_a_balance_check(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Insufficient ledger funds');

        app(LedgerService::class)->recordTransaction(
            description: 'Spend beyond balance',
            currency: 'NGN',
            entries: [
                ['account_id' => $this->wallet->id, 'amount' => 50000, 'direction' => 'debit'],
                ['account_id' => $this->cash->id, 'amount' => 50000, 'direction' => 'credit'],
            ],
            balanceChecks: [$this->wallet->id => 50000],
        );
    }
}
