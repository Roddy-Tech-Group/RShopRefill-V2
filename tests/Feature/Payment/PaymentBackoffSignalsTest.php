<?php

namespace Tests\Feature\Payment;

use App\Domain\Payment\Services\PaymentSessionService;
use App\Domain\PaymentInsights\Enums\FailureStage;
use App\Domain\PaymentInsights\Services\PaymentBackoffManager;
use App\Models\Order;
use App\Models\PaymentAttempt;
use App\Models\PaymentBackoffEvent;
use App\Models\PaymentOutcome;
use App\Models\PaymentSession;
use App\Models\SiteSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The rate limiter used to count every unsuccessful outcome as a failure.
 * On real traffic the overwhelming majority of those are abandonment, so it
 * throttled customers for browsing while a card tester pacing themselves
 * barely felt it. It was measuring shopping and calling it abuse.
 *
 * These tests pin the two signals that replaced it, the trust bands that
 * decide how much room each customer gets, and the shadow posture that keeps
 * it honest until its thresholds have been checked against live traffic.
 */
class PaymentBackoffSignalsTest extends TestCase
{
    use RefreshDatabase;

    private PaymentBackoffManager $manager;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.flutterwave.secret_key' => 'FLW_SECRET_KEY_MOCK']);

        $this->manager = app(PaymentBackoffManager::class);
        $this->setEnforcement(true);
    }

    private function setEnforcement(bool $on): void
    {
        $key = 'features.payment_backoff_enforce_enabled';
        SiteSetting::put($key, $on ? 'on' : 'off', 'features');
        Cache::forget('site_setting_'.$key);
    }

    private function openSession(User $user, ?Order $order = null): PaymentSession
    {
        $order ??= Order::factory()->create(['user_id' => $user->id]);

        $attempt = PaymentAttempt::create([
            'order_id' => $order->id,
            'user_id' => $user->id,
            'gateway' => 'flutterwave',
            'idempotency_key' => 'k_'.Str::random(16),
            'currency' => 'USD',
            'amount' => 25.00,
            'payment_status' => 'pending',
        ]);

        return app(PaymentSessionService::class)->createForOrder($order, $attempt, []);
    }

    /**
     * Abandon a checkout at a given point in the flow. `awaiting_method` is the
     * customer who tapped Pay and never picked anything; `processing` is a real
     * submitted payment the gateway refused.
     */
    private function abandonAt(User $user, string $status, string $reason = 'popup_closed'): void
    {
        $session = $this->openSession($user);

        if ($status !== $session->status) {
            $session->transitionTo($status);
        }

        app(PaymentSessionService::class)->failSession($session, $reason);
    }

    private function backoffFor(?User $user, string $ip = '203.0.113.77'): array
    {
        return $this->manager->getBackoffStatus($user, 'checkout', $ip);
    }

    // ------------------------------------------------------- the stage itself

    /**
     * Everything else here depends on the stage being recorded truthfully.
     * failSession routes through 'processing' on its way to 'failed', so the
     * outcome used to be stamped with the terminal status and every single
     * failure was filed as a processing-stage one.
     */
    public function test_the_recorded_stage_is_where_the_customer_actually_stopped(): void
    {
        $user = User::factory()->create();

        $this->abandonAt($user, 'awaiting_method');
        $this->assertSame(
            FailureStage::Initialization,
            PaymentOutcome::latest('id')->first()->failure_stage,
            'a customer who never picked a payment method was filed as a processing failure'
        );

        $this->abandonAt($user, 'awaiting_confirmation');
        $this->assertSame(
            FailureStage::Confirmation,
            PaymentOutcome::latest('id')->first()->failure_stage,
        );
    }

    // ------------------------------------------------------------- the signals

    public function test_abandoning_at_the_gateway_is_not_counted_at_all(): void
    {
        $user = User::factory()->create();

        for ($i = 0; $i < 8; $i++) {
            $this->abandonAt($user, 'awaiting_confirmation');
        }

        $status = $this->backoffFor($user);

        $this->assertSame(0, $status['declines']);
        $this->assertSame(0, $status['no_shows']);
        $this->assertFalse($status['is_rate_limited'], 'a customer was throttled for changing their mind');
    }

    public function test_repeat_pay_taps_that_never_reach_a_gateway_are_counted(): void
    {
        $user = User::factory()->create();

        for ($i = 0; $i < 6; $i++) {
            $this->abandonAt($user, 'awaiting_method');
        }

        $status = $this->backoffFor($user);

        $this->assertSame(6, $status['no_shows']);
        $this->assertSame(0, $status['declines']);
        $this->assertSame(PaymentBackoffManager::SIGNAL_NO_SHOW, $status['signal']);
        $this->assertTrue($status['is_rate_limited']);
    }

    public function test_declines_trip_far_sooner_than_no_shows(): void
    {
        $user = User::factory()->create();

        for ($i = 0; $i < 3; $i++) {
            $this->abandonAt($user, 'processing', 'declined');
        }

        $status = $this->backoffFor($user);

        $this->assertSame(3, $status['declines']);
        $this->assertSame(PaymentBackoffManager::SIGNAL_DECLINE, $status['signal']);
        $this->assertTrue($status['is_rate_limited']);
        $this->assertGreaterThan(0, $status['wait_seconds']);
    }

    public function test_three_no_shows_alone_do_not_trip_the_limiter(): void
    {
        $user = User::factory()->create();

        for ($i = 0; $i < 3; $i++) {
            $this->abandonAt($user, 'awaiting_method');
        }

        $this->assertFalse($this->backoffFor($user)['is_rate_limited']);
    }

    // --------------------------------------------------------------- the tiers

    public function test_a_customer_who_has_paid_before_gets_a_longer_leash(): void
    {
        $user = User::factory()->create();

        $paid = $this->openSession($user);
        app(PaymentSessionService::class)->confirmSession($paid);

        // Age the success so it does not also wipe the failures below.
        PaymentOutcome::query()->update(['created_at' => now()->subMinutes(90)]);

        for ($i = 0; $i < 3; $i++) {
            $this->abandonAt($user, 'processing', 'declined');
        }

        $status = $this->backoffFor($user);

        $this->assertSame(PaymentBackoffManager::TIER_TRUSTED, $status['tier']);
        $this->assertSame(3, $status['declines']);
        $this->assertFalse($status['is_rate_limited'], 'a paying customer was throttled at the baseline threshold');
    }

    public function test_a_customer_who_has_never_paid_is_held_to_the_baseline(): void
    {
        $user = User::factory()->create();

        for ($i = 0; $i < 3; $i++) {
            $this->abandonAt($user, 'processing', 'declined');
        }

        $status = $this->backoffFor($user);

        $this->assertSame(PaymentBackoffManager::TIER_KNOWN, $status['tier']);
        $this->assertTrue($status['is_rate_limited']);
    }

    /**
     * Guest checkout does not arrive as a null user: it mints a real User row
     * with is_guest = true and hands that to the guard. A plain null check
     * therefore never sees a real guest, and the tightest band would apply to
     * nobody in production while the cheapest-to-discard identity quietly got
     * the full signed-in allowance.
     */
    public function test_a_guest_shadow_account_is_treated_as_a_guest(): void
    {
        $guest = User::factory()->create(['is_guest' => true]);

        $this->assertSame(PaymentBackoffManager::TIER_GUEST, $this->backoffFor($guest)['tier']);

        for ($i = 0; $i < 4; $i++) {
            $this->abandonAt($guest, 'awaiting_method');
        }

        $status = $this->backoffFor($guest);

        $this->assertSame(4, $status['no_shows']);
        $this->assertTrue($status['is_rate_limited'], 'a guest shadow account got the full signed-in allowance');
    }

    /**
     * Paying outranks being a guest. Someone who has actually given you money
     * is not the threat, whatever kind of account they hold.
     */
    public function test_a_guest_who_has_paid_before_is_trusted(): void
    {
        $guest = User::factory()->create(['is_guest' => true]);

        app(PaymentSessionService::class)->confirmSession($this->openSession($guest));

        $this->assertSame(PaymentBackoffManager::TIER_TRUSTED, $this->backoffFor($guest)['tier']);
    }

    public function test_guests_get_less_room_on_the_no_show_counter(): void
    {
        $user = User::factory()->create();

        for ($i = 0; $i < 4; $i++) {
            $this->abandonAt($user, 'awaiting_method');
        }

        // Re-attribute the outcomes to an anonymous visitor.
        PaymentOutcome::query()->update(['user_id' => null, 'customer_ip' => '198.51.100.7']);

        $asGuest = $this->manager->getBackoffStatus(null, 'checkout', '198.51.100.7');

        $this->assertSame(PaymentBackoffManager::TIER_GUEST, $asGuest['tier']);
        $this->assertSame(4, $asGuest['no_shows']);
        // Four no-shows sits inside the baseline allowance but not the guest one.
        $this->assertTrue($asGuest['is_rate_limited'], 'a guest got the full signed-in allowance');
    }

    // -------------------------------------------------------------- the shadow

    public function test_shadow_mode_records_the_decision_without_blocking(): void
    {
        $this->setEnforcement(false);

        $user = User::factory()->create();

        for ($i = 0; $i < 4; $i++) {
            $this->abandonAt($user, 'processing', 'declined');
        }

        $status = $this->backoffFor($user);

        $this->assertFalse($status['is_rate_limited'], 'shadow mode blocked a customer');
        $this->assertFalse($status['enforced']);
        $this->assertGreaterThan(0, $status['wait_seconds'], 'shadow mode measured nothing');

        $event = PaymentBackoffEvent::latest('id')->first();
        $this->assertNotNull($event, 'shadow mode recorded no event');
        $this->assertFalse($event->enforced);
        $this->assertSame(PaymentBackoffManager::SIGNAL_DECLINE, $event->signal);
        $this->assertSame(4, $event->decline_count);

        // And the checkout guard must let them straight through.
        app(PaymentSessionService::class)->assertNotRateLimited($user, 'checkout');
    }

    public function test_enforcement_is_off_unless_explicitly_enabled(): void
    {
        SiteSetting::query()->where('key', 'features.payment_backoff_enforce_enabled')->delete();
        Cache::forget('site_setting_features.payment_backoff_enforce_enabled');

        $this->assertFalse(
            app(PaymentBackoffManager::class)->enforcementEnabled(),
            'a fresh deployment would start turning customers away on unvalidated thresholds'
        );
    }
}
