<?php

namespace Tests\Feature\Ledger;

use App\Domain\Ledger\Models\FinancialLedgerEvent;
use App\Domain\Ledger\Services\WalletLedgerChainVerifier;
use App\Domain\Shared\Enums\TransactionCategory;
use App\Domain\Shared\Services\OperationalAlerter;
use App\Domain\Wallet\Services\WalletService;
use App\Enums\AlertSeverity;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Every wallet movement is written as a hash-chained event beside its
 * transaction row. Until now nothing ever re-read the chain, so a balance
 * edited by hand, a deleted event or a skipped write would pass unnoticed.
 * The verifier recomputes every link from the stored rows and compares the
 * chain's last word with the wallet's balance.
 */
class WalletLedgerChainTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, array<string, mixed>> */
    private array $alerts = [];

    protected function setUp(): void
    {
        parent::setUp();

        $alerts = &$this->alerts;

        $this->app->instance(OperationalAlerter::class, new class($alerts) extends OperationalAlerter
        {
            public function __construct(private array &$sent) {}

            public function send(
                AlertSeverity $severity,
                string $key,
                string $title,
                string $message,
                array $context = [],
                ?string $runbook = null,
            ): bool {
                $this->sent[] = compact('severity', 'key', 'title', 'message', 'context');

                return true;
            }
        });
    }

    private function busyWallet(): Wallet
    {
        $user = User::factory()->create();
        $wallet = Wallet::create(['user_id' => $user->id, 'currency' => 'XAF', 'balance' => 0, 'locked_balance' => 0, 'is_active' => true]);
        $wallets = app(WalletService::class);
        $k = 'w'.$wallet->id.'-';

        // Amounts chosen to produce floating-point residue in the writer's
        // arithmetic (17.77 + 5.5, then minus 0.1 ...): the writer hashed the
        // float it computed, and the verifier must reproduce exactly that.
        $wallets->credit($wallet, 17.77, TransactionCategory::Funding, 'Top-up', $k.'fund-1', $k.'k-1');
        $wallets->credit($wallet, 5.5, TransactionCategory::Funding, 'Top-up', $k.'fund-2', $k.'k-2');
        $wallets->lockFunds($wallet, 3.1);
        $wallets->unlockFunds($wallet, 3.1);
        $wallets->debit($wallet, 0.1, TransactionCategory::Purchase, 'Order', $k.'RSR-1', $k.'k-3');
        $wallets->debit($wallet, 12.34, TransactionCategory::Purchase, 'Order', $k.'RSR-2', $k.'k-4');
        $wallets->credit($wallet, 100, TransactionCategory::Refund, 'Refund', $k.'REF-RSR-2', $k.'k-5');

        return $wallet->fresh();
    }

    public function test_an_untouched_chain_verifies_clean(): void
    {
        $wallet = $this->busyWallet();

        $result = app(WalletLedgerChainVerifier::class)->verify();

        $this->assertSame('passed', $result['status'], json_encode($result['violations']));
        $this->assertSame(1, $result['wallets']);
        $this->assertSame(7, $result['events']);
        $this->assertEqualsWithDelta(110.83, (float) $wallet->balance, 0.0001);
    }

    public function test_a_tampered_event_amount_breaks_the_chain_at_that_event(): void
    {
        $wallet = $this->busyWallet();
        $second = FinancialLedgerEvent::where('wallet_id', $wallet->id)->orderBy('id')->skip(1)->firstOrFail();

        DB::table('financial_ledger_events')->where('id', $second->id)->update(['amount' => 500]);

        $result = app(WalletLedgerChainVerifier::class)->verify();

        $this->assertSame('failed', $result['status']);
        $broken = collect($result['violations'])->where('type', 'broken_link');
        $this->assertSame([$second->id], $broken->pluck('event_id')->all(), 'only the edited event fails; the rest of the chain still links');
    }

    public function test_a_deleted_event_breaks_the_link_that_followed_it(): void
    {
        $wallet = $this->busyWallet();
        $events = FinancialLedgerEvent::where('wallet_id', $wallet->id)->orderBy('id')->get();

        DB::table('financial_ledger_events')->where('id', $events[2]->id)->delete();

        $result = app(WalletLedgerChainVerifier::class)->verify();

        $this->assertSame('failed', $result['status']);
        $types = collect($result['violations'])->pluck('type');
        $this->assertTrue($types->contains('broken_link'));
        $this->assertSame($events[3]->id, collect($result['violations'])->firstWhere('type', 'broken_link')['event_id']);
    }

    public function test_a_balance_edited_by_hand_is_reported(): void
    {
        $wallet = $this->busyWallet();

        DB::table('wallets')->where('id', $wallet->id)->update(['balance' => 999.99]);

        $result = app(WalletLedgerChainVerifier::class)->verify();

        $this->assertSame('failed', $result['status']);
        $drift = collect($result['violations'])->firstWhere('type', 'balance_drift');
        $this->assertNotNull($drift);
        $this->assertSame($wallet->id, $drift['wallet_id']);
        $this->assertStringContainsString('999.99', $drift['message']);
        $this->assertStringContainsString('110.83', $drift['message']);
    }

    /**
     * The writer hashes whatever balance it reads, so a balance set by hand
     * between two movements leaves every link intact and a jump in the
     * money. Found on the local database (wallet 3, 26 May 2026).
     */
    public function test_a_balance_edited_between_two_movements_is_caught_even_though_every_link_holds(): void
    {
        $wallet = $this->busyWallet();

        DB::table('wallets')->where('id', $wallet->id)->update(['balance' => 17.84]);
        app(WalletService::class)->debit($wallet->fresh(), 6.7392, TransactionCategory::Purchase, 'Order', 'RSR-hand', 'k-hand');

        $result = app(WalletLedgerChainVerifier::class)->verify();

        $this->assertSame('failed', $result['status']);
        $this->assertSame(0, $result['checks']['broken_link']);
        $this->assertSame(0, $result['checks']['balance_drift'], 'the wallet balance agrees with the last event; the jump is between events');

        $gap = collect($result['violations'])->firstWhere('type', 'event_gap');
        $this->assertNotNull($gap);
        $this->assertStringContainsString('11.1008', $gap['message']);
        $this->assertStringContainsString('110.8300', $gap['message']);
        $this->assertStringContainsString('-92.9900', $gap['message']);
        $this->assertTrue(collect($result['violations'])->pluck('type')->contains('transaction_gap'));
    }

    public function test_a_transaction_written_without_its_event_is_reported(): void
    {
        $wallet = $this->busyWallet();

        DB::table('wallet_transactions')->insert([
            'wallet_id' => $wallet->id,
            'user_id' => $wallet->user_id,
            'type' => 'credit',
            'currency' => 'XAF',
            'amount' => 50,
            'balance_before' => 110.83,
            'balance_after' => 160.83,
            'transaction_category' => 'funding',
            'description' => 'Slipped in',
            'reference' => 'ghost-1',
            'idempotency_key' => 'ghost-1',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('wallets')->where('id', $wallet->id)->update(['balance' => 160.83]);

        $result = app(WalletLedgerChainVerifier::class)->verify();

        $this->assertSame('failed', $result['status']);
        $types = collect($result['violations'])->pluck('type');
        $this->assertTrue($types->contains('unchained_transaction'), $types->implode(','));
        $this->assertTrue($types->contains('balance_drift'));
    }

    public function test_a_gap_between_transactions_is_reported(): void
    {
        $wallet = $this->busyWallet();
        $last = DB::table('wallet_transactions')->where('wallet_id', $wallet->id)->orderByDesc('id')->first();

        DB::table('wallet_transactions')->where('id', $last->id)->update(['balance_before' => 1.0]);

        $result = app(WalletLedgerChainVerifier::class)->verify();

        $this->assertSame('failed', $result['status']);
        $gap = collect($result['violations'])->firstWhere('type', 'transaction_gap');
        $this->assertNotNull($gap);
        $this->assertSame((int) $last->id, $gap['transaction_id']);
    }

    public function test_the_command_passes_quietly_and_fails_loudly(): void
    {
        $wallet = $this->busyWallet();

        $this->artisan('ledger:verify-chain')->assertSuccessful();
        $this->assertSame([], $this->alerts);

        DB::table('wallets')->where('id', $wallet->id)->update(['balance' => 0]);

        $this->artisan('ledger:verify-chain')->assertFailed();

        $this->assertCount(1, $this->alerts);
        $this->assertSame(AlertSeverity::Sev1, $this->alerts[0]['severity']);
        $this->assertSame('ledger:chain-violation', $this->alerts[0]['key']);
    }

    public function test_the_command_can_verify_one_wallet(): void
    {
        $wallet = $this->busyWallet();
        $other = $this->busyWallet();
        DB::table('wallets')->where('id', $other->id)->update(['balance' => 0]);

        $this->artisan('ledger:verify-chain', ['--wallet' => $wallet->id])->assertSuccessful();
        $this->artisan('ledger:verify-chain', ['--wallet' => $other->id])->assertFailed();
    }
}
