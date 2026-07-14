<?php

namespace Tests\Feature\Fraud;

use App\Domain\Fraud\Services\FraudDetectionService;
use App\Models\User;
use App\Notifications\CriticalSystemAlert;
use App\Support\TaggedCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class FraudDetectionTest extends TestCase
{
    use RefreshDatabase;

    private function service(): FraudDetectionService
    {
        return app(FraudDetectionService::class);
    }

    public function test_a_normal_checkout_is_not_flagged(): void
    {
        $user = User::factory()->create(['created_at' => now()->subYear()]);

        $this->assertFalse($this->service()->isSuspiciousCheckout($user, 50.0, '1.2.3.4'));
    }

    public function test_a_high_value_checkout_on_an_unverified_account_is_flagged_and_alerts_admin(): void
    {
        Notification::fake();
        $user = User::factory()->create(['kyc_status' => 'pending']);

        $this->assertTrue($this->service()->isSuspiciousCheckout($user, 1500.0, '1.2.3.4'));

        Notification::assertSentOnDemand(CriticalSystemAlert::class);
    }

    public function test_a_high_value_checkout_on_a_verified_account_is_allowed(): void
    {
        $user = User::factory()->create(['kyc_status' => 'verified']);

        $this->assertFalse($this->service()->isSuspiciousCheckout($user, 1500.0, '1.2.3.4'));
    }

    public function test_purchase_velocity_over_the_limit_is_flagged(): void
    {
        Notification::fake();
        $user = User::factory()->create(['created_at' => now()->subYear()]);
        TaggedCache::for(['fraud'])->put("fraud_velocity_purchases_{$user->id}", 11, now()->addHour());

        $this->assertTrue($this->service()->isSuspiciousCheckout($user, 10.0, '1.2.3.4'));
    }

    public function test_ip_velocity_over_the_limit_is_flagged(): void
    {
        Notification::fake();
        $user = User::factory()->create(['created_at' => now()->subYear()]);
        TaggedCache::for(['fraud'])->put('fraud_velocity_ip_9.9.9.9', 16, now()->addHour());

        $this->assertTrue($this->service()->isSuspiciousCheckout($user, 10.0, '9.9.9.9'));
    }

    public function test_record_checkout_increments_both_velocity_counters(): void
    {
        $user = User::factory()->create();

        $this->service()->recordCheckout($user, '5.5.5.5');
        $this->service()->recordCheckout($user, '5.5.5.5');

        $this->assertSame(2, TaggedCache::for(['fraud'])->get("fraud_velocity_purchases_{$user->id}"));
        $this->assertSame(2, TaggedCache::for(['fraud'])->get('fraud_velocity_ip_5.5.5.5'));
    }
}
