<?php

namespace App\Console\Commands;

use App\Domain\Admin\Services\MonitoringSnapshotService;
use App\Domain\Shared\Services\OperationalAlerter;
use App\Enums\AlertSeverity;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * The CTO alert fleet: evaluates every signal the Monitoring page shows and
 * pushes anything unhealthy to Telegram, so the phone knows before Sentry
 * does. Runs every five minutes; the OperationalAlerter's throttle window
 * keeps a standing problem from re-sending on every run.
 *
 * Signals that already alert from their own command (nightly ledger:verify,
 * backup:health, pricing:audit-below-cost) are deliberately NOT re-checked
 * here; one problem must produce one alert stream, not two.
 */
class MonitorOperationsCommand extends Command
{
    protected $signature = 'monitor:operations';

    protected $description = 'Evaluate operational health signals and alert on anything unhealthy';

    /** Queue wait (seconds) that means customers are visibly waiting. */
    private const QUEUE_WAIT_ALERT_SECONDS = 300;

    /** Failed jobs in 24h that count as a spike rather than noise. */
    private const FAILED_JOBS_SPIKE = 5;

    /** 24h fulfillment success rate below this is a degraded provider. */
    private const PROVIDER_SUCCESS_FLOOR = 90.0;

    /** Minimum decided items before a success rate is worth alerting on. */
    private const PROVIDER_MIN_DECIDED = 5;

    /** Provider float at or below this fails orders like an outage would. */
    private const BALANCE_FLOOR = 250.0;

    /** Each crossing alerts immediately; sitting inside a tier only reminds. */
    private const BALANCE_TIERS = [250.0, 200.0, 150.0, 100.0];

    /** At or below this the alert escalates to SEV-1 (Telegram + mail). */
    private const BALANCE_CRITICAL_TIER = 100.0;

    /** How often to repeat a standing low-float alert (twice a day). */
    private const BALANCE_REMINDER_HOURS = 12;

    /** FX snapshots older than this misprice the whole catalog. */
    private const FX_STALE_HOURS = 26.0;

    public function __construct(
        private MonitoringSnapshotService $snapshotService,
        private OperationalAlerter $alerter,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $snapshot = $this->snapshotService->snapshot();
        $sent = 0;

        $sent += $this->checkHealthComponents($snapshot['health']);
        $sent += $this->checkDisk($snapshot['disk']);
        $sent += $this->checkReconciliation($snapshot['reconciliation']);
        $sent += $this->checkStuckOrders($snapshot['orders']);
        $sent += $this->checkFailedJobs($snapshot['jobs']);
        $sent += $this->checkQueueWaits($snapshot['queues']);
        $sent += $this->checkSweeper($snapshot['sweeper']);
        $sent += $this->checkFx($snapshot['fx']);
        $sent += $this->checkProviders($snapshot['providers']);
        $sent += $this->checkBalances([
            'zendit' => $snapshot['zendit_balance'],
            'airalo' => $snapshot['airalo_balance'],
        ]);

        $this->info($sent === 0 ? 'All signals healthy.' : "{$sent} alert(s) raised.");

        // Monitoring succeeded either way; a non-zero exit here would page
        // about the messenger instead of the message.
        return self::SUCCESS;
    }

    /** @param array<string, mixed> $health */
    private function checkHealthComponents(array $health): int
    {
        $sent = 0;

        foreach (($health['components'] ?? []) as $name => $component) {
            if (($component['status'] ?? 'ok') === 'ok') {
                continue;
            }

            // A broken alerting channel cannot announce itself through the
            // channel that is broken; /health and alerting:test surface it.
            if ($name === 'alerting') {
                continue;
            }

            $sent += (int) $this->alerter->send(
                severity: AlertSeverity::Sev1,
                key: "health:{$name}",
                title: "Health check failing: {$name}",
                message: (string) ($component['message'] ?? 'Component probe failed.'),
                context: ['component' => $name],
                runbook: match ($name) {
                    'database' => 'docs/runbooks/database-restore.md',
                    'queue' => 'docs/runbooks/queue-backlog.md',
                    default => 'docs/runbooks/logs.md',
                },
            );
        }

        return $sent;
    }

    /** @param ?array{free_gb: float, total_gb: float, used_pct: float} $disk */
    private function checkDisk(?array $disk): int
    {
        if ($disk === null || $disk['used_pct'] < 75) {
            return 0;
        }

        $critical = $disk['used_pct'] >= 90;

        return (int) $this->alerter->send(
            severity: $critical ? AlertSeverity::Sev1 : AlertSeverity::Sev2,
            key: $critical ? 'disk:critical' : 'disk:warning',
            title: $critical ? 'Disk almost full' : 'Disk filling up',
            message: sprintf(
                '%.1f%% used, %.1f GB free of %.1f GB. A full disk stops MySQL, backups and logging at once.',
                $disk['used_pct'],
                $disk['free_gb'],
                $disk['total_gb'],
            ),
            context: $disk,
            runbook: 'docs/runbooks/logs.md',
        );
    }

    /** @param ?array{status: string, anomalies: int, completed_at: ?string} $recon */
    private function checkReconciliation(?array $recon): int
    {
        if ($recon === null || $recon['status'] === 'clean') {
            return 0;
        }

        // Keyed on the run's completion time so each anomalous run alerts
        // exactly once instead of hourly until the next clean run.
        return (int) $this->alerter->send(
            severity: AlertSeverity::Sev2,
            key: 'recon:wallet:'.($recon['completed_at'] ?? 'unknown'),
            title: 'Wallet reconciliation found anomalies',
            message: sprintf(
                '%d anomalies in the latest wallet-balance reconciliation (run %s). Never auto-fix a break; investigate to root cause.',
                $recon['anomalies'],
                $recon['completed_at'] ?? 'time unknown',
            ),
            context: $recon,
            runbook: 'docs/runbooks/ledger-integrity.md',
        );
    }

    /** @param array{stuck: int, requires_attention: int} $orders */
    private function checkStuckOrders(array $orders): int
    {
        if ($orders['stuck'] === 0) {
            return 0;
        }

        return (int) $this->alerter->send(
            severity: AlertSeverity::Sev2,
            key: 'orders:stuck',
            title: 'Paid orders stuck without fulfilment',
            message: sprintf(
                '%d order(s) paid more than 15 minutes ago with fulfilment never started. Each one is a customer who paid and received nothing yet.',
                $orders['stuck'],
            ),
            context: $orders,
            runbook: 'docs/runbooks/fulfillment-provider-outage.md',
        );
    }

    /** @param array{failed_24h: int, by_queue: array<string, int>} $jobs */
    private function checkFailedJobs(array $jobs): int
    {
        if ($jobs['failed_24h'] < self::FAILED_JOBS_SPIKE) {
            return 0;
        }

        $breakdown = collect($jobs['by_queue'])
            ->map(fn (int $count, string $queue) => "{$queue}: {$count}")
            ->implode(', ');

        return (int) $this->alerter->send(
            severity: AlertSeverity::Sev2,
            key: 'jobs:failed-spike',
            title: 'Failed jobs piling up',
            message: sprintf(
                '%d failed jobs in 24h (%s). Review in Horizon before the 7-day evidence window expires.',
                $jobs['failed_24h'],
                $breakdown,
            ),
            context: $jobs,
            runbook: 'docs/runbooks/queue-backlog.md',
        );
    }

    /** @param array{waits: array<string, float>, worst: ?array{queue: string, seconds: float}} $queues */
    private function checkQueueWaits(array $queues): int
    {
        $sent = 0;

        foreach ($queues['waits'] as $queue => $seconds) {
            if ($seconds < self::QUEUE_WAIT_ALERT_SECONDS) {
                continue;
            }

            $sent += (int) $this->alerter->send(
                severity: AlertSeverity::Sev2,
                key: "queue:wait:{$queue}",
                title: "Queue backing up: {$queue}",
                message: sprintf(
                    'Oldest job on "%s" has waited %.0f seconds. Nothing has failed, but customers are waiting; check Horizon workers.',
                    $queue,
                    $seconds,
                ),
                context: ['queue' => $queue, 'wait_seconds' => $seconds],
                runbook: 'docs/runbooks/queue-backlog.md',
            );
        }

        return $sent;
    }

    /** @param array{pickups_24h: int, pickups_1h: int} $sweeper */
    private function checkSweeper(array $sweeper): int
    {
        if ($sweeper['pickups_1h'] < 3) {
            return 0;
        }

        return (int) $this->alerter->send(
            severity: AlertSeverity::Sev2,
            key: 'sweeper:pickup-spike',
            title: 'Payment webhooks going missing',
            message: sprintf(
                'The rescue sweeper picked up %d paid-but-unfulfilled order(s) in the last hour (%d in 24h). A payment provider\'s webhooks are likely failing.',
                $sweeper['pickups_1h'],
                $sweeper['pickups_24h'],
            ),
            context: $sweeper,
            runbook: 'docs/runbooks/payment-provider-failure.md',
        );
    }

    /** @param array{active_rates: int, newest_age_hours: ?float} $fx */
    private function checkFx(array $fx): int
    {
        if ($fx['newest_age_hours'] === null || $fx['newest_age_hours'] <= self::FX_STALE_HOURS) {
            return 0;
        }

        return (int) $this->alerter->send(
            severity: AlertSeverity::Sev2,
            key: 'fx:stale',
            title: 'Exchange rates are stale',
            message: sprintf(
                'Newest FX rate is %.1f hours old (limit %.0fh). Stale rates misprice the entire catalog; run rates:fetch.',
                $fx['newest_age_hours'],
                self::FX_STALE_HOURS,
            ),
            context: $fx,
            runbook: 'docs/runbooks/fx-failure.md',
        );
    }

    /** @param array<string, array<string, mixed>> $providers */
    private function checkProviders(array $providers): int
    {
        $sent = 0;

        foreach ($providers as $name => $provider) {
            if (($provider['circuit_breaker_open'] ?? null) === true) {
                $sent += (int) $this->alerter->send(
                    severity: AlertSeverity::Sev2,
                    key: "provider:{$name}:breaker",
                    title: "Circuit breaker open: {$name}",
                    message: "The {$name} circuit breaker is open; calls are failing fast and fulfilment is paused for its products.",
                    context: ['provider' => $name],
                    runbook: 'docs/runbooks/fulfillment-provider-outage.md',
                );
            }

            $day = $provider['24h'] ?? [];
            $rate = $day['success_rate'] ?? null;
            $decided = (int) ($day['fulfilled'] ?? 0) + (int) ($day['failed'] ?? 0);

            if ($rate !== null && $decided >= self::PROVIDER_MIN_DECIDED && $rate < self::PROVIDER_SUCCESS_FLOOR) {
                $sent += (int) $this->alerter->send(
                    severity: AlertSeverity::Sev2,
                    key: "provider:{$name}:success-rate",
                    title: "Provider degraded: {$name}",
                    message: sprintf(
                        '%s fulfilment success is %.1f%% over 24h (%d decided items, floor %.0f%%). Customers are already feeling this.',
                        ucfirst($name),
                        $rate,
                        $decided,
                        self::PROVIDER_SUCCESS_FLOOR,
                    ),
                    context: ['provider' => $name, 'success_rate' => $rate, 'decided' => $decided],
                    runbook: 'docs/runbooks/fulfillment-provider-outage.md',
                );
            }
        }

        return $sent;
    }

    /**
     * Provider float alerts are a state machine, not a threshold check.
     *
     * A plain "below floor" check re-fires every throttle window for as long
     * as the float stays low, which after a day is noise nobody reads; yet
     * the one thing it must never do is go quiet while the float keeps
     * falling. So: crossing a tier (250 → 200 → 150 → 100) alerts at once,
     * every time; sitting inside a tier only earns a reminder twice a day;
     * climbing back above the floor sends one recovery message and clears
     * the state so the next dip starts fresh. The check itself still runs
     * every five minutes.
     *
     * @param  array<string, ?array{balance: float, currency: string}>  $balances
     */
    private function checkBalances(array $balances): int
    {
        $sent = 0;

        foreach ($balances as $name => $balance) {
            if ($balance === null) {
                continue;
            }

            $stateKey = "monitor:balance:{$name}";
            /** @var ?array{tier: float, since: string, reminded_at: string} $state */
            $state = Cache::get($stateKey);
            $amount = $balance['balance'];

            if ($amount > self::BALANCE_FLOOR) {
                if ($state !== null) {
                    Cache::forget($stateKey);
                    $sent += (int) $this->alerter->send(
                        severity: AlertSeverity::Sev2,
                        key: "provider:{$name}:balance:recovered",
                        title: "Provider float recovered: {$name}",
                        message: sprintf(
                            '%s balance is back to %s %.2f (floor %.0f) after %s below it.',
                            ucfirst($name),
                            $balance['currency'],
                            $amount,
                            self::BALANCE_FLOOR,
                            Carbon::parse($state['since'])->diffForHumans(now(), ['syntax' => Carbon::DIFF_ABSOLUTE]),
                        ),
                        context: ['provider' => $name] + $balance,
                    );
                }

                continue;
            }

            $tier = $this->balanceTier($amount);
            $crossedNewTier = $state === null || $tier < $state['tier'];
            $reminderDue = ! $crossedNewTier
                && Carbon::parse($state['reminded_at'])->addHours(self::BALANCE_REMINDER_HOURS)->lte(now());

            if (! $crossedNewTier && ! $reminderDue) {
                continue;
            }

            $since = $state['since'] ?? now()->toIso8601String();
            Cache::put($stateKey, [
                'tier' => $tier,
                'since' => $since,
                'reminded_at' => now()->toIso8601String(),
            ], now()->addDays(30));

            $sent += (int) $this->alerter->send(
                // The bottom tier fans out to mail as well: orders are about to fail.
                severity: $tier <= self::BALANCE_CRITICAL_TIER ? AlertSeverity::Sev1 : AlertSeverity::Sev2,
                key: $crossedNewTier
                    ? "provider:{$name}:balance:{$tier}"
                    : "provider:{$name}:balance:reminder",
                title: $crossedNewTier
                    ? "Provider float low: {$name} under {$balance['currency']} ".number_format($tier)
                    : "Provider float still low: {$name}",
                message: sprintf(
                    '%s balance is %s %.2f (floor %.0f%s). An empty float fails orders exactly like an outage; top up before it runs dry.',
                    ucfirst($name),
                    $balance['currency'],
                    $amount,
                    self::BALANCE_FLOOR,
                    $crossedNewTier ? '' : ', low since '.Carbon::parse($since)->toDayDateTimeString(),
                ),
                context: ['provider' => $name, 'tier' => $tier] + $balance,
                runbook: 'docs/runbooks/fulfillment-provider-outage.md',
            );
        }

        return $sent;
    }

    /**
     * The deepest tier the balance has fallen through; 100 for anything at
     * or below the critical tier.
     */
    private function balanceTier(float $amount): float
    {
        foreach (self::BALANCE_TIERS as $tier) {
            if ($amount <= $tier) {
                $deepest = $tier;
            }
        }

        return $deepest ?? self::BALANCE_CRITICAL_TIER;
    }
}
