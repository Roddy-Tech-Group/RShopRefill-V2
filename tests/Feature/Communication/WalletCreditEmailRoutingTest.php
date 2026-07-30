<?php

namespace Tests\Feature\Communication;

use App\Domain\Communication\Enums\CommunicationCategory;
use App\Domain\Communication\Listeners\Wallet\WalletCreditListener;
use App\Domain\Communication\Mail\Rewards\RcoinEarnedMail;
use App\Domain\Communication\Mail\Wallet\WalletFundedMail;
use App\Domain\Communication\Services\CommunicationManager;
use App\Domain\Shared\Enums\TransactionCategory;
use App\Domain\Shared\Enums\WalletTransactionType;
use App\Domain\Wallet\Events\WalletCredited;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * WalletCreditListener must route the credit communication by WHAT the credit
 * was. It used to greet every WalletCredited with "Wallet Funded Successfully":
 * Rcoin cashback arrived as a wallet top-up email, and referral bonuses and
 * refunds were double-mailed on top of their own dedicated notifications.
 *
 * Asserted at the CommunicationManager boundary (the listener's real output);
 * the delivery pipeline behind it is queued, idempotency-guarded and
 * failure-swallowing, so Mail::fake() sees nothing useful here. The listener
 * is invoked directly because WalletService fires the event in
 * DB::afterCommit, which never runs inside the test transaction.
 */
class WalletCreditEmailRoutingTest extends TestCase
{
    use RefreshDatabase;

    private function creditTransaction(TransactionCategory $category, string $currency = 'RCOIN', array $metadata = []): WalletTransaction
    {
        $user = User::factory()->create();
        $wallet = Wallet::create([
            'user_id' => $user->id,
            'currency' => $currency,
            'balance' => 500,
            'is_active' => true,
        ]);

        return WalletTransaction::create([
            'wallet_id' => $wallet->id,
            'user_id' => $user->id,
            'type' => WalletTransactionType::Credit,
            'currency' => $currency,
            'amount' => 500,
            'balance_before' => 0,
            'balance_after' => 500,
            'transaction_category' => $category,
            'reference' => 'TXN-'.uniqid(),
            'description' => 'Test credit',
            'metadata' => $metadata,
        ]);
    }

    private function fire(WalletTransaction $transaction): void
    {
        app(WalletCreditListener::class)->handle(new WalletCredited($transaction));
    }

    /**
     * Mock the manager and capture every send() argument list, searching each
     * for mailables/categories by TYPE so the assertion survives signature
     * reordering and named-argument call sites.
     *
     * @return array<int, array<int, mixed>> captured argument lists (by reference)
     */
    private function &captureSends(): array
    {
        $calls = [];

        $this->mock(CommunicationManager::class, function ($mock) use (&$calls) {
            $mock->shouldReceive('send')->andReturnUsing(function (...$args) use (&$calls) {
                $calls[] = $args;
            });
        });

        return $calls;
    }

    /** @param array<int, mixed> $args */
    private function argOfType(array $args, string $class): ?object
    {
        foreach ($args as $arg) {
            if ($arg instanceof $class) {
                return $arg;
            }
        }

        return null;
    }

    public function test_cashback_credit_sends_the_cashback_email_not_the_funding_one(): void
    {
        $calls = &$this->captureSends();

        $this->fire($this->creditTransaction(
            TransactionCategory::RewardCashback,
            metadata: ['order_number' => 'RSR-CASHBACK1'],
        ));

        $this->assertCount(1, $calls);

        $mailable = $this->argOfType($calls[0], RcoinEarnedMail::class);
        $this->assertNotNull($mailable, 'Cashback must send RcoinEarnedMail');
        $this->assertSame('cashback', $mailable->kind);
        $this->assertSame(500, $mailable->rcoinAmount);
        $this->assertSame('RSR-CASHBACK1', $mailable->orderNumber);

        $this->assertNull($this->argOfType($calls[0], WalletFundedMail::class));
        $this->assertSame(CommunicationCategory::RcoinReward, $this->argOfType($calls[0], CommunicationCategory::class));
    }

    public function test_referral_credit_sends_nothing_from_this_listener(): void
    {
        $calls = &$this->captureSends();

        // RewardEngine already mails the referrer with the referred customer's
        // name; a second mail here was the double-email bug.
        $this->fire($this->creditTransaction(TransactionCategory::RewardReferral));

        $this->assertCount(0, $calls);
    }

    public function test_refund_and_reversal_credits_send_nothing_from_this_listener(): void
    {
        $calls = &$this->captureSends();

        // RefundNotificationListener owns refund communications.
        $this->fire($this->creditTransaction(TransactionCategory::Refund, 'USD'));
        $this->fire($this->creditTransaction(TransactionCategory::Reversal, 'USD'));
        $this->fire($this->creditTransaction(TransactionCategory::RewardReversal));

        $this->assertCount(0, $calls);
    }

    public function test_funding_credit_still_sends_the_wallet_funded_email(): void
    {
        $calls = &$this->captureSends();

        $this->fire($this->creditTransaction(TransactionCategory::Funding, 'USD'));

        $this->assertCount(1, $calls);
        $this->assertNotNull($this->argOfType($calls[0], WalletFundedMail::class));
        $this->assertNull($this->argOfType($calls[0], RcoinEarnedMail::class));
    }

    public function test_rcoin_conversion_credit_reads_as_a_wallet_credit(): void
    {
        $calls = &$this->captureSends();

        // Rcoin-to-USD conversion lands in the cash wallet; "your wallet has
        // been credited" is the accurate description for that one.
        $this->fire($this->creditTransaction(TransactionCategory::RewardRedemption, 'USD'));

        $this->assertCount(1, $calls);
        $this->assertNotNull($this->argOfType($calls[0], WalletFundedMail::class));
    }
}
