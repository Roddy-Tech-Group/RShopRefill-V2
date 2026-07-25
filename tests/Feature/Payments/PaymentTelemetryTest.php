<?php

namespace Tests\Feature\Payments;

use App\Domain\Payment\Services\PaymentSessionService;
use App\Domain\Shared\Enums\Currency;
use App\Domain\Shared\Enums\TransactionCategory;
use App\Domain\Wallet\Services\WalletService;
use App\Models\Order;
use App\Models\PaymentAttempt;
use App\Models\PaymentOutcome;
use App\Models\PaymentSession;
use App\Models\PaymentTelemetryEvent;
use App\Models\SiteSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The frontend error-tracking API: browsers report payment failures
 * (closed popups, ad blockers, SDK errors) against their own session UUID.
 */
class PaymentTelemetryTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = '/api/payment-sessions/telemetry/record-failure';

    private const SIGNAL_ENDPOINT = '/api/payment-sessions/telemetry/record-signal';

    private function makeSession(User $user): PaymentSession
    {
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

        return app(PaymentSessionService::class)->createForOrder($order, $attempt, []);
    }

    public function test_records_a_frontend_failure_against_a_uuid_session(): void
    {
        $user = User::factory()->create();
        $session = $this->makeSession($user);

        $this->actingAs($user)
            ->postJson(self::ENDPOINT, [
                'session_id' => $session->id,
                'reason' => 'Ad blocker detected - popup blocked',
                'context' => ['provider' => 'flutterwave'],
            ])
            ->assertOk()
            ->assertJson(['status' => 'recorded']);

        $this->assertSame('failed', $session->fresh()->status);

        $outcome = PaymentOutcome::where('payment_session_id', $session->id)->firstOrFail();
        $this->assertSame('frontend', $outcome->failure_category->value);
        $this->assertSame('frontend_issue', $outcome->failure_reason_code);
    }

    public function test_closed_popup_is_recorded_as_cancelled(): void
    {
        $user = User::factory()->create();
        $session = $this->makeSession($user);

        $this->actingAs($user)
            ->postJson(self::ENDPOINT, ['session_id' => $session->id, 'reason' => 'User closed modal'])
            ->assertOk();

        $this->assertTrue(
            PaymentOutcome::where('payment_session_id', $session->id)->where('outcome', 'cancelled')->exists()
        );
    }

    public function test_rejects_non_uuid_session_ids(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson(self::ENDPOINT, ['session_id' => 12345, 'reason' => 'x'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('session_id');
    }

    public function test_cannot_terminate_another_customers_session(): void
    {
        $owner = User::factory()->create();
        $session = $this->makeSession($owner);
        $attacker = User::factory()->create();

        $this->actingAs($attacker)
            ->postJson(self::ENDPOINT, ['session_id' => $session->id, 'reason' => 'x'])
            ->assertNotFound();

        $this->assertNotSame('failed', $session->fresh()->status);
    }

    public function test_requires_authentication(): void
    {
        $this->postJson(self::ENDPOINT, ['session_id' => Str::uuid()->toString(), 'reason' => 'x'])
            ->assertUnauthorized();
    }

    public function test_enumerated_sdk_failure_keeps_its_granular_reason_code(): void
    {
        $user = User::factory()->create();
        $session = $this->makeSession($user);

        $this->actingAs($user)
            ->postJson(self::ENDPOINT, ['session_id' => $session->id, 'reason' => 'sdk_load_failed'])
            ->assertOk();

        $outcome = PaymentOutcome::where('payment_session_id', $session->id)->firstOrFail();
        $this->assertSame('frontend', $outcome->failure_category->value);
        $this->assertSame('sdk_load_failed', $outcome->failure_reason_code);
    }

    public function test_signals_are_recorded_without_touching_the_session(): void
    {
        $user = User::factory()->create();
        $session = $this->makeSession($user);
        $statusBefore = $session->fresh()->status;

        $this->actingAs($user)
            ->postJson(self::SIGNAL_ENDPOINT, [
                'session_id' => $session->id,
                'code' => 'js_exception',
                'context' => ['message' => 'TypeError: boom'],
            ])
            ->assertOk()
            ->assertJson(['status' => 'recorded']);

        $event = PaymentTelemetryEvent::where('payment_session_id', $session->id)->firstOrFail();
        $this->assertSame('js_exception', $event->code);
        $this->assertSame('TypeError: boom', $event->context['message']);
        $this->assertSame($user->id, $event->user_id);

        $this->assertSame($statusBefore, $session->fresh()->status);
        $this->assertSame(0, PaymentOutcome::where('payment_session_id', $session->id)->count());
    }

    public function test_signals_reject_unknown_codes(): void
    {
        $user = User::factory()->create();
        $session = $this->makeSession($user);

        $this->actingAs($user)
            ->postJson(self::SIGNAL_ENDPOINT, ['session_id' => $session->id, 'code' => 'made_up'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('code');
    }

    public function test_signals_cannot_be_attached_to_another_customers_session(): void
    {
        $owner = User::factory()->create();
        $session = $this->makeSession($owner);

        $this->actingAs(User::factory()->create())
            ->postJson(self::SIGNAL_ENDPOINT, ['session_id' => $session->id, 'code' => 'js_exception'])
            ->assertNotFound();

        $this->assertSame(0, PaymentTelemetryEvent::count());
    }

    public function test_gateway_can_still_confirm_a_session_the_browser_reported_failed(): void
    {
        $user = User::factory()->create();
        $session = $this->makeSession($user);

        // Customer pays in the popup, closes the tab; the pagehide beacon
        // reports a failure before the gateway webhook lands.
        $this->actingAs($user)
            ->postJson(self::ENDPOINT, ['session_id' => $session->id, 'reason' => 'page_abandoned'])
            ->assertOk();

        $session = $session->fresh();
        $this->assertSame('failed', $session->status);

        // The money arrived anyway: the webhook-side confirm must not dead-end,
        // or the wallet credit / order payment rolls back forever.
        $session->transitionTo('confirmed');
        $this->assertSame('confirmed', $session->fresh()->status);
    }

    /**
     * failSession returns the customer's locked RCOIN. When the gateway later
     * confirms the same session, the confirm must not re-unlock (which throws
     * and rolls back the whole confirm) - it must just settle the debit.
     */
    public function test_late_confirm_after_failure_settles_rcoin_without_double_unlock(): void
    {
        $user = User::factory()->create();
        $session = $this->makeSession($user);

        $walletService = app(WalletService::class);
        $wallet = $walletService->getOrCreateWallet($user, Currency::RCOIN);
        $walletService->credit(wallet: $wallet, amount: 500, category: TransactionCategory::RewardCashback, description: 'seed');
        $walletService->lockFunds($wallet, 200);

        $order = $session->paymentAttempt->order;
        $order->metadata = array_merge($order->metadata ?? [], ['rcoin_applied' => 200]);
        $order->save();

        // Browser reports failure: the RCOIN lock is released.
        $this->actingAs($user)
            ->postJson(self::ENDPOINT, ['session_id' => $session->id, 'reason' => 'page_abandoned'])
            ->assertOk();
        $this->assertSame(0.0, (float) $wallet->fresh()->locked_balance);

        // Gateway webhook confirms late: no throw, RCOIN debited exactly once.
        app(PaymentSessionService::class)->confirmSession($session->fresh());

        $this->assertSame('confirmed', $session->fresh()->status);
        $this->assertSame(300.0, (float) $wallet->fresh()->balance);
        $this->assertSame(0.0, (float) $wallet->fresh()->locked_balance);
    }

    public function test_reset_backoff_endpoint_no_longer_exists(): void
    {
        $user = User::factory()->create();

        // Self-service backoff reset defeated the anti-card-testing ladder;
        // the endpoint was removed and must stay gone.
        $this->actingAs($user)
            ->postJson('/api/payment-sessions/telemetry/reset-backoff', ['product_line' => 'checkout'])
            ->assertNotFound();
    }

    public function test_sessions_without_a_customer_email_fail_closed(): void
    {
        $user = User::factory()->create();
        $session = $this->makeSession($user);
        // The column is NOT NULL, but an empty string is just as ownerless -
        // and bypassed the old truthiness-based ownership check.
        PaymentSession::whereKey($session->id)->update(['customer_email' => '']);

        $this->actingAs($user)
            ->postJson(self::ENDPOINT, ['session_id' => $session->id, 'reason' => 'x'])
            ->assertNotFound();

        $this->actingAs($user)
            ->postJson(self::SIGNAL_ENDPOINT, ['session_id' => $session->id, 'code' => 'js_exception'])
            ->assertNotFound();

        $this->assertNotSame('failed', $session->fresh()->status);
        $this->assertSame(0, PaymentTelemetryEvent::count());
    }

    public function test_no_ops_gracefully_when_tracking_flag_is_off(): void
    {
        $user = User::factory()->create();
        $session = $this->makeSession($user);

        SiteSetting::put('features.frontend_payment_tracking_enabled', 'off', 'features');
        Cache::forget('site_setting_features.frontend_payment_tracking_enabled');

        $this->actingAs($user)
            ->postJson(self::ENDPOINT, ['session_id' => $session->id, 'reason' => 'Ad blocker'])
            ->assertOk()
            ->assertJson(['status' => 'disabled']);

        $this->actingAs($user)
            ->postJson(self::SIGNAL_ENDPOINT, ['session_id' => $session->id, 'code' => 'js_exception'])
            ->assertOk()
            ->assertJson(['status' => 'disabled']);

        $this->assertNotSame('failed', $session->fresh()->status);
        $this->assertSame(0, PaymentOutcome::where('payment_session_id', $session->id)->count());
        $this->assertSame(0, PaymentTelemetryEvent::count());
    }
}
