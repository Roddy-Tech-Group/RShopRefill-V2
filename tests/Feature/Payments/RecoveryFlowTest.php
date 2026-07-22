<?php

namespace Tests\Feature\Payments;

use App\Domain\Payment\Services\PaymentSessionService;
use App\Domain\PaymentInsights\Services\PaymentBackoffManager;
use App\Models\Order;
use App\Models\PaymentAttempt;
use App\Models\RecoveryAttempt;
use App\Models\TransactionRecovery;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        $this->assertSame('feedback_received', $recovery->status);

        $attempt = RecoveryAttempt::where('transaction_recovery_id', $recovery->id)
            ->where('action', 'provided_feedback')->firstOrFail();
        $this->assertSame('declined', $attempt->payload['reason']);

        // Regression: expires_at was a MySQL auto-update TIMESTAMP — any
        // status write silently reset the expiry to "now", killing the link.
        $this->assertTrue($recovery->expires_at->equalTo($originalExpiry), 'expires_at changed on status update');
    }

    public function test_retry_marks_recovered_resets_backoff_and_redirects_to_the_order(): void
    {
        [$user, $recovery] = $this->makeRecovery();

        $response = $this->post(route('recovery.retry', $recovery->token));

        $this->assertSame('recovered', $recovery->fresh()->status);
        $this->assertTrue(
            RecoveryAttempt::where('transaction_recovery_id', $recovery->id)->where('action', 'retried_payment')->exists()
        );

        $order = $recovery->paymentSession->order;
        $response->assertRedirect(route('shop.order', ['orderNumber' => $order->order_number]));

        // Backoff cache marker set — the manager must report a clean slate.
        $status = app(PaymentBackoffManager::class)->getBackoffStatus($user, 'checkout', '127.0.0.1');
        $this->assertFalse($status['is_rate_limited']);
    }
}
