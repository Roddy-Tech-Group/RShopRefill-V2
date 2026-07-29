<?php

namespace App\Domain\Shared\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Throwable;

/**
 * What an external uptime probe asks the platform about itself.
 *
 * Deliberately cheap enough to poll every 60 seconds from outside the VPS, and
 * deliberately honest: a process that can serve HTML while its database is
 * unreachable is not up, it is merely running. Checking only that PHP responds
 * is how outages get discovered by customers.
 *
 * Returns no secrets, requires no auth, and never throws — a health endpoint
 * that 500s tells the prober nothing about *what* is wrong.
 */
class HealthCheckService
{
    /**
     * Cache key holding the last time the scheduler ran. Written every minute by
     * the heartbeat task in routes/console.php.
     */
    public const SCHEDULER_HEARTBEAT_KEY = 'ops:scheduler:last-run';

    /**
     * A dead crontab silently kills reconciliation, backups, sweeps and syncs at
     * once, and nothing else in the system notices. Five minutes of slack absorbs
     * a slow tick without hiding a stopped scheduler.
     */
    private const SCHEDULER_STALE_AFTER_MINUTES = 5;

    /**
     * @return array{
     *     status: string,
     *     checked_at: string,
     *     environment: string,
     *     components: array<string, array{status: string, message: string|null, critical: bool}>
     * }
     */
    public function run(): array
    {
        $components = [
            'database' => $this->check(true, fn (): ?string => $this->checkDatabase()),
            'cache' => $this->check(true, fn (): ?string => $this->checkCache()),
            'redis' => $this->check(false, fn (): ?string => $this->checkRedis()),
            'queue' => $this->check(false, fn (): ?string => $this->checkHorizon()),
            'scheduler' => $this->check(false, fn (): ?string => $this->checkScheduler()),
            'alerting' => $this->check(false, fn (): ?string => $this->checkAlerting()),
        ];

        // Only a critical component failing means "the site is down". A stalled
        // scheduler is serious, but the storefront still takes orders, and paging
        // an uptime probe for it would train everyone to ignore the probe.
        $healthy = collect($components)
            ->every(fn (array $component): bool => $component['status'] === 'ok' || ! $component['critical']);

        return [
            'status' => $healthy ? 'ok' : 'fail',
            'checked_at' => now()->toIso8601String(),
            'environment' => (string) config('app.env'),
            'components' => $components,
        ];
    }

    /**
     * @param  callable(): ?string  $probe  Returns null when healthy, else the reason.
     * @return array{status: string, message: string|null, critical: bool}
     */
    private function check(bool $critical, callable $probe): array
    {
        try {
            $failure = $probe();
        } catch (Throwable $exception) {
            $failure = $exception->getMessage();
        }

        return [
            'status' => $failure === null ? 'ok' : 'fail',
            'message' => $failure,
            'critical' => $critical,
        ];
    }

    private function checkDatabase(): ?string
    {
        // A trivial round-trip: proves the connection and credentials work
        // without loading a row that might not exist.
        DB::connection()->select('select 1 as ok');

        return null;
    }

    private function checkCache(): ?string
    {
        $key = 'ops:health:cache-probe';

        Cache::put($key, 'ok', 10);

        return Cache::get($key) === 'ok' ? null : 'Cache write succeeded but read returned nothing.';
    }

    private function checkRedis(): ?string
    {
        if (! $this->usesRedis()) {
            return null;
        }

        Redis::connection()->ping();

        return null;
    }

    private function checkHorizon(): ?string
    {
        if (! $this->usesRedis() || ! class_exists(MasterSupervisorRepository::class)) {
            return null;
        }

        $masters = app(MasterSupervisorRepository::class)->all();

        // Horizon down means every queue in the company has stopped: fulfillment,
        // webhooks, payment verification and email all silently stop moving.
        return $masters === [] ? 'No Horizon master supervisor is running.' : null;
    }

    private function checkScheduler(): ?string
    {
        $lastRun = Cache::get(self::SCHEDULER_HEARTBEAT_KEY);

        if ($lastRun === null) {
            return 'No scheduler heartbeat recorded yet.';
        }

        $minutes = now()->diffInMinutes($lastRun, absolute: true);

        return $minutes > self::SCHEDULER_STALE_AFTER_MINUTES
            ? "Scheduler last ran {$minutes} minutes ago; cron may be dead."
            : null;
    }

    private function checkAlerting(): ?string
    {
        // Surfaced because an unconfigured alert path is invisible by nature:
        // everything looks fine right up until the moment nobody is told.
        return app(OperationalAlerter::class)->canReachAHumanUrgently()
            ? null
            : 'No SEV-1 alert channel is configured; out-of-hours failures would reach nobody.';
    }

    private function usesRedis(): bool
    {
        return config('queue.default') === 'redis'
            || config('cache.default') === 'redis'
            || config('session.driver') === 'redis';
    }
}
