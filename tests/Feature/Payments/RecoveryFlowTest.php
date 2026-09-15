<?php

namespace Tests\Feature\Payments;

use App\Domain\Payment\Services\PaymentSessionService;
use App\Domain\PaymentInsights\Enums\RecoveryStatus;
use App\Domain\PaymentInsights\Services\PaymentBackoffManager;
use App\Domain\Shared\Enums\Currency;
use App\Models\Order;
use App\Models\PaymentAttempt;
use App\Models\PaymentDecline;
use App\Models\RecoveryAttempt;
use App\Models\TransactionRecovery;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletFunding;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The public transaction-recovery pages: the tokenised link from the
 * "Complete your Payment" email, the feedback form, and the retry action.
 */
class RecoveryFlowTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: User, 1: TransactionRecovery}
     */
    private function makeRecovery(): array
    {
        $user = User::factory()->create();
        $order = Order::factory()->create(['user_id' => $user->id]);

        $attempt = PaymentAttempt::create([
            'order_id' => $order->id,
            'user_id' => $user->id,
            'gateway' => 'flutterwave',
            'idempotency_key' => 'test_'.Str::random(12),
            'currency' => 'USD',
            'amount' => 25.00,
            'payment_status' => 'pending',
        ]);
        $session = app(PaymentSessionService::class)->createForOrder($order, $attempt, []);

        $recovery = TransactionRecovery::create([
            'payment_session_id' => $session->id,
            'user_id' => $user->id,
            'customer_email' => $user->email,
            'token' => Str::random(40),
            'status' => 'pending',
            'expires_at' => now()->addDay(),
        ]);

        return [$user, $recovery];
    }

    public function test_recovery_page_renders_and_tracks_the_click(): void
    {
        [, $recovery] = $this->makeRecovery();

        $this->withoutVite()
            ->get(route('recovery.show', $recovery->token))
            ->assertOk();

        $this->assertTrue(
            RecoveryAttempt::where('transaction_recovery_id', $recovery->id)->where('action', 'clicked_link')->exists()
        );
    }

    public function test_expired_links_are_refused(): void
    {
        [, $recovery] = $this->makeRecovery();
        $recovery->update(['expires_at' => now()->subMinute()]);

        $this->get(route('recovery.show', $recovery->token))->assertNotFound();
    }

    public function test_feedback_is_recorded_without_clobbering_the_expiry(): void
    {
        [, $recovery] = $this->makeRecovery();
        $originalExpiry = $recovery->expires_at;

        $this->post(route('recovery.feedback', $recovery->token), [
            'reason' => 'declined',
            'details' => 'Bank kept refusing the card',
        ])->assertRedirect(route('recovery.thanks'));

        $recovery->refresh();
        $this->assertSame(RecoveryStatus::FeedbackReceived, $recovery->status);

        $attempt = RecoveryAttempt::where('transaction_recovery_id', $recovery->id)
            ->where('action', 'provided_feedback')->firstOrFail();
        $this->assertSame('declined', $attempt->payload['reason']);

        // Regression: expires_at was a MySQL auto-update TIMESTAMP — any
        // status write silently reset the expiry to "now", killing the link.
        $this->assertTrue($recovery->expires_at->equalTo($originalExpiry), 'expires_at changed on status update');
    }

    public function test_retry_marks_retrying_resets_backoff_and_redirects_to_checkout(): void
    {
        [$user, $recovery] = $this->makeRecovery();

        $response = $this->post(route('recovery.retry', $recovery->token));

        // Retrying, not recovered: going back to checkout is intent, not
        // money. Only a confirmed payment may claim a recovery.
        $this->assertSame(RecoveryStatus::Retrying, $recovery->fresh()->status);
        $this->assertNotNull($recovery->fresh()->retried_at);
        $this->assertNull($recovery->fresh()->recovered_at);
        $this->assertTrue(
            RecoveryAttempt::where('transaction_recovery_id', $recovery->id)->where('action', 'retried_payment')->exists()
        );

        // Checkout, not the order page: the cart survives until a payment
        // confirms, and the order page had no way to pay a failed order.
        $response->assertRedirect(route('shop.checkout'));

        // Backoff cache marker set — the manager must report a clean slate.
        $status = app(PaymentBackoffManager::class)->getBackoffStatus($user, 'checkout', '127.0.0.1');
        $this->assertFalse($status['is_rate_limited']);
    }

    public function test_feedback_is_refused_on_expired_or_resolved_links(): void
    {
        [, $expired] = $this->makeRecovery();
        $expired->update(['expires_at' => now()->subMinute()]);

        $this->post(route('recovery.feedback', $expired->token), ['reason' => 'declined'])
            ->assertNotFound();

        [, $resolved] = $this->makeRecovery();
        $resolved->update(['status' => RecoveryStatus::Recovered]);

        $this->post(route('recovery.feedback', $resolved->token), ['reason' => 'declined'])
            ->assertNotFound();

        // A stray POST must never reopen a resolved recovery.
        $this->assertSame(RecoveryStatus::Recovered, $resolved->fresh()->status);
    }

    public function test_feedback_input_is_length_capped(): void
    {
        [, $recovery] = $this->makeRecovery();

        $this->post(route('recovery.feedback', $recovery->token), [
            'reason' => str_repeat('a', 300),
        ])->assertSessionHasErrors('reason');

        $this->post(route('recovery.feedback', $recovery->token), [
            'reason' => 'declined',
            'details' => str_repeat('a', 3000),
        ])->assertSessionHasErrors('details');

        $this->assertSame(RecoveryStatus::Pending, $recovery->fresh()->status);
    }

    /**
     * A customer invited back by a recovery email must not be greeted by a
     * pause. Failed attempts are counted across checkout and top-ups together,
     * so the retry clears that one shared count, and the customer landing on
     * the wallet top-up can pay straight away.
     */
    public function test_wallet_funding_retry_clears_the_shared_backoff_count(): void
    {
        $user = User::factory()->create();
        $wallet = Wallet::create([
            'user_id' => $user->id,
            'currency' => Currency::USD,
            'balance' => 0.0,
        ]);
        $funding = WalletFunding::create([
            'user_id' => $user->id,
            'wallet_id' => $wallet->id,
            'reference' => 'FUND-USD-'.Str::random(8),
            'currency' => 'USD',
            'amount' => 50.0,
            'gateway' => 'flutterwave',
            'status' => 'pending',
        ]);
        $attempt = PaymentAttempt::create([
            'user_id' => $user->id,
            'payable_type' => WalletFunding::class,
            'payable_id' => $funding->id,
            'gateway' => 'flutterwave',
            'idempotency_key' => 'test_'.Str::random(12),
            'currency' => 'USD',
            'amount' => 50.0,
            'payment_status' => 'pending',
        ]);
        $session = app(PaymentSessionService::class)->createForWalletFunding($funding, $attempt, []);

        $recovery = TransactionRecovery::create([
            'payment_session_id' => $session->id,
            'user_id' => $user->id,
            'customer_email' => $user->email,
            'token' => Str::random(40),
            'status' => 'pending',
            'expires_at' => now()->addDay(),
        ]);

        // Three refused top-ups: enough to pause this customer everywhere.
        foreach (range(1, 3) as $n) {
            PaymentDecline::create([
                'user_id' => $user->id,
                'product_line' => 'wallet_funding',
                'gateway' => 'flutterwave',
                'gateway_transaction_id' => 'recovery-'.$n,
                'tx_ref' => 'ref-'.$user->id,
                'payment_method' => 'card',
                'card_fingerprint' => 'card-'.$n,
                'verdict' => 'Insufficient Funds',
                'verdict_class' => 'soft',
                'amount' => 50,
                'currency' => 'USD',
                'declined_at' => now()->subSeconds(30),
            ]);
        }

        $manager = app(PaymentBackoffManager::class);
        $this->assertSame(3, $manager->peek($user, 'wallet_funding', '127.0.0.1')['attempts']);

        // The wallet, with the sheet told to open for this currency and the
        // failed amount filled in, instead of a bare dashboard.
        $this->post(route('recovery.retry', $recovery->token))
            ->assertRedirect(route('dashboard', ['fund' => 'USD', 'amount' => '50.0000']));

        // One shared count, cleared for both lines at once.
        $this->assertNotNull(Cache::get("payment_backoff_reset:user:{$user->id}"));
        $this->assertSame(0, $manager->peek($user, 'wallet_funding', '127.0.0.1')['attempts']);
        $this->assertSame(0, $manager->peek($user, 'checkout', '127.0.0.1')['attempts']);
    }
}
