<?php

use App\Domain\Fulfillment\Enums\FulfillmentStatus;
use App\Domain\Fulfillment\Support\SweeperPickupCounter;
use App\Domain\Payment\Enums\PaymentStatus;
use App\Domain\Payment\Jobs\ExpireStalePaymentSessionsJob;
use App\Domain\PaymentInsights\Jobs\ExpireStaleRecoveriesJob;
use App\Domain\Shared\Services\HealthCheckService;
use App\Domain\Wallet\Jobs\ReconcilePendingFundingsJob;
use App\Domain\Wallet\Jobs\SyncExchangeRatesJob;
use App\Jobs\FulfillOrderItemJob;
use App\Jobs\PollPendingFulfillmentJob;
use App\Jobs\SyncZenditGiftCardsJob;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;

// Kept as an on-demand command but no longer scheduled: it ran hourly in
// production, adding noise to the scheduler for no operational value.
Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Pull live FX rates from open.er-api.com and push them into exchange_rates.
// Daily cadence is plenty - ECB / interbank rates only refresh once a day.
// SyncExchangeRatesJob still runs hourly as a safety net so any admin manual
// edit to a CurrencyRate row propagates within the hour even without `rates:fetch`.
Schedule::command('rates:fetch')->dailyAt('03:00')->name('rates:fetch-daily')->sentryMonitor('rates-fetch-daily');
Schedule::job(new SyncExchangeRatesJob)->hourly()->sentryMonitor('sync-exchange-rates');
Schedule::job(new ReconcilePendingFundingsJob)->hourly()->sentryMonitor('reconcile-pending-fundings');

// Airalo requires syncing GET /v2/packages at least once every 60 minutes
// or they email warning the catalog may be stale. withoutOverlapping prevents
// a slow sync from doubling up; runInBackground so cron isn't blocked while
// the API call is paginating. Logs go to storage/logs/laravel.log.
Schedule::command('airalo:sync-esims')
    ->hourly()
    ->withoutOverlapping(60)
    ->runInBackground()
    ->name('airalo:sync-esims-hourly');

// Zendit catalog syncs - keep gift cards, top-ups and bills fresh without
// any manual intervention. Cadence is every 6h (Zendit catalog changes
// less often than Airalo eSIM packages). Each command is idempotent
// (updateOrCreate by SKU) so re-running just refreshes data, no duplicates.
Schedule::command('zendit:sync-giftcards')
    ->everySixHours()
    ->withoutOverlapping(120)
    ->runInBackground()
    ->name('zendit:sync-giftcards-6h');

Schedule::command('topups:sync')
    ->everySixHours()
    ->withoutOverlapping(120)
    ->runInBackground()
    ->name('zendit:sync-topups-6h');

// Split bills out of the gift-cards catalog after the giftcards sync runs.
// Fires 15 minutes past the hour so it lands AFTER the 6h sync above
// completes for that slot (gift cards sync typically finishes in <10 min).
Schedule::command('catalog:split-bill-payments')
    ->cron('15 */6 * * *')
    ->withoutOverlapping()
    ->runInBackground()
    ->name('catalog:split-bills-6h');

// Operator/biller logos are missing from Zendit's /topups/offers + bills
// payloads, so we backfill from a separate logo source. Weekly is fine -
// brand logos don't change often.
Schedule::command('topups:sync-logos')
    ->weekly()
    ->sundays()
    ->at('04:00')
    ->withoutOverlapping()
    ->runInBackground()
    ->name('topups:sync-logos-weekly');

// (Removed auto-retry for old notifications)

// Sweep PaymentSessions past their 15-minute TTL and cancel the linked Order /
// WalletFunding, so half-finished checkout attempts don't sit Pending on the
// customer's dashboard forever. Five-minute cadence — the dead state shows for
// at most ~5 min between cron ticks.
Schedule::job(new ExpireStalePaymentSessionsJob)->everyFiveMinutes()->sentryMonitor('expire-stale-payment-sessions');

// Sweep pending transaction recoveries whose 24h links have lapsed into the
// terminal `abandoned` state, so recovery analytics stay truthful. Hourly is
// plenty — the read paths already refuse expired tokens.
Schedule::job(new ExpireStaleRecoveriesJob)->hourly()->sentryMonitor('expire-stale-recoveries');

// Same job, on demand. Useful after a debugging session left dozens of Pending
// orders, or any time you want to flush stale sessions without waiting for cron.
Artisan::command('payments:expire-stale', function () {
    $this->info('Sweeping stale payment sessions...');
    dispatch_sync(new ExpireStalePaymentSessionsJob);
    $this->info('Done.');
})->purpose('Expire payment sessions past their TTL and cancel their orders/fundings');

Artisan::command('zendit:sync-giftcards', function () {
    $this->info('Setting default queue connection to sync...');
    config(['queue.default' => 'sync']);

    $this->info('Dispatching SyncZenditGiftCardsJob synchronously...');
    dispatch(new SyncZenditGiftCardsJob);

    $this->info('Zendit Gift Card and Brand Sync completed successfully!');
})->purpose('Sync Gift Cards and Brand Assets from Zendit API');

// Bug #4 fix: Fulfillment fallback sweeper.
//
// For non-wallet gateways (card, bank, crypto), fulfillment is triggered by the
// Flutterwave/NowPayments webhook → VerifyPaymentJob chain. If the webhook is
// delayed, dropped, or the queue worker was offline at the time of payment, all
// order items remain at NotStarted indefinitely — no refund fires, no voucher is
// delivered, and the customer's money appears lost.
//
// This sweeper runs every 5 minutes and re-dispatches FulfillOrderItemJob for
// any paid order whose items are still NotStarted after a 10-minute grace window.
Schedule::call(function () {
    $cutoff = now()->subMinutes(10);
    $pickups = 0;

    Order::query()
        ->where('payment_status', PaymentStatus::Paid)
        ->where('fulfillment_status', FulfillmentStatus::NotStarted)
        ->where('placed_at', '<=', $cutoff)
        ->with('items')
        ->chunkById(100, function ($orphanedOrders) use (&$pickups) {
            foreach ($orphanedOrders as $order) {
                foreach ($order->items as $item) {
                    if ($item->fulfillment_status === FulfillmentStatus::NotStarted) {
                        Log::warning("Fulfillment sweeper: re-dispatching FulfillOrderItemJob for orphaned item {$item->id} on order {$order->order_number}");
                        FulfillOrderItemJob::dispatch($item);
                        $pickups++;
                    }
                }
            }
        });

    // Every pickup is a webhook that went missing; Monitoring and
    // monitor:operations read these buckets.
    SweeperPickupCounter::record($pickups);
})->everyFiveMinutes()->name('fulfillment:rescue-orphaned-orders')->sentryMonitor('fulfillment-rescue-orphaned-orders');

// Pending-fulfillment poll sweeper.
//
// When a provider returns Processing (Zendit top-ups + eSIMs, Airalo) the
// fulfillment job queues a PollPendingFulfillmentJob with a 10s delay to
// check status. On `QUEUE_CONNECTION=sync` that delay is moot — the job
// runs inline and then never retries, so items stick at `processing`
// forever. This sweeper kicks them every minute, with a 30-minute upper
// bound so a stuck item doesn't churn forever (the poll job itself stops
// re-releasing after 120 tries / 20 minutes).
Schedule::call(function () {
    $cutoff = now()->subMinutes(30);

    OrderItem::query()
        ->whereIn('fulfillment_status', [FulfillmentStatus::Processing, FulfillmentStatus::Delayed])
        ->where('updated_at', '>=', $cutoff)
        ->chunkById(100, function ($items) {
            $items->each(function (OrderItem $item) {
                dispatch_sync(new PollPendingFulfillmentJob($item));
            });
        });
})->everyMinute()->name('fulfillment:poll-pending')->withoutOverlapping();

// Horizon metrics snapshot (P0-5).
//
// Without this the Horizon dashboard's throughput and runtime graphs are empty,
// which is why they have never shown anything: the panels exist, the data was
// never collected. Five minutes is Horizon's documented cadence.
Schedule::command('horizon:snapshot')
    ->everyFiveMinutes()
    ->name('horizon:snapshot-5m');

// Scheduler heartbeat (P0-3).
//
// A dead crontab silently stops backups, reconciliation, sweepers and catalog
// syncs all at once, and nothing else in the system notices — the app keeps
// serving pages perfectly while its entire nervous system is switched off.
// This stamp is what /health reads to prove cron is still ticking.
Schedule::call(function () {
    Cache::put(HealthCheckService::SCHEDULER_HEARTBEAT_KEY, now(), now()->addDay());
})->everyMinute()->name('ops:scheduler-heartbeat');

// Disaster recovery — nightly encrypted, off-site backups.
//
// Ordering is deliberate: prune first, then dump at 01:30 so the fresh archive is
// never the one a failing cleanup deletes, and both land before the 02:00
// reconciliation window so the archive captures a database that the integrity
// fleet then verifies. runInBackground keeps a multi-minute dump from stalling
// the every-minute fulfillment and campaign sweepers that share this scheduler.
Schedule::command('backup:clean')
    ->dailyAt('01:15')
    ->withoutOverlapping(60)
    ->runInBackground()
    ->name('backup:clean-nightly')
    ->sentryMonitor('backup-clean-nightly');

Schedule::command('backup:run')
    ->dailyAt('01:30')
    ->withoutOverlapping(120)
    ->runInBackground()
    ->name('backup:run-nightly')
    ->sentryMonitor('backup-run-nightly');

// Independent verification that a recent, correctly-sized, encrypted, off-site
// archive actually exists. `backup:run` reporting success is not the same claim:
// this catches a backup that has been quietly writing nowhere useful. Runs before
// the morning sweep so a bad night is known before the day starts.
Schedule::command('backup:health')
    ->dailyAt('08:00')
    ->name('backup:health-daily')
    ->sentryMonitor('backup-health-daily');

// The CTO alert fleet: every five minutes, evaluate the same signals the
// Management → Monitoring page shows (health components, disk, reconciliation
// breaks, stuck orders, failed-job spikes, queue waits, sweeper pickups, FX
// staleness, provider degradation and float) and push anything unhealthy to
// Telegram. The alerter's throttle window keeps a standing problem from
// re-sending on every run.
Schedule::command('monitor:operations')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->runInBackground()
    ->name('monitor:operations-5m')
    ->sentryMonitor('monitor-operations');

// Financial integrity fleet (P0-4).
//
// These commands all existed and were all manual-only, which meant drift was
// detected when somebody remembered rather than when it happened. Reconciliation
// as a continuously-running product is the practice that separates a fintech
// from an app that moves money; scheduling them is what makes it real.
//
// Each exits non-zero on a violation, alerts on its own severity, and carries a
// Sentry cron monitor so a *missed run* is as visible as a failed one — a check
// that silently stops running looks exactly like a check that keeps passing.
//
// 02:30 places the ledger verification after the 01:30 backup (so the archive
// captures the state being verified) and after the 02:00 wallet reconciliation.
Schedule::command('ledger:verify')
    ->dailyAt('02:30')
    ->withoutOverlapping()
    ->name('ledger:verify-nightly')
    ->sentryMonitor('ledger-verify-nightly');

// Margin protection. Runs before the morning sweep so any below-cost sale from
// overnight is on the desk before the day starts.
Schedule::command('pricing:audit-below-cost')
    ->dailyAt('06:00')
    ->withoutOverlapping()
    ->runInBackground()
    ->name('pricing:audit-below-cost-daily')
    ->sentryMonitor('pricing-audit-below-cost-daily');

// Platform self-validation suites. Weekly rather than daily: they assert wiring
// and configuration, which only change when someone deploys.
Schedule::command('payments:validate')
    ->weeklyOn(1, '05:00')
    ->withoutOverlapping()
    ->runInBackground()
    ->name('payments:validate-weekly')
    ->sentryMonitor('payments-validate-weekly');

Schedule::command('communication:validate')
    ->weeklyOn(1, '05:15')
    ->withoutOverlapping()
    ->runInBackground()
    ->name('communication:validate-weekly')
    ->sentryMonitor('communication-validate-weekly');

// Enterprise Reconciliation Engine Scheduling
Schedule::command('reconcile:wallet-balances')->dailyAt('02:00')->sentryMonitor('reconcile-wallet-balances');
Schedule::command('reconcile:orphaned-sessions')->hourly()->sentryMonitor('reconcile-orphaned-sessions');

// Crypto safety net: re-verifies pending NOWPayments attempts against the gateway
// so dropped IPNs still confirm. This is not optional cover — NOWPayments stops
// sending callbacks once a payment expires while still crediting deposits that
// arrive afterwards, so polling is the only way those are ever seen.
Schedule::command('reconcile:nowpayments --hours=48')
    ->hourly()
    ->withoutOverlapping()
    ->sentryMonitor('reconcile-nowpayments');

// Surfaces crypto payments that settled short or over. Exits non-zero when the
// queue is non-empty so the monitor trips and a human actually looks at it —
// these have no automatic resolution and used to accumulate silently.
Schedule::command('crypto:settlements --days=7')
    ->dailyAt('07:30')
    ->withoutOverlapping()
    ->sentryMonitor('crypto-settlement-audit');

// Per-coin minimums float with network fee conditions — USDT on Tron has sat as
// high as ~$12 while USDC on Base clears at ~$0.02 — so a stale table would let
// checkout offer a coin the gateway then rejects. Six-hourly keeps it honest
// without hammering the rate-limited min-amount endpoint.
Schedule::command('crypto:sync-currencies')
    ->everySixHours()
    ->withoutOverlapping()
    ->sentryMonitor('crypto-sync-currencies');

// Enterprise Provider Monitoring
Schedule::command('zendit:check-balance --threshold=250')->hourly()->sentryMonitor('zendit-check-balance');

// Check for stuck bank payouts and fallback to manual processing
Schedule::command('payouts:check-stuck')
    ->everyFifteenMinutes()
    ->withoutOverlapping()
    ->name('payouts:check-stuck-15m');

// Dispatch scheduled push notification campaigns
Schedule::command('campaigns:dispatch')
    ->everyMinute()
    ->withoutOverlapping()
    ->runInBackground()
    ->name('campaigns:dispatch-1m');
