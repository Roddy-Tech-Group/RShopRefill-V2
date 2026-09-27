<?php

namespace App\Domain\Admin\Queries;

use App\Domain\Admin\Support\RefundAdjustments;
use App\Domain\Fulfillment\Enums\FulfillmentStatus;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Payment\Enums\PaymentStatus;
use App\Domain\Shared\Enums\Currency;
use App\Models\CurrencyRate;
use App\Models\Order;
use App\Models\PaymentAttempt;
use App\Models\Setting;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Centralized query class for admin dashboard metrics.
 *
 * Keeps complex aggregations and cross-table unions out of the models
 * and controllers. This ensures queries are optimized and easy to scale.
 */
class DashboardMetricsQuery
{
    public function __construct(private RefundAdjustments $refunds) {}

    /**
     * Get aggregated overview metrics.
     *
     * Accepts an optional preset ('today' | '7d' | '30d' | '90d' | 'year' | 'custom')
     * and optional custom-range bounds. Default null → all-time (preserves the
     * original behaviour so existing callers don't break). Wallet balance total
     * is always all-time — it is a snapshot, not a transactional figure.
     */
    public function getOverviewMetrics(?string $preset = null, ?string $start = null, ?string $end = null): array
    {
        [$startDate, $endDate] = $this->resolveRange($preset, $start, $end);

        $usersQuery = User::query();
        $ordersQuery = Order::query();
        $paymentQuery = PaymentAttempt::query();
        $walletTxQuery = WalletTransaction::query();

        if ($startDate) {
            $usersQuery->where('created_at', '>=', $startDate);
            $ordersQuery->where('created_at', '>=', $startDate);
            $paymentQuery->where('created_at', '>=', $startDate);
            $walletTxQuery->where('created_at', '>=', $startDate);
        }
        if ($endDate) {
            $usersQuery->where('created_at', '<=', $endDate);
            $ordersQuery->where('created_at', '<=', $endDate);
            $paymentQuery->where('created_at', '<=', $endDate);
            $walletTxQuery->where('created_at', '<=', $endDate);
        }

        $totalUsers = $usersQuery->count();
        $totalOrders = $ordersQuery->count();

        // Revenue is recognised when an order completes, and is never
        // un-booked. A paid-but-still-processing order is money in the till
        // but not yet earned, so it is excluded; a refunded one stays, because
        // the sale really happened and the refund is deducted below on the day
        // it was given back. Dropping the order instead is what made a closed
        // month's revenue shrink after the fact.
        //
        // Summed from DELIVERED ITEMS, never the whole order total, and via
        // itemCashUsdSql() so the display-currency figures (4000 XAF, 12000
        // NGN) become real USD rather than being added together raw, and only
        // the part the customer paid in cash counts. Rcoin is not revenue.
        //
        // The order total includes items that never delivered, which the
        // customer was refunded for. Counting it while counting only delivered
        // items' cost reports revenue we gave back against cost we never paid,
        // and inflates profit by exactly the failed items' cost. Revenue and
        // cost have to come off the same basis, which is what the Finances page
        // has always done and what these two pages did not.
        //
        // Completed orders only, like every other page. An order refunded in
        // full while it still waited on the supplier is `refunded`, a booked
        // status, but it never completed: nothing was sold, and its
        // undelivered items cost nothing.
        $grossRevenue = (float) DB::table('order_items')
            ->join('orders', 'order_items.order_id', '=', 'orders.id')
            ->whereIn('orders.order_status', OrderStatus::booked())
            ->whereNotNull('orders.completed_at')
            ->where('order_items.fulfillment_status', '!=', FulfillmentStatus::Failed->value)
            ->when($startDate, fn ($q) => $q->where('orders.created_at', '>=', $startDate))
            ->when($endDate, fn ($q) => $q->where('orders.created_at', '<=', $endDate))
            ->sum(DB::raw($this->itemCashUsdSql()));

        // Money handed back for goods the customer actually received, dated by
        // when it was handed back. Same ledger the Finances page reads, so the
        // two pages cannot disagree.
        $refunds = $this->refunds->customerRefundsUsd($startDate, $endDate);
        $totalRevenue = round((float) $grossRevenue - $refunds, 4);

        $paymentCount = (clone $paymentQuery)->count();
        $walletTxCount = $walletTxQuery->count();
        $transactionsCount = $paymentCount + $walletTxCount;

        $successfulPayments = (clone $paymentQuery)
            ->where('payment_status', PaymentStatus::Paid)
            ->count();
        $successRate = $paymentCount > 0 ? round(($successfulPayments / $paymentCount) * 100, 2) : 0.0;

        // Wallet balance total stays all-time — current value, not a transaction.
        $walletBalanceTotal = $this->walletBalanceTotalUsd();

        // ── Per-card donut breakdowns ───────────────────────────────────
        // Each KPI card gets a mini donut chart that visualises a meaningful
        // ratio for that metric. All five are bounded 0–100 so the donut can
        // render them as a single percent arc.
        //
        // Users:        active (neither banned nor suspended) / total
        // Orders:       completed / total
        // Revenue:      markup share (sales − supplier cost) / sales
        // Transactions: successful payments / (payments + wallet txns)
        // Success Rate: identical to the headline KPI — reinforces the figure
        $activeUsers = (clone $usersQuery)
            ->whereNull('banned_at')
            ->whereNull('suspended_at')
            ->count();
        $activeUsersPct = $totalUsers > 0
            ? round(($activeUsers / $totalUsers) * 100, 2)
            : 0.0;

        $completedOrders = (clone $ordersQuery)
            ->whereIn('order_status', OrderStatus::booked())
            ->whereNotNull('completed_at')
            ->count();
        $completedOrdersPct = $totalOrders > 0
            ? round(($completedOrders / $totalOrders) * 100, 2)
            : 0.0;

        // Markup share: how much of completed-order revenue is our profit vs
        // what we paid the supplier. order_items.provider_cost_usd × quantity
        // is the source-of-truth supplier cost — items without a cost snapshot
        // contribute zero (no synthetic estimate), so the figure stays honest.
        // Delivered items only: an item that never delivered cost us nothing
        // and earned nothing, so counting its cost against a booked sale would
        // invent a loss. Supplier credits come off on the day the supplier
        // granted them, which is not the day we refunded the customer.
        //
        // Not floored at zero, the same as Finances. A window can hold a
        // credit for a sale made before it, and dropping part of that credit
        // would report a cost the supplier gave back. The donut below clamps
        // the share to 0-100 anyway, so nothing downstream sees a negative.
        $supplierCost = DB::table('order_items')
            ->join('orders', 'order_items.order_id', '=', 'orders.id')
            ->whereIn('orders.order_status', OrderStatus::booked())
            ->whereNotNull('orders.completed_at')
            ->where('order_items.fulfillment_status', '!=', FulfillmentStatus::Failed->value)
            ->when($startDate, fn ($q) => $q->where('orders.created_at', '>=', $startDate))
            ->when($endDate, fn ($q) => $q->where('orders.created_at', '<=', $endDate))
            ->sum(DB::raw('order_items.provider_cost_usd * order_items.quantity'));
        $supplierCost = (float) $supplierCost - $this->refunds->supplierCreditsUsd($startDate, $endDate);

        $markupSharePct = $totalRevenue > 0
            ? round((($totalRevenue - $supplierCost) / $totalRevenue) * 100, 2)
            : 0.0;

        $transactionsSuccessPct = $transactionsCount > 0
            ? round((($successfulPayments + $walletTxCount) / $transactionsCount) * 100, 2)
            : 0.0;

        return [
            'total_users' => $totalUsers,
            'total_orders' => $totalOrders,
            'total_revenue' => (float) $totalRevenue,
            'transactions_count' => $transactionsCount,
            'success_rate' => $successRate,
            'wallet_balance_total' => (float) $walletBalanceTotal,
            // Donut percentages — each bounded 0–100, ready to render.
            'donuts' => [
                'active_users_pct' => $activeUsersPct,
                'completed_orders_pct' => $completedOrdersPct,
                'markup_share_pct' => max(0.0, min(100.0, $markupSharePct)),
                'transactions_success_pct' => $transactionsSuccessPct,
                'success_rate_pct' => $successRate,
            ],
        ];
    }

    /**
     * The company's money vs money it merely holds for customers - a plain
     * separation of the two pools so they are never confused:
     *
     *   - customer_float_usd  Every wallet balance summed in USD. A LIABILITY:
     *                         money customers funded that the business must keep
     *                         available to honour their spends and withdrawals.
     *                         NOT the company's to spend.
     *   - company_profit_usd  Markup actually earned = completed-order sales
     *                         (the part paid in cash, never Rcoin) minus the
     *                         supplier cost paid. This IS the business's
     *                         money.
     *
     * Deliberately all-time and range-independent: float is a live snapshot (you
     * cannot date-filter a current balance) and profit is cumulative earnings.
     *
     * @return array{customer_float_usd: float, company_profit_usd: float, gross_sales_usd: float, supplier_cost_usd: float}
     */
    public function getMoneySplit(): array
    {
        $customerFloat = $this->walletBalanceTotalUsd();

        // All-time booked sales on the SAME basis as the cost below: delivered
        // items only. Using the whole order total here while costing only
        // delivered items is what inflated profit by the failed items' cost.
        // Less what was handed back to customers, because profit the business
        // can actually spend is net of refunds.
        $grossSales = (float) DB::table('order_items')
            ->join('orders', 'order_items.order_id', '=', 'orders.id')
            ->whereIn('orders.order_status', OrderStatus::booked())
            ->whereNotNull('orders.completed_at')
            ->where('order_items.fulfillment_status', '!=', FulfillmentStatus::Failed->value)
            ->sum(DB::raw($this->itemCashUsdSql()));
        $grossSales = max(0.0, $grossSales - $this->refunds->customerRefundsUsd());

        // Source-of-truth supplier cost from the item cost snapshots, delivered
        // items only, less every credit the supplier has granted us.
        $supplierCost = (float) DB::table('order_items')
            ->join('orders', 'order_items.order_id', '=', 'orders.id')
            ->whereIn('orders.order_status', OrderStatus::booked())
            ->whereNotNull('orders.completed_at')
            ->where('order_items.fulfillment_status', '!=', FulfillmentStatus::Failed->value)
            ->sum(DB::raw('order_items.provider_cost_usd * order_items.quantity'));
        $supplierCost = max(0.0, $supplierCost - $this->refunds->supplierCreditsUsd());

        return [
            'customer_float_usd' => round($customerFloat, 2),
            'company_profit_usd' => round(max(0.0, $grossSales - $supplierCost), 2),
            'gross_sales_usd' => round($grossSales, 2),
            'supplier_cost_usd' => round($supplierCost, 2),
        ];
    }

    /**
     * Resolve a preset string + optional custom bounds into [start, end] Carbon
     * instances or [null, null] for all-time.
     *
     * @return array{0: ?Carbon, 1: ?Carbon}
     */
    private function resolveRange(?string $preset, ?string $start, ?string $end): array
    {
        if ($preset === 'custom' && $start && $end) {
            try {
                return [Carbon::parse($start)->startOfDay(), Carbon::parse($end)->endOfDay()];
            } catch (\Throwable $e) {
                return [null, null];
            }
        }

        return match ($preset) {
            'today' => [now()->startOfDay(), now()],
            '7d' => [now()->subDays(7), now()],
            '30d' => [now()->subDays(30), now()],
            '90d' => [now()->subDays(90), now()],
            'year' => [now()->startOfYear(), now()],
            default => [null, null],
        };
    }

    /**
     * Sum every wallet into a single honest USD figure. Balances are stored in
     * the wallet's own currency, so a raw SUM(balance) would add 4000 XAF to
     * 20 USD and call the result dollars.
     *
     *   - USD wallets count at face value (never divided by the USD spread row).
     *   - Rcoin converts at the platform's rcoin_usd_rate setting.
     *   - Other currencies divide by their active currency_rates rate_per_usd.
     *   - Currencies with no usable rate are skipped — no rate, no claim.
     */
    private function walletBalanceTotalUsd(): float
    {
        $balancesByCurrency = Wallet::query()
            ->selectRaw('currency, SUM(balance) as total')
            ->groupBy('currency')
            ->pluck('total', 'currency');

        if ($balancesByCurrency->isEmpty()) {
            return 0.0;
        }

        $ratesPerUsd = CurrencyRate::query()
            ->where('is_active', true)
            ->pluck('rate_per_usd', 'code');

        $rcoinUsdRate = (float) Setting::rcoinUsdRate();

        $total = 0.0;
        foreach ($balancesByCurrency as $code => $balance) {
            $total += $this->balanceToUsd((string) $code, (float) $balance, $ratesPerUsd, $rcoinUsdRate);
        }

        return round($total, 2);
    }

    /**
     * Convert a single balance in `$code` to USD using the platform's canonical
     * rule (the same one that powers the wallet-balance total and the money
     * split, so the CLI money report can never disagree with the dashboard):
     *
     *   - USD counts at face value.
     *   - Rcoin converts at the rcoin_usd_rate setting.
     *   - Every other currency divides by its active per-USD rate.
     *   - A currency with no usable rate contributes 0 (no rate, no claim).
     *
     * @param  Collection<string, mixed>  $ratesPerUsd  currency code => rate_per_usd
     */
    public function balanceToUsd(string $code, float $amount, Collection $ratesPerUsd, float $rcoinUsdRate): float
    {
        $code = strtoupper($code);

        if ($code === 'USD') {
            return $amount;
        }

        if ($code === Currency::RCOIN->value) {
            return $amount * $rcoinUsdRate;
        }

        $rate = (float) ($ratesPerUsd[$code] ?? 0.0);

        return $rate > 0 ? $amount / $rate : 0.0;
    }

    /**
     * SQL expression converting order_items.subtotal_amount (display currency)
     * to USD via the order's metadata exchange-rate snapshot. Orders without a
     * snapshot divide by 1: for those legacy rows the stored amount is the
     * only honest figure available (mirrors Order::usdTotal()'s fallback).
     *
     * Public so LeaderboardQuery reuses the exact same conversion rule, and
     * itemCashUsdSql() builds on it. Two pages must never disagree about a
     * USD figure.
     *
     * This is the item's full price, whatever paid for it. The customer
     * rankings (LeaderboardQuery) read it. The admin money pages read
     * itemCashUsdSql() instead.
     */
    public function itemUsdSql(): string
    {
        $rate = $this->jsonDecimalSql('orders.metadata', '$.exchange_rate');

        return "order_items.subtotal_amount / COALESCE(NULLIF({$rate}, 0), 1)";
    }

    /**
     * The revenue an item earned: its USD price (itemUsdSql) times the share
     * of its order the customer paid in cash. Revenue is what the customer
     * paid in cash. The part paid with Rcoin is not revenue, and an order paid
     * entirely with Rcoin earns nothing while its supplier cost still counts.
     *
     * A coupon needs no share: checkout lowers the item's own price for it.
     * Rcoin only lowers the order total, so the item still carries its full
     * price and the share takes the Rcoin part back out. The share is
     * settlement_total_usd over settlement_total_usd plus rcoin_discount_usd,
     * both USD, so it holds for every display currency. With no Rcoin, or
     * without those figures, the share is 1 and this equals itemUsdSql().
     *
     * Order::cashShare() applies the same rule in PHP, for the pages that
     * sum in PHP and for RefundAdjustments.
     *
     * Overview, the money split (and the money:report command), Trends, the
     * country map, the category chart and Finances read this SQL. Reports
     * sums its buckets in PHP and applies Order::cashShare() instead.
     */
    public function itemCashUsdSql(): string
    {
        $cash = $this->jsonDecimalSql('orders.metadata', '$.settlement_total_usd');
        $rcoin = $this->jsonDecimalSql('orders.metadata', '$.rcoin_discount_usd');

        // A missing key reads as NULL, which fails both tests and leaves the
        // share at 1. The * 1.0 keeps sqlite from dividing whole numbers as
        // integers.
        $share = "CASE WHEN {$rcoin} > 0 AND {$cash} + {$rcoin} > 0 THEN {$cash} * 1.0 / ({$cash} + {$rcoin}) ELSE 1 END";

        return "({$this->itemUsdSql()}) * ({$share})";
    }

    /**
     * Driver-aware numeric extraction from a JSON column. MySQL needs
     * JSON_UNQUOTE + DECIMAL cast; SQLite (the test driver) unquotes inside
     * json_extract already and casts with NUMERIC.
     *
     * A stored JSON null reads as SQL NULL on both drivers. MySQL unquotes it
     * to the text 'null', which would otherwise cast to 0 and read as a real
     * zero (a cash share of 0 where SQLite and Order::cashShare() say 1).
     */
    public function jsonDecimalSql(string $column, string $path): string
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            return "CAST(json_extract({$column}, '{$path}') AS NUMERIC)";
        }

        return "CAST(NULLIF(JSON_UNQUOTE(JSON_EXTRACT({$column}, '{$path}')), 'null') AS DECIMAL(16,8))";
    }

    /**
     * Driver-aware text extraction from a JSON column.
     */
    public function jsonTextSql(string $column, string $path): string
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            return "json_extract({$column}, '{$path}')";
        }

        return "JSON_UNQUOTE(JSON_EXTRACT({$column}, '{$path}'))";
    }

    /**
     * Driver-aware date bucketing. The format tokens used here (%Y, %m, %d)
     * mean the same thing to MySQL's DATE_FORMAT and SQLite's strftime.
     */
    public function dateFormatSql(string $column, string $format): string
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            return "strftime('{$format}', {$column})";
        }

        return "DATE_FORMAT({$column}, '{$format}')";
    }

    /**
     * Monthly new-user counts for the last N months (inclusive of the current
     * month). Returns an ordered ['Jan' => 0, 'Feb' => 3, ...] array — keys are
     * the short month label so the dashboard chart can read them directly.
     */
    public function getNewUsersTimeseries(int $months = 6): array
    {
        $months = max(1, min(24, $months));

        $signups = User::selectRaw("DATE_FORMAT(created_at, '%Y-%m') as month, COUNT(*) as count")
            ->where('created_at', '>=', now()->subMonths($months - 1)->startOfMonth())
            ->groupBy('month')
            ->orderBy('month')
            ->pluck('count', 'month');

        $result = [];
        for ($i = $months - 1; $i >= 0; $i--) {
            $m = now()->subMonths($i);
            $result[$m->format('M')] = (int) ($signups[$m->format('Y-m')] ?? 0);
        }

        return $result;
    }

    /**
     * Report builder used by /admin/reports. Aggregates booked PRODUCT orders
     * into buckets (daily / weekly / monthly), optionally narrowed to a
     * category.
     *
     * Revenue and cost both come from DELIVERED ITEMS, converted by the order's
     * own rate snapshot, and revenue is only the part the customer paid in
     * cash (Order::cashShare), which is the basis the Finances page uses. For
     * a given month this returns the same revenue Finances does, and a test
     * asserts it, because the two pages disagreeing is how a
     * refunded-but-still-counted item inflated profit here for months without
     * anyone noticing.
     *
     * Narrowing to a category narrows the revenue too, since only that
     * category's items are loaded. Previously a category filter still reported
     * the WHOLE order total of any order containing that category. Refunds and
     * supplier credits narrow the same way, through each refund's per-item
     * allocations, so the figure for one category matches that category's row
     * on Finances.
     *
     * A bucket's cost can go negative: a supplier credit granted on a day with
     * less cost than the credit. It is kept whole, because flooring each
     * bucket threw part of the credit away and the period's total stopped
     * matching Finances.
     *
     * Product-only scope:
     *   - Sourced from `orders` only. Wallet top-ups live in `wallet_fundings`
     *     (a different table with its own payment_attempts) and never appear
     *     here. The `whereHas('items', category_id IS NOT NULL)` clause is
     *     belt-and-braces against any future order shape that lands a row
     *     without product items — those would be excluded too.
     *   - eSIM in-dashboard top-ups DO count: they're real product purchases
     *     stored as Orders + OrderItems (metadata flags them, but the data
     *     model is identical to a fresh eSIM order).
     *
     * Cost is summed from `order_items.provider_cost_usd × quantity`, the
     * source-of-truth USD we pay the supplier. Items without a recorded cost
     * contribute zero — no synthetic estimate.
     *
     * Returns an array keyed by bucket date (Y-m-d), each row carrying:
     *   - date              ISO bucket start
     *   - transactions      distinct completed product orders in the bucket
     *   - sales_usd         delivered items' USD subtotals, cash share only (NOT order totals)
     *   - cost_usd          sum of supplier USD cost, less supplier credits
     *   - profit_usd        sales − cost
     *   - profit_margin     profit / sales × 100, or 0 when sales = 0
     *   - avg_per_tx_usd    sales / transactions, or 0 when transactions = 0
     *
     * Gap-fills empty buckets so the chart line is continuous.
     *
     * @return array<int, array{date: string, transactions: int, sales_usd: float, cost_usd: float, profit_usd: float, profit_margin: float, avg_per_tx_usd: float}>
     */
    public function getReportSeries(
        Carbon $start,
        Carbon $end,
        string $granularity = 'daily',
        ?int $categoryId = null,
    ): array {
        $granularity = in_array($granularity, ['daily', 'weekly', 'monthly'], true) ? $granularity : 'daily';

        $ordersQuery = Order::query()
            ->whereIn('order_status', OrderStatus::booked())
            ->whereBetween('completed_at', [$start, $end])
            // Defensive: only orders with at least one product item (i.e. a
            // row in order_items with a non-null category_id) count. This is
            // the "product data only" guarantee — if anything non-product
            // ever sneaks into the orders table it's silently skipped here.
            ->whereHas('items', fn ($q) => $q->whereNotNull('category_id'))
            ->with(['items' => function ($query) use ($categoryId) {
                if ($categoryId !== null) {
                    $query->where('category_id', $categoryId);
                }
            }]);

        if ($categoryId !== null) {
            // When narrowing to a category, only count orders that have at
            // least one item in that category. Stops the table from listing
            // orders whose only contribution to "Total sales" is zero.
            $ordersQuery->whereHas('items', fn ($q) => $q->where('category_id', $categoryId));
        }

        $orders = $ordersQuery->get();

        // Bucket every order by date according to the chosen granularity. The
        // bucket key is the first date in the period so the table reads as
        // "week starting X" / "month of Y".
        $buckets = [];
        foreach ($orders as $order) {
            $completedAt = $order->completed_at;
            if (! $completedAt instanceof Carbon) {
                continue;
            }

            $bucketKey = match ($granularity) {
                'weekly' => $completedAt->copy()->startOfWeek()->format('Y-m-d'),
                'monthly' => $completedAt->copy()->startOfMonth()->format('Y-m-d'),
                default => $completedAt->copy()->startOfDay()->format('Y-m-d'),
            };

            if (! isset($buckets[$bucketKey])) {
                $buckets[$bucketKey] = ['transactions' => 0, 'sales' => 0.0, 'cost' => 0.0];
            }

            // Delivered items only, on BOTH sides. A failed item earned nothing
            // (the customer was refunded) and cost nothing (it never
            // delivered), so it belongs in neither figure. Counting the whole
            // order total as revenue while costing only what delivered reports
            // money we gave back against cost we never paid.
            //
            // Same conversion as itemCashUsdSql(), which divides each item's
            // display-currency subtotal by the order's rate snapshot and keeps
            // only the share the customer paid in cash. The rate and the share
            // are per order, so applying them once to the order's summed
            // subtotals is identical arithmetic, and it keeps this figure equal
            // to the Finances page's revenue for the same month.
            $delivered = $order->items->where('fulfillment_status', '!=', FulfillmentStatus::Failed);
            $rate = $order->exchangeRate() ?: 1.0;

            $buckets[$bucketKey]['transactions']++;
            $buckets[$bucketKey]['sales'] += $delivered->sum(fn ($item) => (float) $item->subtotal_amount) / $rate * $order->cashShare();
            $buckets[$bucketKey]['cost'] += $delivered
                ->sum(fn ($item) => (float) $item->provider_cost_usd * (int) $item->quantity);
        }

        // Refunds and supplier credits land in the bucket they HAPPENED in,
        // not the bucket that made the sale. An eSIM sold in September and
        // refunded in October is September revenue and an October deduction,
        // which is the whole reason the ledger records its own dates. The one
        // exception is a refund taken before its order completed: it lands
        // with the sale it reverses, never in an earlier bucket. Under a
        // category filter only that category's share of each comes off.
        $refundBuckets = $this->refunds->customerRefundsByBucket($start, $end, $granularity, $categoryId);
        $creditBuckets = $this->refunds->supplierCreditsByBucket($start, $end, $granularity, $categoryId);

        // Gap-fill every bucket between start and end so the chart line is
        // continuous (no missing days = no jagged drops to zero).
        $series = [];
        $cursor = match ($granularity) {
            'weekly' => $start->copy()->startOfWeek(),
            'monthly' => $start->copy()->startOfMonth(),
            default => $start->copy()->startOfDay(),
        };
        $endCursor = $end->copy()->startOfDay();

        while ($cursor <= $endCursor) {
            $key = $cursor->format('Y-m-d');
            $row = $buckets[$key] ?? ['transactions' => 0, 'sales' => 0.0, 'cost' => 0.0];
            $sales = round((float) $row['sales'] - ($refundBuckets[$key] ?? 0.0), 4);
            $cost = round((float) $row['cost'] - ($creditBuckets[$key] ?? 0.0), 4);
            $profit = round($sales - $cost, 4);
            $transactions = (int) $row['transactions'];

            $series[] = [
                'date' => $key,
                'transactions' => $transactions,
                'sales_usd' => $sales,
                'cost_usd' => $cost,
                'profit_usd' => $profit,
                'profit_margin' => $sales > 0 ? round(($profit / $sales) * 100, 2) : 0.0,
                'avg_per_tx_usd' => $transactions > 0 ? round($sales / $transactions, 4) : 0.0,
            ];

            match ($granularity) {
                'weekly' => $cursor->addWeek(),
                'monthly' => $cursor->addMonth(),
                default => $cursor->addDay(),
            };
        }

        return $series;
    }

    /**
     * Daily sales + cost timeseries for the Trends chart. Sales is what the
     * customer paid in cash (order_items.subtotal_amount, converted to USD,
     * less any part paid with Rcoin); cost is the
     * real USD wholesale we paid the supplier (order_items.provider_cost_usd x
     * quantity) - the same source-of-truth cost the reports page and the KPI
     * donut use, so the three never disagree. Items missing a cost contribute
     * zero to cost - no synthetic fallback, only real data.
     * Returns one row per day in the window, gaps filled with zeros.
     *
     * @return array<int, array{date: string, sales: float, cost: float}>
     */
    public function getSalesCostTimeseries(int $days = 30): array
    {
        $days = max(1, min(365, $days));
        $startDate = now()->subDays($days - 1)->startOfDay();

        $rows = DB::table('order_items')
            ->join('orders', 'order_items.order_id', '=', 'orders.id')
            ->whereIn('orders.order_status', OrderStatus::booked())
            ->where('order_items.fulfillment_status', '!=', FulfillmentStatus::Failed->value)
            ->where('orders.completed_at', '>=', $startDate)
            ->select(
                DB::raw($this->dateFormatSql('orders.completed_at', '%Y-%m-%d').' as date'),
                // Item subtotals are display-currency figures; divide by the
                // order's exchange-rate snapshot so the chart's "sales" are
                // real USD, comparable against the USD supplier cost below.
                // Only the cash-paid share counts: Rcoin is not revenue.
                DB::raw('SUM('.$this->itemCashUsdSql().') as sales'),
                // Cost is the real USD wholesale we paid the supplier
                // (order_items.provider_cost_usd) - the SAME source getReportSeries
                // and the KPI donut use. The old variant_snapshot.cost_price
                // over-counted: for some products (e.g. eSIMs) cost_price holds the
                // recommended RETAIL price, not the net cost, which pushed the
                // plotted cost line above sales. Items with no cost contribute 0.
                DB::raw('SUM(order_items.provider_cost_usd * order_items.quantity) as cost'),
            )
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->keyBy('date');

        // Refunds and credits belong to the day they happened, so the line
        // dips when money went back out, not retroactively on the sale day.
        // A day's cost is not floored: a credit granted on a quiet day would
        // otherwise be partly thrown away, and the window would stop summing
        // to what Finances shows.
        $refundDays = $this->refunds->customerRefundsByBucket($startDate, now()->endOfDay());
        $creditDays = $this->refunds->supplierCreditsByBucket($startDate, now()->endOfDay());

        // Fill every day in the window so the chart line is continuous even
        // when there were no sales that day.
        $series = [];
        for ($i = 0; $i < $days; $i++) {
            $date = now()->subDays($days - 1 - $i)->format('Y-m-d');
            $row = $rows[$date] ?? null;
            $series[] = [
                'date' => $date,
                'sales' => round((float) ($row->sales ?? 0) - ($refundDays[$date] ?? 0.0), 2),
                'cost' => round((float) ($row->cost ?? 0) - ($creditDays[$date] ?? 0.0), 2),
            ];
        }

        return $series;
    }

    /**
     * Best-selling countries for the world-map widget. Aggregates completed
     * order revenue per ISO country code, optionally narrowed to a product
     * category (gift_cards / esim / topup) and to a rolling-N-days window.
     * Returns a `[ISO2 => total_sales_usd]` map ready to feed jsvectormap.
     *
     * Country code is sourced from the order item's product snapshot (the
     * historical country at sale time) so renames or relocations of the
     * underlying product don't rewrite history.
     *
     * @return array<string, float>
     */
    public function getBestSellingCountries(int $days = 7, ?string $category = null): array
    {
        $days = max(1, min(365, $days));
        $startDate = now()->subDays($days - 1)->startOfDay();

        $query = DB::table('order_items')
            ->join('orders', 'order_items.order_id', '=', 'orders.id')
            ->whereIn('orders.order_status', OrderStatus::booked())
            ->where('order_items.fulfillment_status', '!=', FulfillmentStatus::Failed->value)
            ->where('orders.completed_at', '>=', $startDate)
            ->select(
                DB::raw('UPPER('.$this->jsonTextSql('order_items.product_snapshot', '$.country_code').') as cc'),
                // Converted to USD via the order's rate snapshot — the map
                // labels these figures "USD", so a 4373 XAF sale must show
                // as ~$7, not as $4373. Cash-paid share only, like Overview.
                DB::raw('SUM('.$this->itemCashUsdSql().') as total'),
            )
            ->groupBy('cc');

        $categoryIds = null;

        if ($category !== null && $category !== 'all') {
            $query->join('categories', 'order_items.category_id', '=', 'categories.id')
                ->where('categories.slug', 'like', '%'.$category.'%');

            $categoryIds = DB::table('categories')
                ->where('slug', 'like', '%'.$category.'%')
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();
        }

        $rows = $query->get();

        // Refunds come off the country that made the sale, read from each
        // refund's own per-item allocations rather than spread evenly. Each
        // allocation also names its item's category, so a map narrowed to one
        // category takes off exactly that category's refunds, matched by the
        // same slug rule as the sales above.
        $refundsByCountry = $this->refunds->customerRefundsByCountry($startDate, now()->endOfDay(), $categoryIds);

        $byCountry = [];
        foreach ($rows as $row) {
            $cc = (string) ($row->cc ?? '');
            // Skip rows with no country or a non-country marker (WW = global eSIM).
            if ($cc === '' || strlen($cc) !== 2 || $cc === 'WW') {
                continue;
            }
            $byCountry[$cc] = round(max(0.0, (float) $row->total - ($refundsByCountry[$cc] ?? 0.0)), 2);
        }

        return $byCountry;
    }

    /**
     * Get revenue chart data aggregated by date and product category.
     *
     * Accepts either a named preset ('7d' | '30d' | '6m' | '1y') or a raw int
     * day count (e.g. 15). Day counts always group daily; '6m' and '1y' group
     * monthly. The int path lets the dashboard's 1-30 day picker drive the
     * chart at full granularity without each day mapping to a coarser bucket.
     *
     * Net of customer refunds, each taken off its own category on the day it
     * was given back. A day with a refund and no sales gets its own row, which
     * can be negative.
     *
     * @return array<int, array{date: string, gift_cards: int|float, esim: int|float, topup: int|float, other: int|float}>
     */
    public function getRevenueChartData(string|int $range = '7d'): array
    {
        if (is_int($range) || ctype_digit((string) $range)) {
            $days = max(1, (int) $range);
            $startDate = now()->subDays($days);
            $dateFormat = '%Y-%m-%d';
        } else {
            $startDate = match ($range) {
                '7d' => now()->subDays(7),
                '30d' => now()->subDays(30),
                '6m' => now()->subMonths(6),
                '1y' => now()->subYear(),
                default => now()->subDays(7),
            };

            // Use monthly grouping for long ranges, daily for short ranges
            $dateFormat = in_array($range, ['6m', '1y']) ? '%Y-%m' : '%Y-%m-%d';
        }

        // Aggregate delivered items on booked orders by product type. A
        // paid-but-still-processing order does NOT appear here yet.
        $results = DB::table('order_items')
            ->join('orders', 'order_items.order_id', '=', 'orders.id')
            ->join('categories', 'order_items.category_id', '=', 'categories.id')
            ->whereIn('orders.order_status', OrderStatus::booked())
            ->where('order_items.fulfillment_status', '!=', FulfillmentStatus::Failed->value)
            ->where('orders.completed_at', '>=', $startDate)
            ->select(
                DB::raw($this->dateFormatSql('orders.completed_at', $dateFormat).' as date'),
                'categories.slug as category_slug',
                // USD via the order's rate snapshot, cash-paid share only,
                // matching the other charts.
                DB::raw('SUM('.$this->itemCashUsdSql().') as total')
            )
            ->groupBy('date', 'categories.slug')
            ->orderBy('date')
            ->get();

        $chartData = [];

        foreach ($results as $row) {
            $date = $row->date;

            $chartData[$date] ??= $this->emptyRevenueChartRow($date);
            $chartData[$date][$this->revenueChartKey((string) $row->category_slug)] += (float) $row->total;
        }

        // Refunds come off the category of each item they reversed, on the
        // day they were given back, which each refund's allocations record.
        // That is how Reports narrowed to a category reads them too, so a
        // category's line here matches Reports for that category.
        $granularity = $dateFormat === '%Y-%m' ? 'monthly' : 'daily';
        $refunds = $this->refunds->customerRefundsByCategory($startDate, now(), $granularity);

        $slugById = DB::table('categories')
            ->whereIn('id', collect($refunds)->flatMap(fn (array $byCategory) => array_keys($byCategory))->unique()->all())
            ->pluck('slug', 'id');

        foreach ($refunds as $bucket => $byCategory) {
            $date = $granularity === 'monthly' ? substr($bucket, 0, 7) : $bucket;

            foreach ($byCategory as $categoryId => $usd) {
                $slug = $slugById[$categoryId] ?? null;

                if ($slug === null) {
                    continue;
                }

                $chartData[$date] ??= $this->emptyRevenueChartRow($date);
                $chartData[$date][$this->revenueChartKey((string) $slug)] -= $usd;
            }
        }

        ksort($chartData);

        return array_values($chartData);
    }

    /**
     * @return array{date: string, gift_cards: int|float, esim: int|float, topup: int|float, other: int|float}
     */
    private function emptyRevenueChartRow(string $date): array
    {
        return [
            'date' => $date,
            'gift_cards' => 0,
            'esim' => 0,
            'topup' => 0,
            'other' => 0,
        ];
    }

    /**
     * Pivot a category slug into the chart's fixed product lines.
     */
    private function revenueChartKey(string $slug): string
    {
        $slug = strtolower($slug);

        return match (true) {
            str_contains($slug, 'gift') => 'gift_cards',
            str_contains($slug, 'esim') => 'esim',
            str_contains($slug, 'topup') || str_contains($slug, 'airtime') || str_contains($slug, 'data') => 'topup',
            default => 'other',
        };
    }

    /**
     * Get the latest registered users with their wallets.
     */
    public function getLatestUsers(int $perPage = 10): LengthAwarePaginator
    {
        return User::with('wallet')->latest()->paginate($perPage);
    }

    /**
     * Get a unified stream of the latest transactions (Payments + Wallet Txns).
     */
    public function getLatestTransactions(int $perPage = 10): LengthAwarePaginator
    {
        $payments = DB::table('payment_attempts')
            ->join('users', 'payment_attempts.user_id', '=', 'users.id')
            ->select(
                'payment_attempts.id',
                DB::raw('COALESCE(payment_attempts.gateway_reference, payment_attempts.idempotency_key) as reference'),
                'users.name as customer_name',
                DB::raw("'payment' as type"),
                'payment_attempts.amount',
                'payment_attempts.currency',
                'payment_attempts.payment_status as status',
                'payment_attempts.created_at as date',
                'payment_attempts.gateway as gateway',
                DB::raw("'payment' as source")
            );

        $walletTransactions = DB::table('wallet_transactions')
            ->join('users', 'wallet_transactions.user_id', '=', 'users.id')
            ->select(
                'wallet_transactions.id',
                'wallet_transactions.reference',
                'users.name as customer_name',
                'wallet_transactions.type as type',
                'wallet_transactions.amount',
                'wallet_transactions.currency',
                DB::raw("'completed' as status"), // Wallet txns are recorded as completed
                'wallet_transactions.created_at as date',
                DB::raw("'wallet' as gateway"),
                DB::raw("'wallet_transaction' as source")
            );

        // Union both tables into a single timeline and paginate natively
        return $payments->unionAll($walletTransactions)
            ->orderByDesc('date')
            ->paginate($perPage);
    }
}
