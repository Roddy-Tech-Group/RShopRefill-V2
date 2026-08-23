<?php

namespace Tests\Feature\Payment;

use App\Domain\Shared\Services\OperationalAlerter;
use App\Models\SiteSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Creating a setting is not flipping a setting: seeding a gateway flag (fresh
 * deploy, new backup) must never page the on-call, while a genuine flip of an
 * existing flag always does. Same rule for the eligibility-enforce alert.
 */
class GatewayFlagAlertTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeding_a_new_gateway_flag_off_fires_no_alert(): void
    {
        $this->mock(OperationalAlerter::class, function ($mock) {
            $mock->shouldReceive('send')->never();
        });

        // A brand-new backup gateway seeded off, exactly like a fresh deploy.
        SiteSetting::put('features.gateway_testpay_enabled', 'off', 'features');
    }

    public function test_flipping_an_existing_gateway_flag_fires_one_alert(): void
    {
        SiteSetting::put('features.gateway_testpay_enabled', 'off', 'features');

        $this->mock(OperationalAlerter::class, function ($mock) {
            $mock->shouldReceive('send')->once();
        });

        SiteSetting::put('features.gateway_testpay_enabled', 'on', 'features');
    }

    public function test_resaving_the_same_gateway_value_fires_no_alert(): void
    {
        SiteSetting::put('features.gateway_testpay_enabled', 'off', 'features');

        $this->mock(OperationalAlerter::class, function ($mock) {
            $mock->shouldReceive('send')->never();
        });

        SiteSetting::put('features.gateway_testpay_enabled', 'off', 'features');
    }

    public function test_flipping_eligibility_enforcement_fires_an_alert(): void
    {
        SiteSetting::put('features.payment_eligibility_enforce_enabled', 'off', 'features');

        $this->mock(OperationalAlerter::class, function ($mock) {
            $mock->shouldReceive('send')->once();
        });

        SiteSetting::put('features.payment_eligibility_enforce_enabled', 'on', 'features');
    }
}
