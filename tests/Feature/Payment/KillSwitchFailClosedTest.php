<?php

namespace Tests\Feature\Payment;

use App\Domain\Shared\Services\OperationalAlerter;
use App\Enums\AlertSeverity;
use App\Models\SiteSetting;
use App\Support\FeatureFlag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * A gateway kill switch that cannot be read must read as OFF. The generic
 * setting reader falls back to the caller's default when the store fails,
 * and every gateway flag defaults to on, so a cache outage used to turn a
 * switch an admin had deliberately flipped off back on, silently.
 */
class KillSwitchFailClosedTest extends TestCase
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

    public function test_a_switch_an_admin_turned_off_stays_off_when_the_cache_is_down(): void
    {
        SiteSetting::put('features.gateway_flutterwave_enabled', 'off', 'features');
        Cache::shouldReceive('remember')->andThrow(new \RuntimeException('redis: connection refused'));
        Cache::shouldReceive('forget')->andReturnNull();

        // The database still knows the truth, so the answer is the truth,
        // and nobody is paged (the one alert above is the flip itself).
        $this->assertFalse(FeatureFlag::gatewayEnabled('flutterwave'));
        $this->assertSame([], collect($this->alerts)->where('key', 'feature-flag:unreadable:gateway')->all());
    }

    public function test_a_switch_that_is_on_stays_on_when_only_the_cache_is_down(): void
    {
        SiteSetting::put('features.gateway_flutterwave_enabled', 'on', 'features');
        Cache::shouldReceive('remember')->andThrow(new \RuntimeException('redis: connection refused'));
        Cache::shouldReceive('forget')->andReturnNull();

        $this->assertTrue(FeatureFlag::gatewayEnabled('flutterwave'));
    }

    public function test_a_switch_nobody_can_read_reads_as_off_and_pages_someone(): void
    {
        Cache::shouldReceive('remember')->andThrow(new \RuntimeException('redis: connection refused'));
        Schema::drop('site_settings');

        $this->assertFalse(FeatureFlag::gatewayEnabled('flutterwave'));
        $this->assertFalse(FeatureFlag::gatewayEnabled('nowpayments'));
        $this->assertFalse(FeatureFlag::gatewayEnabled('wallet'));
        $this->assertSame(['flutterwave', 'nowpayments', 'wallet', 'paystack', 'korapay'], FeatureFlag::disabledGateways());

        $this->assertNotEmpty($this->alerts);
        $this->assertSame(AlertSeverity::Sev2, $this->alerts[0]['severity']);
        $this->assertSame('feature-flag:unreadable:gateway', $this->alerts[0]['key']);
    }

    public function test_a_missing_row_is_still_on_by_default(): void
    {
        // No row is configuration, not failure: a new gateway ships on until
        // an admin turns it off, exactly as before.
        $this->assertTrue(FeatureFlag::gatewayEnabled('flutterwave'));
    }

    public function test_ordinary_settings_keep_falling_back_to_their_default(): void
    {
        Cache::shouldReceive('remember')->andThrow(new \RuntimeException('redis: connection refused'));
        Schema::drop('site_settings');

        // Page chrome must still render on a bad night; only money switches
        // fail closed.
        $this->assertSame('RshopRefill', SiteSetting::get('brand.name', 'RshopRefill'));
    }
}
