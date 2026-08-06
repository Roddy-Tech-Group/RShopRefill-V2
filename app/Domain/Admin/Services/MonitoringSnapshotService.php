<?php

namespace App\Domain\Admin\Services;

use App\Domain\Fulfillment\Support\SweeperPickupCounter;
use App\Domain\Ledger\Services\LedgerIntegrityService;
use App\Domain\Reconciliation\Models\ReconciliationReport;
use App\Domain\Shared\Services\BackupHealthService;
use App\Domain\Shared\Services\CircuitBreaker;
use App\Domain\Shared\Services\HealthCheckService;
use App\Support\FeatureFlag;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Laravel\Horizon\WaitTimeCalculator;

/**
 * One snapshot for the Management → Monitoring page: system components,
 * money-integrity results, queue health, FX freshness, and fulfillment
 * provider vitals (Zendit / Airalo — payment gateways live on the Payment
 * Insights page, deliberately not duplicated here).
 *
 * Expensive probes (ledger verification, backup listing, the Zendit balance
 * HTTP call) are cached for a few minutes so the page can poll every minute
 * without adding load; cheap DB counts run live.
 */
class MonitoringSnapshotService
{
    private const FULFILLMENT_PROVIDERS = ['zendit', 'airalo'];

    /** An order paid this long ago but still unfulfilled counts as stuck. */
    private const STUCK_AFTER_MINUTES = 15;

    public function __construct(
        private HealthCheckService $health,
        private BackupHealthService $backupHealth,
        private LedgerIntegrityService $ledgerIntegrity,
    ) {}

    /**
     * @return array{
     *     health: array<string, mixed>,
     *     backup: array<string, mixed>,
     *     ledger: array{status: string, violations: array<int, mixed>, checks: array<string, int>},
     *     reconciliation: ?array{status: string, anomalies: int, completed_at: ?string},
     *     jobs: array{failed_24h: int, by_queue: array<string, int>},
     *     queues: array{waits: array<string, float>, worst: ?array{queue: string, seconds: float}},
     *     sweeper: array{pickups_24h: int, pickups_1h: int},
     *     orders: array{stuck: int, requires_attention: int},
     *     fx: array{active_rates: int, newest_age_hours: ?float},
     *     providers: array<string, array<string, mixed>>,
     *     zendit_balance: ?array{balance: float, currency: string},
     *     airalo_balance: ?array{balance: float, currency: string},
     *     disk: ?array{free_gb: float, total_gb: float, used_pct: float},
     *     generated_at: string
     * }
     */
    public function snapshot(): array
    {
        return [
            'health' => $this->health->run(),
            'backup' => Cache::remember('management:monitoring:backup', 300, fn () => $this->backupHealth->status()),
            'ledger' => Cache::remember('management:monitoring:ledger', 300, fn () => $this->ledgerIntegrity->verifyLedgerIntegrity()),
            'reconciliation' => $this->latestReconciliation(),
            'jobs' => $this->failedJobs(),
            'queues' => $this->queueWaits(),
            'sweeper' => [
                'pickups_24h' => SweeperPickupCounter::lastHours(24),
                'pickups_1h' => SweeperPickupCounter::lastHours(1),
            ],
            'orders' => $this->orderBacklog(),
            'fx' => $this->fxFreshness(),
            'providers' => Cache::remember('management:monitoring:providers', 60, fn () => $this->fulfillmentProviders()),
            // Kill-switch state next to provider health, so "degraded AND
            // disabled at 14:32" reads in one glance. Uncached: after flipping
            // a switch mid-incident the page must tell the truth immediately.
            'gateway_flags' => $this->gatewayFlags(),
            'zendit_balance' => Cache::remember('management:monitoring:zendit-balance', 300, fn () => $this->zenditBalance()),
            'airalo_balance' => Cache::remember('management:monitoring:airalo-balance', 300, fn () => $this->airaloBalance()),
            'disk' => $this->diskSpace(),
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Latest wallet-balance reconciliation run (written nightly at 02:00 by
     * reconcile:wallet-balances).
     *
     * @return ?array{status: string, anomalies: int, completed_at: ?string}
     */
    private function latestReconciliation(): ?array
    {
        $report = ReconciliationReport::query()
            ->where('type', 'wallet_balance')
            ->latest('id')
            ->first();

        if (! $report) {
            return null;
        }

        return [
            'status' => (string) $report->status,
            'anomalies' => is_array($report->anomalies_found) ? count($report->anomalies_found) : 0,
            'completed_at' => $report->completed_at?->toDateTimeString(),
        ];
    }

    /** @return array{failed_24h: int, by_queue: array<string, int>} */
    private function failedJobs(): array
    {
        $rows = DB::table('failed_jobs')
            ->where('failed_at', '>=', now()->subDay())
            ->select('queue', DB::raw('COUNT(*) as failures'))
            ->groupBy('queue')
            ->orderByDesc('failures')
            ->get();

        return [
            'failed_24h' => (int) $rows->sum('failures'),
            'by_queue' => $rows->pluck('failures', 'queue')->map(fn ($count) => (int) $count)->all(),
        ];
    }

    /**
     * Per-queue wait estimates from Horizon: how long the oldest pending job
     * on each queue has been standing in line. Failed jobs are work that
     * died; a long wait is work that is quietly late — a customer's eSIM
     * arriving in twenty minutes instead of ten seconds, with zero errors
     * anywhere. Empty when Horizon or Redis is unreachable (the System row
     * reports that outage separately).
     *
     * @return array{waits: array<string, float>, worst: ?array{queue: string, seconds: float}}
     */
    private function queueWaits(): array
    {
        try {
            $raw = app(WaitTimeCalculator::class)->calculate();
        } catch (\Throwable) {
            return ['waits' => [], 'worst' => null];
        }

        $waits = [];
        foreach ($raw as $queue => $seconds) {
            // Horizon keys as "connection:queue"; the connection is noise here.
            $waits[str_contains((string) $queue, ':') ? substr((string) $queue, strpos((string) $queue, ':') + 1) : (string) $queue] = (float) $seconds;
        }

        arsort($waits);

        $worstQueue = array_key_first($waits);

        return [
            'waits' => $waits,
            'worst' => $worstQueue === null ? null : ['queue' => $worstQueue, 'seconds' => $waits[$worstQueue]],
        ];
    }

    /**
     * Money-in-limbo counts: paid-but-unfulfilled orders older than the stuck
     * threshold (each one is a fulfillment dispatch or webhook that went
     * missing) and orders flagged requires_attention.
     *
     * @return array{stuck: int, requires_attention: int}
     */
    private function orderBacklog(): array
    {
        $stuck = DB::table('orders')
            ->whereIn('payment_status', ['paid', 'reserved'])
            ->whereIn('fulfillment_status', ['not_started', 'queued'])
            ->whereNotIn('order_status', ['completed', 'cancelled', 'failed'])
            ->where('placed_at', '<=', now()->subMinutes(self::STUCK_AFTER_MINUTES))
            ->count();

        $attention = DB::table('orders')
            ->where('order_status', 'requires_attention')
            ->count();

        return ['stuck' => $stuck, 'requires_attention' => $attention];
    }

    /**
     * Exchange-rate freshness — stale FX misprices the whole catalog at once.
     *
     * @return array{active_rates: int, newest_age_hours: ?float}
     */
    /**
     * Payment gateway kill-switch state, from the same canonical map the
     * checkout tiles and server guards read.
     *
     * @return array<string, bool> gateway => accepting new payments
     */
    private function gatewayFlags(): array
    {
        $flags = [];

        foreach (array_keys(FeatureFlag::GATEWAY_FLAGS) as $gateway) {
            $flags[$gateway] = FeatureFlag::gatewayEnabled($gateway);
        }

        return $flags;
    }

    private function fxFreshness(): array
    {
        $newest = DB::table('currency_rates')->where('is_active', true)->max('updated_at');

        return [
            'active_rates' => (int) DB::table('currency_rates')->where('is_active', true)->count(),
            // Diffed past → now so Carbon 3's signed diffs stay positive.
            'newest_age_hours' => $newest
                ? round(Carbon::parse($newest)->diffInMinutes(now()) / 60, 1)
                : null,
        ];
    }

    /**
     * Per-provider fulfillment vitals over 24h and 7d. Attempt volume and
     * errors come from fulfillment_logs (provider_name there is always
     * exactly zendit/airalo); the success rate comes from the terminal state
     * of the touched order items, because Zendit logs SUCCESS on accepted-but-
     * pending transactions and a raw log ratio would flatter it.
     *
     * @return array<string, array<string, mixed>>
     */
    private function fulfillmentProviders(): array
    {
        $providers = [];

        foreach (self::FULFILLMENT_PROVIDERS as $provider) {
            $windows = [];
            foreach (['24h' => now()->subDay(), '7d' => now()->subDays(7)] as $key => $since) {
                $logs = DB::table('fulfillment_logs')
                    ->where('provider_name', $provider)
                    ->where('processed_at', '>=', $since);

                $attempts = (clone $logs)->count();
                $errors = (clone $logs)->whereIn('status', ['FAILED', 'EXCEPTION'])->count();

                $itemIds = (clone $logs)->distinct()->pluck('order_item_id');
                $terminal = ['fulfilled' => 0, 'failed' => 0, 'in_flight' => 0];
                if ($itemIds->isNotEmpty()) {
                    $states = DB::table('order_items')
                        ->whereIn('id', $itemIds)
                        ->select('fulfillment_status', DB::raw('COUNT(*) as items'))
                        ->groupBy('fulfillment_status')
                        ->pluck('items', 'fulfillment_status');

                    $terminal['fulfilled'] = (int) ($states['fulfilled'] ?? 0) + (int) ($states['partially_fulfilled'] ?? 0);
                    $terminal['failed'] = (int) ($states['failed'] ?? 0);
                    $terminal['in_flight'] = max(0, (int) $states->sum() - $terminal['fulfilled'] - $terminal['failed']);
                }

                $decided = $terminal['fulfilled'] + $terminal['failed'];

                $windows[$key] = [
                    'attempts' => $attempts,
                    'errors' => $errors,
                    'fulfilled' => $terminal['fulfilled'],
                    'failed' => $terminal['failed'],
                    'in_flight' => $terminal['in_flight'],
                    'success_rate' => $decided > 0 ? round(($terminal['fulfilled'] / $decided) * 100, 1) : null,
                ];
            }

            $lastActivity = DB::table('fulfillment_logs')
                ->where('provider_name', $provider)
                ->max('processed_at');

            $providers[$provider] = $windows + [
                'last_activity_at' => $lastActivity ? (string) $lastActivity : null,
                'circuit_breaker_open' => $provider === 'zendit'
                    ? app(CircuitBreaker::class, ['serviceName' => 'zendit_api'])->isOpen()
                    : null,
            ];
        }

        return $providers;
    }

    /**
     * Live Zendit float, mirroring zendit:check-balance's request and parsing
     * (including its TLS relaxation — the command established that behaviour
     * against this API). Returns null when unconfigured, mocked, or failing —
     * the tile shows "unavailable" rather than a made-up number.
     *
     * @return ?array{balance: float, currency: string}
     */
    private function zenditBalance(): ?array
    {
        $apiKey = (string) config('services.zendit.api_key');
        $baseUrl = (string) config('services.zendit.base_url', 'https://api.zendit.io/v1');

        if ($apiKey === '' || str_contains($apiKey, 'MOCK')) {
            return null;
        }

        try {
            $response = Http::withoutVerifying()
                ->timeout(8)
                ->withToken($apiKey)
                ->acceptJson()
                ->get("{$baseUrl}/balance");

            if ($response->failed()) {
                return null;
            }

            $data = $response->json();
            $divisor = (float) ($data['currencyDivisor'] ?? 100);
            if ($divisor <= 0) {
                $divisor = 100;
            }

            return [
                'balance' => round(((float) ($data['availableBalance'] ?? 0)) / $divisor, 2),
                'currency' => (string) ($data['currency'] ?? 'USD'),
            ];
        } catch (\Throwable $e) {
            Log::warning('Monitoring page could not fetch Zendit balance', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Airalo credit balance via GET /v2/balance (Partner API, Bearer token —
     * reuses the same cached OAuth token as catalog sync and fulfillment).
     * Airalo returns data.balances with name + availableBalance{amount,
     * currency}; both single-account and multi-account shapes are handled.
     * Null when unconfigured or failing, so the tile says "unavailable"
     * rather than inventing a number.
     *
     * @return ?array{balance: float, currency: string}
     */
    private function airaloBalance(): ?array
    {
        $baseUrl = (string) config('services.airalo.base_url', 'https://partners-api.airalo.com/v2');
        $clientId = (string) config('services.airalo.client_id');
        $clientSecret = (string) config('services.airalo.client_secret');

        if ($clientId === '' || $clientSecret === '' || str_contains($clientId, 'MOCK')) {
            return null;
        }

        try {
            $token = Cache::remember('airalo_access_token', now()->addMinutes(45), function () use ($baseUrl, $clientId, $clientSecret) {
                $response = Http::withoutVerifying()
                    ->timeout(8)
                    ->asForm()
                    ->post("{$baseUrl}/token", [
                        'client_id' => $clientId,
                        'client_secret' => $clientSecret,
                        'grant_type' => 'client_credentials',
                    ]);

                if ($response->failed()) {
                    throw new \RuntimeException('Failed to authenticate with Airalo API');
                }

                return $response->json('data.access_token') ?? $response->json('access_token');
            });

            $response = Http::withoutVerifying()
                ->timeout(8)
                ->withToken((string) $token)
                ->acceptJson()
                ->get("{$baseUrl}/balance");

            if ($response->failed()) {
                return null;
            }

            $balances = $response->json('data.balances');
            if (! is_array($balances) || $balances === []) {
                return null;
            }

            // Single account arrives as an object, multiple as a list; take
            // the first account either way.
            $account = array_is_list($balances) ? $balances[0] : $balances;
            $available = $account['availableBalance'] ?? null;
            if (! is_array($available)) {
                return null;
            }

            return [
                'balance' => round((float) ($available['amount'] ?? 0), 2),
                'currency' => (string) ($available['currency'] ?? 'USD'),
            ];
        } catch (\Throwable $e) {
            Log::warning('Monitoring page could not fetch Airalo balance', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Disk usage for the partition the application lives on. Logs, backups
     * and MySQL share it in production; a full disk stops all three at once,
     * usually silently.
     *
     * @return ?array{free_gb: float, total_gb: float, used_pct: float}
     */
    private function diskSpace(): ?array
    {
        try {
            $free = @disk_free_space(base_path());
            $total = @disk_total_space(base_path());

            if ($free === false || $total === false || $total <= 0) {
                return null;
            }

            return [
                'free_gb' => round($free / 1024 ** 3, 1),
                'total_gb' => round($total / 1024 ** 3, 1),
                'used_pct' => round((($total - $free) / $total) * 100, 1),
            ];
        } catch (\Throwable) {
            return null;
        }
    }
}
