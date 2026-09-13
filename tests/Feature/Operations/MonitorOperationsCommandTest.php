<?php

namespace Tests\Feature\Operations;

use App\Domain\Admin\Services\MonitoringSnapshotService;
use App\Domain\Shared\Services\OperationalAlerter;
use App\Enums\AlertSeverity;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * monitor:operations is the CTO alert fleet: it turns the Monitoring page's
 * signals into Telegram alerts. These tests feed it snapshots and assert the
 * thresholds; a healthy platform must produce total silence, and each class
 * of degradation must produce exactly its own alert.
 */
class MonitorOperationsCommandTest extends TestCase
{
    /** @var OperationalAlerter recording fake bound into the container */
    private $alerter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->alerter = new class extends OperationalAlerter
        {
            /** @var array<int, array{severity: AlertSeverity, key: string, runbook: ?string}> */
            public array $sent = [];

            public function send(
                AlertSeverity $severity,
                string $key,
                string $title,
                string $message,
                array $context = [],
                ?string $runbook = null,
            ): bool {
                $this->sent[] = ['severity' => $severity, 'key' => $key, 'runbook' => $runbook];

                return true;
            }
        };

        $this->app->instance(OperationalAlerter::class, $this->alerter);
    }

    /** @var array<string, mixed> */
    private array $snapshot = [];

    /**
     * Artisan resolves a command once per process, so the snapshot service
     * is mocked once and reads whatever the current test has bound; a test
     * can then run the command several times against changing snapshots.
     */
    private function bindSnapshot(array $overrides = []): void
    {
        $this->snapshot = array_replace_recursive($this->healthySnapshot(), $overrides);

        if (! $this->snapshotMocked) {
            $this->snapshotMocked = true;
            $this->mock(MonitoringSnapshotService::class)
                ->shouldReceive('snapshot')
                ->andReturnUsing(fn () => $this->snapshot);
        }
    }

    private bool $snapshotMocked = false;

    private function healthySnapshot(): array
    {
        $components = [];
        foreach (['database', 'cache', 'redis', 'queue', 'scheduler', 'alerting'] as $name) {
            $components[$name] = ['status' => 'ok', 'message' => null];
        }

        return [
            'health' => ['status' => 'ok', 'components' => $components],
            'backup' => ['healthy' => true],
            'ledger' => ['status' => 'passed', 'violations' => [], 'checks' => []],
            'reconciliation' => ['status' => 'clean', 'anomalies' => 0, 'completed_at' => now()->toDateTimeString()],
            'jobs' => ['failed_24h' => 0, 'by_queue' => []],
            'queues' => ['waits' => ['default' => 2.0], 'worst' => ['queue' => 'default', 'seconds' => 2.0]],
            'sweeper' => ['pickups_24h' => 0, 'pickups_1h' => 0],
            'orders' => ['stuck' => 0, 'requires_attention' => 0],
            'fx' => ['active_rates' => 12, 'newest_age_hours' => 3.0],
            'providers' => [
                'zendit' => [
                    '24h' => ['attempts' => 20, 'errors' => 0, 'fulfilled' => 18, 'failed' => 0, 'in_flight' => 2, 'success_rate' => 100.0],
                    '7d' => ['attempts' => 100, 'errors' => 1, 'fulfilled' => 95, 'failed' => 1, 'in_flight' => 4, 'success_rate' => 99.0],
                    'last_activity_at' => now()->toDateTimeString(),
                    'circuit_breaker_open' => false,
                ],
                'airalo' => [
                    '24h' => ['attempts' => 5, 'errors' => 0, 'fulfilled' => 5, 'failed' => 0, 'in_flight' => 0, 'success_rate' => 100.0],
                    '7d' => ['attempts' => 30, 'errors' => 0, 'fulfilled' => 30, 'failed' => 0, 'in_flight' => 0, 'success_rate' => 100.0],
                    'last_activity_at' => now()->toDateTimeString(),
                    'circuit_breaker_open' => null,
                ],
            ],
            'zendit_balance' => ['balance' => 900.0, 'currency' => 'USD'],
            'airalo_balance' => ['balance' => 700.0, 'currency' => 'USD'],
            'disk' => ['free_gb' => 40.0, 'total_gb' => 80.0, 'used_pct' => 50.0],
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /** @return array<int, string> */
    private function sentKeys(): array
    {
        return array_column($this->alerter->sent, 'key');
    }

    public function test_a_healthy_platform_produces_total_silence(): void
    {
        $this->bindSnapshot();

        $this->artisan('monitor:operations')
            ->expectsOutputToContain('All signals healthy.')
            ->assertExitCode(0);

        $this->assertSame([], $this->alerter->sent);
    }

    public function test_a_failing_health_component_pages_sev1(): void
    {
        $this->bindSnapshot([
            'health' => ['components' => ['database' => ['status' => 'fail', 'message' => 'Connection refused']]],
        ]);

        $this->artisan('monitor:operations')->assertExitCode(0);

        $this->assertContains('health:database', $this->sentKeys());
        $this->assertSame(AlertSeverity::Sev1, $this->alerter->sent[0]['severity']);
    }

    public function test_a_broken_alerting_component_does_not_alert_through_itself(): void
    {
        $this->bindSnapshot([
            'health' => ['components' => ['alerting' => ['status' => 'fail', 'message' => 'No channel configured']]],
        ]);

        $this->artisan('monitor:operations')->assertExitCode(0);

        $this->assertSame([], $this->alerter->sent);
    }

    public function test_disk_thresholds_split_warning_and_critical(): void
    {
        $this->bindSnapshot(['disk' => ['free_gb' => 6.0, 'total_gb' => 80.0, 'used_pct' => 92.5]]);
        $this->artisan('monitor:operations')->assertExitCode(0);

        $this->assertContains('disk:critical', $this->sentKeys());
        $this->assertSame(AlertSeverity::Sev1, $this->alerter->sent[0]['severity']);
    }

    public function test_reconciliation_anomalies_alert_keyed_per_run(): void
    {
        $this->bindSnapshot([
            'reconciliation' => ['status' => 'anomalies_found', 'anomalies' => 4, 'completed_at' => '2026-07-31 02:00:11'],
        ]);

        $this->artisan('monitor:operations')->assertExitCode(0);

        $this->assertContains('recon:wallet:2026-07-31 02:00:11', $this->sentKeys());
    }

    public function test_stuck_orders_stale_fx_and_queue_waits_alert(): void
    {
        $this->bindSnapshot([
            'orders' => ['stuck' => 3],
            'fx' => ['newest_age_hours' => 30.0],
            'queues' => ['waits' => ['webhooks' => 400.0, 'default' => 5.0]],
        ]);

        $this->artisan('monitor:operations')->assertExitCode(0);

        $keys = $this->sentKeys();
        $this->assertContains('orders:stuck', $keys);
        $this->assertContains('fx:stale', $keys);
        $this->assertContains('queue:wait:webhooks', $keys);
        $this->assertNotContains('queue:wait:default', $keys);
    }

    public function test_sweeper_pickup_spike_alerts_as_missing_webhooks(): void
    {
        $this->bindSnapshot(['sweeper' => ['pickups_24h' => 5, 'pickups_1h' => 3]]);

        $this->artisan('monitor:operations')->assertExitCode(0);

        $this->assertContains('sweeper:pickup-spike', $this->sentKeys());
    }

    public function test_provider_degradation_breaker_and_low_float_alert(): void
    {
        $this->bindSnapshot([
            'providers' => [
                'zendit' => [
                    '24h' => ['fulfilled' => 4, 'failed' => 6, 'success_rate' => 40.0],
                    'circuit_breaker_open' => true,
                ],
            ],
            'airalo_balance' => ['balance' => 120.0, 'currency' => 'USD'],
        ]);

        $this->artisan('monitor:operations')->assertExitCode(0);

        $keys = $this->sentKeys();
        $this->assertContains('provider:zendit:breaker', $keys);
        $this->assertContains('provider:zendit:success-rate', $keys);
        $this->assertContains('provider:airalo:balance:150', $keys);
    }

    private function runWithZenditBalance(float $balance): void
    {
        $this->bindSnapshot(['zendit_balance' => ['balance' => $balance, 'currency' => 'USD']]);
        $this->artisan('monitor:operations')->assertExitCode(0);
    }

    /** @return array<int, string> */
    private function zenditBalanceKeys(): array
    {
        return array_values(array_filter($this->sentKeys(), fn (string $key) => str_starts_with($key, 'provider:zendit:balance')));
    }

    public function test_low_float_alerts_once_on_crossing_and_stays_quiet_inside_the_tier(): void
    {
        Carbon::setTestNow('2026-08-27 10:00:00');

        $this->runWithZenditBalance(247.05);
        $this->assertSame(['provider:zendit:balance:250'], $this->zenditBalanceKeys());

        // Same tier, every five minutes for the next six hours: nothing new.
        foreach (range(1, 72) as $run) {
            Carbon::setTestNow(now()->addMinutes(5));
            $this->runWithZenditBalance(247.05 - $run * 0.1);
        }
        $this->assertSame(['provider:zendit:balance:250'], $this->zenditBalanceKeys());
    }

    public function test_falling_through_a_lower_tier_alerts_immediately(): void
    {
        Carbon::setTestNow('2026-08-27 10:00:00');
        $this->runWithZenditBalance(247.05);

        Carbon::setTestNow('2026-08-27 10:05:00');
        $this->runWithZenditBalance(199.0);

        Carbon::setTestNow('2026-08-27 10:10:00');
        $this->runWithZenditBalance(60.0);

        $this->assertSame([
            'provider:zendit:balance:250',
            'provider:zendit:balance:200',
            'provider:zendit:balance:100',
        ], $this->zenditBalanceKeys());

        // The bottom tier fans out to mail too: orders are about to fail.
        $severities = array_column($this->alerter->sent, 'severity', 'key');
        $this->assertSame(AlertSeverity::Sev2, $severities['provider:zendit:balance:250']);
        $this->assertSame(AlertSeverity::Sev1, $severities['provider:zendit:balance:100']);
    }

    public function test_a_standing_low_float_is_reminded_twice_a_day(): void
    {
        Carbon::setTestNow('2026-08-27 10:00:00');
        $this->runWithZenditBalance(247.05);

        Carbon::setTestNow('2026-08-27 21:55:00');
        $this->runWithZenditBalance(247.05);
        $this->assertCount(1, $this->zenditBalanceKeys());

        Carbon::setTestNow('2026-08-27 22:00:00');
        $this->runWithZenditBalance(247.05);
        $this->assertSame(['provider:zendit:balance:250', 'provider:zendit:balance:reminder'], $this->zenditBalanceKeys());

        Carbon::setTestNow('2026-08-28 10:00:00');
        $this->runWithZenditBalance(247.05);
        $this->assertCount(3, $this->zenditBalanceKeys());
    }

    public function test_recovery_above_the_floor_sends_one_message_and_resets_the_state(): void
    {
        Carbon::setTestNow('2026-08-27 10:00:00');
        $this->runWithZenditBalance(247.05);

        Carbon::setTestNow('2026-08-27 12:00:00');
        $this->runWithZenditBalance(900.0);
        $this->runWithZenditBalance(900.0);
        $this->assertSame(['provider:zendit:balance:250', 'provider:zendit:balance:recovered'], $this->zenditBalanceKeys());

        // A later dip is a new incident and alerts again at once.
        Carbon::setTestNow('2026-08-27 13:00:00');
        $this->runWithZenditBalance(240.0);
        $this->assertSame('provider:zendit:balance:250', end($this->alerter->sent)['key']);
    }

    public function test_a_healthy_float_that_was_never_low_sends_no_recovery(): void
    {
        $this->runWithZenditBalance(900.0);

        $this->assertSame([], $this->zenditBalanceKeys());
    }

    public function test_a_thin_sample_does_not_alert_on_success_rate(): void
    {
        // 1 failure out of 2 decided items is a 50% rate, but two items is
        // noise, not a signal.
        $this->bindSnapshot([
            'providers' => [
                'airalo' => ['24h' => ['fulfilled' => 1, 'failed' => 1, 'success_rate' => 50.0]],
            ],
        ]);

        $this->artisan('monitor:operations')->assertExitCode(0);

        $this->assertNotContains('provider:airalo:success-rate', $this->sentKeys());
    }
}
