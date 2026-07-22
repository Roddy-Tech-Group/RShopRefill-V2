<?php

use App\Domain\Fulfillment\Enums\FulfillmentStatus;
use App\Domain\Payment\Enums\PaymentStatus;
use App\Domain\Payment\Jobs\ExpireStalePaymentSessionsJob;
use App\Domain\PaymentInsights\Jobs\ExpireStaleRecoveriesJob;
use App\Domain\Wallet\Jobs\ReconcilePendingFundingsJob;
use App\Domain\Wallet\Jobs\SyncExchangeRatesJob;
use App\Jobs\FulfillOrderItemJob;
use App\Jobs\PollPendingFulfillmentJob;
use App\Jobs\SyncZenditGiftCardsJob;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote')->hourly();

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

    Order::query()
        ->where('payment_status', PaymentStatus::Paid)
        ->where('fulfillment_status', FulfillmentStatus::NotStarted)
        ->where('placed_at', '<=', $cutoff)
        ->with('items')
        ->chunkById(100, function ($orphanedOrders) {
            foreach ($orphanedOrders as $order) {
                foreach ($order->items as $item) {
                    if ($item->fulfillment_status === FulfillmentStatus::NotStarted) {
                        Log::warning("Fulfillment sweeper: re-dispatching FulfillOrderItemJob for orphaned item {$item->id} on order {$order->order_number}");
                        FulfillOrderItemJob::dispatch($item);
                    }
                }
            }
        });
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

// Enterprise Reconciliation Engine Scheduling
Schedule::command('reconcile:wallet-balances')->dailyAt('02:00')->sentryMonitor('reconcile-wallet-balances');
Schedule::command('reconcile:orphaned-sessions')->hourly()->sentryMonitor('reconcile-orphaned-sessions');

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
