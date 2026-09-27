<?php

namespace Tests\Feature\Kyc;

use App\Domain\Kyc\Exceptions\SpendingLimitExceededException;
use App\Domain\Kyc\Services\SpendingLimitService;
use App\Domain\Payment\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\SiteSetting;
use App\Models\SpendingLimitEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The caps have run in shadow since they shipped, recording a log line
 * nobody reads. They now keep a row per customer who reaches a cap, whether
 * they were refused or let through, and `limits:report` reads it: the
 * would-have-stopped list that decides whether enforcement is safe to flip.
 */
class SpendingLimitShadowReportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // A $500 daily cap for unverified customers, the shipped default.
        SiteSetting::put('features.spend_limit_standard_day_usd', '500', 'features');
        SiteSetting::put('features.spending_limits_enforce_enabled', 'off', 'features');
        Cache::flush();
    }

    private function customerWhoHasSpent(float $usd): User
    {
        $user = User::factory()->create(['kyc_status' => 'unsubmitted']);

        // Money in: a gateway-paid order counts against the caps.
        Order::factory()->create([
            'user_id' => $user->id,
            'payment_method' => 'card',
            'payment_status' => PaymentStatus::Paid,
            'display_currency' => 'USD',
            'subtotal_amount' => $usd,
            'total_amount' => $usd,
            'metadata' => ['exchange_rate' => 1.0, 'settlement_total_usd' => $usd, 'settlement_subtotal_usd' => $usd],
        ]);

        return $user;
    }

    public function test_a_shadow_hit_is_recorded_and_the_customer_is_let_through(): void
    {
        $user = $this->customerWhoHasSpent(450);

        // $450 already in, another $100 would pass the $500 daily cap.
        app(SpendingLimitService::class)->guard($user, 100, 'funding');

        $event = SpendingLimitEvent::sole();
        $this->assertSame($user->id, $event->user_id);
        $this->assertSame('daily', $event->limit_window);
        $this->assertSame('funding', $event->context);
        $this->assertFalse($event->enforced, 'shadow lets the money through');
        $this->assertFalse($event->kyc_verified);
        $this->assertEqualsWithDelta(500.0, (float) $event->limit_usd, 0.01);
        $this->assertEqualsWithDelta(450.0, (float) $event->money_in_usd, 0.01);
        $this->assertEqualsWithDelta(100.0, (float) $event->amount_usd, 0.01);
        $this->assertEqualsWithDelta(50.0, $event->overshootUsd(), 0.01);
    }

    public function test_an_enforced_refusal_is_recorded_too(): void
    {
        SiteSetting::put('features.spending_limits_enforce_enabled', 'on', 'features');
        Cache::flush();

        $user = $this->customerWhoHasSpent(450);

        try {
            app(SpendingLimitService::class)->guard($user, 100, 'order');
            $this->fail('Expected the cap to refuse.');
        } catch (SpendingLimitExceededException) {
            // expected
        }

        $this->assertTrue(SpendingLimitEvent::sole()->enforced, 'the record must say the customer was actually refused');
    }

    public function test_a_customer_under_the_cap_records_nothing(): void
    {
        $user = $this->customerWhoHasSpent(100);

        app(SpendingLimitService::class)->guard($user, 50, 'order');

        $this->assertSame(0, SpendingLimitEvent::count());
    }

    /**
     * Spending a balance the customer already holds brings no new money in,
     * so it is never counted and never recorded.
     */
    public function test_a_wallet_paid_action_is_never_recorded(): void
    {
        $user = $this->customerWhoHasSpent(450);

        app(SpendingLimitService::class)->guard($user, 100, 'order', bringsNewMoney: false);

        $this->assertSame(0, SpendingLimitEvent::count());
    }

    /**
     * The row is a record of a decision, never the decision itself: if the
     * table is gone the payment still behaves exactly as it should.
     */
    public function test_recording_never_breaks_the_payment(): void
    {
        SiteSetting::put('features.spending_limits_enforce_enabled', 'on', 'features');
        Cache::flush();

        $user = $this->customerWhoHasSpent(450);
        Schema::drop('spending_limit_events');

        $this->expectException(SpendingLimitExceededException::class);
        app(SpendingLimitService::class)->guard($user, 100, 'order');
    }

    public function test_the_report_names_who_would_have_been_stopped(): void
    {
        $small = $this->customerWhoHasSpent(450);
        $big = $this->customerWhoHasSpent(480);

        $service = app(SpendingLimitService::class);
        $service->guard($small, 60, 'funding');
        $service->guard($big, 400, 'order');

        Artisan::call('limits:report', ['--days' => 7]);
        $output = Artisan::output();

        $this->assertStringContainsString('SHADOW', $output);
        $this->assertStringContainsString('2 event(s) across 2 customer(s)', $output);
        $this->assertStringContainsString('0 refused, 2 let through', $output);
        $this->assertStringContainsString($big->email, $output);
        $this->assertStringContainsString($small->email, $output);
        // The worst overshoot leads: 480 + 400 - 500.
        $this->assertStringContainsString('$380.00', $output);
        $this->assertStringContainsString('never flip it before verification is on', $output);
    }

    public function test_the_report_is_quiet_when_nobody_reached_a_cap(): void
    {
        Artisan::call('limits:report');

        $this->assertStringContainsString('No customer has reached a spending cap', Artisan::output());
    }

    public function test_the_report_speaks_json(): void
    {
        $user = $this->customerWhoHasSpent(450);
        app(SpendingLimitService::class)->guard($user, 100, 'funding');

        Artisan::call('limits:report', ['--json' => true]);
        $report = json_decode(Artisan::output(), true);

        $this->assertFalse($report['enforcing']);
        $this->assertSame(1, $report['events']);
        $this->assertSame(1, $report['customers']);
        $this->assertSame(0, $report['refused']);
        $this->assertSame(1, $report['by_window']['daily']);
        $this->assertEqualsWithDelta(500.0, $report['limits']['standard']['day'], 0.01);
    }
}
