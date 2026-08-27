<?php

namespace Tests\Feature\Communication;

use App\Domain\Communication\Services\ResendApiThrottle;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use RuntimeException;
use Tests\TestCase;

/**
 * The throttle caps total Resend requests per second across every worker,
 * so a newsletter blast fanned out over ten Horizon processes can never
 * exceed Resend's account-wide limit.
 */
class ResendApiThrottleTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        RateLimiter::clear('resend-api-requests');
    }

    public function test_requests_within_the_per_second_ceiling_pass_immediately(): void
    {
        Carbon::setTestNow('2026-08-26 18:25:50');
        $throttle = new ResendApiThrottle(maxPerSecond: 3, maxWaitMilliseconds: 0);

        $throttle->acquire();
        $throttle->acquire();
        $throttle->acquire();

        $this->assertSame(3, RateLimiter::attempts('resend-api-requests'));
    }

    public function test_request_over_the_ceiling_fails_when_no_slot_opens_in_the_wait_budget(): void
    {
        Carbon::setTestNow('2026-08-26 18:25:50');
        $throttle = new ResendApiThrottle(maxPerSecond: 2, maxWaitMilliseconds: 0);

        $throttle->acquire();
        $throttle->acquire();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('limit 2/s');

        $throttle->acquire();
    }

    public function test_ceiling_resets_on_the_next_second(): void
    {
        Carbon::setTestNow('2026-08-26 18:25:50');
        $throttle = new ResendApiThrottle(maxPerSecond: 1, maxWaitMilliseconds: 0);

        $throttle->acquire();

        Carbon::setTestNow('2026-08-26 18:25:52');

        $throttle->acquire();

        $this->assertSame(1, RateLimiter::attempts('resend-api-requests'));
    }

    public function test_waits_for_a_slot_instead_of_failing_when_it_has_a_budget(): void
    {
        // Real clock here: the window opened at "now" and expires one second
        // later, so a 1.5s wait budget must find a slot without throwing.
        $throttle = new ResendApiThrottle(maxPerSecond: 1, maxWaitMilliseconds: 1500);

        $throttle->acquire();
        $started = hrtime(true);
        $throttle->acquire();

        $this->assertGreaterThan(0.2, (hrtime(true) - $started) / 1e9, 'second call should have waited for the window to roll');
    }

    public function test_send_goes_through_when_the_cache_store_is_unreachable(): void
    {
        // The gate exists to smooth bursts; it must never be the reason an
        // OTP or receipt does not leave.
        Log::spy();
        RateLimiter::shouldReceive('attempt')->once()->andThrow(new RuntimeException('Connection refused'));

        $throttle = new ResendApiThrottle(maxPerSecond: 1, maxWaitMilliseconds: 0);
        $throttle->acquire();

        Log::shouldHaveReceived('warning')->once()->withArgs(
            fn (string $message) => $message === 'Resend API throttle unavailable, sending without it'
        );
    }

    public function test_config_defaults_leave_headroom_under_resends_ten_per_second(): void
    {
        $throttle = ResendApiThrottle::fromConfig();

        $this->assertSame(8, (fn () => $this->maxPerSecond)->call($throttle));
        $this->assertSame(5000, (fn () => $this->maxWaitMilliseconds)->call($throttle));
    }
}
