<?php

namespace Tests\Feature\Payments;

use App\Domain\Payment\Services\PaymentSessionService;
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
