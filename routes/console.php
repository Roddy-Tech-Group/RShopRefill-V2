<?php

use App\Domain\Catalog\Enums\CatalogFeed;
use App\Domain\Catalog\Enums\CatalogSyncRunStatus;
use App\Domain\Catalog\Services\CatalogSyncRunService;
use App\Domain\Fulfillment\Enums\FulfillmentStatus;
use App\Domain\Fulfillment\Support\SweeperPickupCounter;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Payment\Enums\PaymentStatus;
use App\Domain\Payment\Jobs\ExpireStalePaymentSessionsJob;
use App\Domain\PaymentInsights\Jobs\ExpireStaleRecoveriesJob;
use App\Domain\PaymentInsights\Jobs\SendRecoveryRemindersJob;
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

// Fraud/risk engine housekeeping. The daily report logs the headline metrics and
// pings the review-queue owners about any SLA-breached reviews; the prune keeps
// the assessment store inside the retention window.
Schedule::command('risk:report')->dailyAt('08:00')->name('risk:report-daily');
Schedule::command('risk:prune')->dailyAt('02:30')->name('risk:prune-daily');

// The mobile API's short-lived bookkeeping. Idempotency keys stop mattering
// after their 24-hour replay window and are the fastest-growing table the API
// owns; dead refresh tokens are kept a month so a support question about a
// surprise sign-out can still be answered.
Schedule::command('api:prune')->dailyAt('02:15')->withoutOverlapping()->name('api:prune-daily');

// Audit retention: archive-then-delete rows past the two-year window
// (config/audit.php). Monthly is plenty - the window moves a day at a time.
Schedule::command('audit:prune')->monthlyOn(1, '02:45')->name('audit:prune-monthly');

// Account closures: once the grace period in config/retention.php has run out,
// anonymise the customer's personal details while keeping the financial rows.
Schedule::command('retention:close-accounts')->dailyAt('03:15')->name('retention:close-accounts-daily');
Schedule::command('retention:enforce')->dailyAt('03:30')->name('retention:enforce-daily');
Schedule::job(new SyncExchangeRatesJob)->hourly()->sentryMonitor('sync-exchange-rates');
Schedule::job(new ReconcilePendingFundingsJob)->hourly()->sentryMonitor('reconcile-pending-fundings');

// Airalo requires syncing GET /v2/packages at least once every 60 minutes
// or they email warning the catalog may be stale. withoutOverlapping prevents
// a slow sync from doubling up; runInBackground so cron isn't blocked while
// the API call is paginating. Command output is appended to
// storage/logs/airalo-sync.log; without that, a background run only
// ever surfaces as "exited with code 1".
Schedule::command('airalo:sync-esims')
    ->hourlyAt(17)
    ->withoutOverlapping(60)
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/airalo-sync.log'))
    ->name('airalo:sync-esims-hourly');

// Manage eSIMs: refresh status and data for the 200 least recently checked
// eSIMs every half hour (Airalo caches usage for 20 minutes, so faster buys
// nothing). About a minute per run at Airalo's 5 calls a second.
Schedule::command('esims:sync-status --limit=200')
    ->everyThirtyMinutes()
    ->withoutOverlapping(30)
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/esim-status-sync.log'))
    ->name('esims:sync-status');

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

// Re-pin topup pricing after each sync slot: a brand-new Nigerian operator
// lands on the 8% category default until pinned at its deliberate 2%, so the
// pinning command re-runs 30 minutes past the sync (idempotent - existing
// rules are updated in place, never duplicated).
Schedule::command('pricing:apply-topup-rules')
    ->cron('30 */6 * * *')
    ->withoutOverlapping()
    ->runInBackground()
    ->name('pricing:apply-topup-rules-6h');

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

// Second recovery touch. Hourly is the right resolution: the reminder is due
// ~20h after the failure, so precision beyond an hour buys nothing and only
// risks emailing someone at 3am in their own timezone.
Schedule::job(new SendRecoveryRemindersJob)->hourly()->sentryMonitor('send-recovery-reminders');

// Payment failures that need a human now, not at the next dashboard visit:
// platform-side faults (a rail we asked for that the gateway refuses), one
// customer failing repeatedly, and a reason code spiking against its own
// baseline. SEV-2, so Telegram only; the alerter's throttle keeps a standing
// fault to one message an hour.
Schedule::command('insights:alert-failures')
    ->everyTenMinutes()
    ->withoutOverlapping()
    ->runInBackground()
    ->name('insights:alert-failures-10m')
    ->sentryMonitor('insights-alert-failures');

// Gateway words no rule reads yet, once a day and only when there are any:
// each text with its count and customers, so rules are added in a batch
// from one message rather than one hourly page per text.
Schedule::command('insights:unclassified-digest')
    ->dailyAt('08:30')
    ->name('insights:unclassified-digest')
    ->sentryMonitor('insights-unclassified-digest');

// Same job, on demand. Useful after a debugging session left dozens of Pending
// orders, or any time you want to flush stale sessions without waiting for cron.
Artisan::command('payments:expire-stale', function () {
    $this->info('Sweeping stale payment sessions...');
    dispatch_sync(new ExpireStalePaymentSessionsJob);
    $this->info('Done.');
})->purpose('Expire payment sessions past their TTL and cancel their orders/fundings');

Artisan::command('zendit:sync-giftcards {--force-cleanup : Hide offers Zendit no longer lists even when the shrink guards would hold back}', function (CatalogSyncRunService $runs) {
    $this->info('Setting default queue connection to sync...');
    config(['queue.default' => 'sync']);

    $run = $runs->start(CatalogFeed::ZenditVouchers, (bool) $this->option('force-cleanup'));

    $this->info('Dispatching SyncZenditGiftCardsJob synchronously...');
    dispatch(new SyncZenditGiftCardsJob(1, $run->id));

    $run->refresh();
    $this->info($run->summary());

    return $run->status === CatalogSyncRunStatus::Completed ? 0 : 1;
})->purpose('Sync Gift Cards and Brand Assets from Zendit API, then hide offers Zendit no longer lists');

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

// The slow lane, for everything the fast one drops.
//
// The sweeper above only looks at items touched in the last thirty minutes, so
// an item the provider accepted and then sat on fell out of the net and was
// never polled again: paid, undelivered, and retried by nothing. A real MTN
// Cameroon data top-up did exactly that, and only surfaced because the person
// who bought it happened to own the shop.
//
// So the cutoff stops being an exit. Past thirty minutes an item keeps being
// checked, just far less often, and after twelve hours it is handed to a human
// instead of being dropped: requires_attention is a state the admin panel and
// the operations monitor both already watch, which is exactly what abandoning
// it silently was not.
Schedule::call(function () {
    $handOver = now()->subHours(12);

    OrderItem::query()
        ->whereIn('fulfillment_status', [FulfillmentStatus::Processing, FulfillmentStatus::Delayed])
        ->where('updated_at', '<', now()->subMinutes(30))
        ->chunkById(100, function ($items) use ($handOver) {
            $items->each(function (OrderItem $item) use ($handOver) {
                // One more ask: the provider may have resolved it since.
                dispatch_sync(new PollPendingFulfillmentJob($item));

                $item->refresh();

                $unresolved = in_array($item->fulfillment_status, [
                    FulfillmentStatus::Processing,
                    FulfillmentStatus::Delayed,
                ], true);

                if ($unresolved && $item->created_at < $handOver) {
                    $order = $item->order;

                    if ($order && $order->order_status !== OrderStatus::RequiresAttention) {
                        $order->order_status = OrderStatus::RequiresAttention;
                        $order->save();
                    }
                }
            });
        });
})->everyTenMinutes()->name('fulfillment:poll-stalled')->withoutOverlapping();

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

// The wallet ledger chain: the hash-linked events WalletService writes for
// every credit, debit, lock and unlock, re-proved link by link and checked
// against each wallet's balance. This is the chain production actually
// writes (the double-entry journals above have no writer yet), so this is
// the check that stands behind every balance customers spend from.
Schedule::command('ledger:verify-chain')
    ->dailyAt('02:40')
    ->withoutOverlapping()
    ->name('ledger:verify-chain-nightly')
    ->sentryMonitor('ledger-verify-chain-nightly');

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

// Identity safety net, the same shape as the crypto one below: a Smile ID
// callback that never arrives leaves the customer reading "in review" with
// nothing coming, and under step-up enforcement holds their payment behind
// it. This asks Smile about anything still waiting and applies the verdict
// through the path the callback would have used.
Schedule::command('kyc:reconcile')
    ->everyFifteenMinutes()
    ->withoutOverlapping()
    ->name('kyc:reconcile')
    ->sentryMonitor('kyc-reconcile');

// Refresh the local GeoLite2 database. Everything that knows where a login came
// from reads this one file, and IP ranges are reassigned continually, so a
// stale file quietly loses accuracy rather than failing. MaxMind publishes
// twice a week; Tuesday covers it with room to spare. A failed download leaves
// the existing file in place, so the worst case is staleness, never a gap.
Schedule::command('location:update')
    ->weeklyOn(2, '03:20')
    ->withoutOverlapping()
    ->name('location:update')
    ->sentryMonitor('location-update');

// Crypto safety net: re-verifies pending NOWPayments attempts against the gateway
// so dropped IPNs still confirm. This is not optional cover — NOWPayments stops
// sending callbacks once a payment expires while still crediting deposits that
// arrive afterwards, so polling is the only way those are ever seen.
Schedule::command('reconcile:nowpayments --hours=48')
    ->hourly()
    ->withoutOverlapping()
    ->sentryMonitor('reconcile-nowpayments');

// Kora's book against ours: every Kora attempt of the last two days is looked
// up by our reference and each disagreement goes to Telegram. Report-only; a
// human decides, or runs it again with --settle.
Schedule::command('korapay:reconcile --hours=48')
    ->hourly()
    ->withoutOverlapping()
    ->sentryMonitor('korapay-reconcile');

// Disputes and chargebacks, read from each live gateway's chargeback list so a
// webhook that never came never hides one. New disputes page with their
// deadline and label the customer for the risk engine; open ones page again
// when under 6 hours remain. Reads only; nothing is answered or refunded.
Schedule::command('disputes:sync')
    ->hourlyAt(20)
    ->withoutOverlapping()
    ->sentryMonitor('disputes-sync');

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

// Dispatch scheduled push notification campaigns
Schedule::command('campaigns:dispatch')
    ->everyMinute()
    ->withoutOverlapping()
    ->runInBackground()
    ->name('campaigns:dispatch-1m');
