<?php

namespace Tests\Feature\Operations;

use App\Domain\Shared\Services\HealthCheckService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The endpoint an external uptime probe polls every 60 seconds.
 *
 * Its whole value is that it fails when the platform cannot serve customers, so
 * these tests are mostly about the failure paths. An endpoint that returns 200
 * unconditionally is worse than none: it converts an outage into a green
 * dashboard.
 */
class HealthEndpointTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // A fresh heartbeat, so scheduler staleness does not bleed into unrelated
        // assertions about other components.
        Cache::put(HealthCheckService::SCHEDULER_HEARTBEAT_KEY, now(), now()->addDay());
    }

    public function test_it_reports_ok_when_the_platform_is_healthy(): void
    {
        $this->getJson('/health')
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('components.database.status', 'ok')
            ->assertJsonPath('components.cache.status', 'ok');
    }

    public function test_it_returns_503_when_the_database_is_unreachable(): void
    {
        // A process that serves HTML while its database is down is not "up"; it
        // is merely running. The probe must page for this.
        DB::shouldReceive('connection->select')
            ->andThrow(new \RuntimeException('SQLSTATE[HY000] [2002] Connection refused'));

        $this->getJson('/health')
            ->assertStatus(503)
            ->assertJsonPath('status', 'fail')
            ->assertJsonPath('components.database.status', 'fail');
    }

    public function test_it_flags_a_stalled_scheduler_without_declaring_the_site_down(): void
    {
        // A dead crontab stops backups, reconciliation and sweepers — serious, but
        // the storefront still takes orders, so it must not trip the uptime probe.
        Cache::put(HealthCheckService::SCHEDULER_HEARTBEAT_KEY, now()->subHour(), now()->addDay());

        $this->getJson('/health')
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('components.scheduler.status', 'fail');
    }

    public function test_it_flags_a_missing_scheduler_heartbeat(): void
    {
        Cache::forget(HealthCheckService::SCHEDULER_HEARTBEAT_KEY);

        $this->getJson('/health')
            ->assertOk()
            ->assertJsonPath('components.scheduler.status', 'fail');
    }

    public function test_it_flags_that_no_sev1_alert_channel_is_configured(): void
    {
        config([
            'alerting.channels.telegram.bot_token' => null,
            'alerting.channels.telegram.chat_id' => null,
            'alerting.channels.slack.webhook_url' => null,
            'alerting.channels.mail.to' => null,
        ]);

        $this->getJson('/health')
            ->assertJsonPath('components.alerting.status', 'fail');
    }

    public function test_it_reports_healthy_alerting_when_a_channel_exists(): void
    {
        config(['alerting.channels.slack.webhook_url' => 'https://hooks.slack.test/x']);

        $this->getJson('/health')->assertJsonPath('components.alerting.status', 'ok');
    }

    public function test_it_is_never_cached(): void
    {
        // A CDN or browser serving a stale "everything is fine" would hide a live
        // outage from the probe.
        $cacheControl = $this->getJson('/health')->headers->get('Cache-Control');

        $this->assertStringContainsString('no-store', (string) $cacheControl);
        $this->assertStringContainsString('no-cache', (string) $cacheControl);
    }

    public function test_it_exposes_no_secrets(): void
    {
        $body = (string) $this->getJson('/health')->getContent();

        // Only non-empty secrets are meaningful to search for: an empty needle is
        // contained in every string and would make this assertion vacuous.
        $secrets = array_filter([
            (string) config('app.key'),
            (string) config('database.connections.mysql.password'),
            (string) config('alerting.channels.telegram.bot_token'),
        ]);

        $this->assertNotEmpty($secrets, 'No secret was available to test against.');

        foreach ($secrets as $secret) {
            $this->assertStringNotContainsString($secret, $body);
        }
    }

    public function test_it_requires_no_authentication(): void
    {
        // A probe cannot log in.
        $this->getJson('/health')->assertOk();
    }
}
