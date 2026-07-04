<?php

namespace App\Domain\Admin\Queries;

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
use Illuminate\Support\Facades\DB;

/**
 * Centralized query class for admin dashboard metrics.
 *
 * Keeps complex aggregations and cross-table unions out of the models
 * and controllers. This ensures queries are optimized and easy to scale.
 */
class DashboardMetricsQuery
{
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

        // Revenue is recognised only when an order is completed + fulfilled
        // (order_status = Completed). A paid-but-still-processing order is
        // money in the till but not yet earned — excluded here so the KPI
        // matches the Revenue Overview chart's "completed and fulfilled" rule.
        //
        // Summed via Order::usdTotal(): total_amount is the display-currency
        // figure (4000 XAF, 12000 NGN, ...), so summing it raw would mix
        // currencies into a number the dashboard then labels "USD".
        $totalRevenue = (clone $ordersQuery)
            ->where('order_status', OrderStatus::Completed->value)
            ->get(['id', 'total_amount', 'display_currency', 'metadata'])
            ->sum(fn (Order $order) => $order->usdTotal());

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
            ->where('order_status', OrderStatus::Completed->value)
            ->count();
        $completedOrdersPct = $totalOrders > 0
            ? round(($completedOrders / $totalOrders) * 100, 2)
            : 0.0;

        // Markup share: how much of completed-order revenue is our profit vs
        // what we paid the supplier. order_items.provider_cost_usd × quantity
        // is the source-of-truth supplier cost — items without a cost snapshot
        // contribute zero (no synthetic estimate), so the figure stays honest.
        $supplierCost = DB::table('order_items')
            ->join('orders', 'order_items.order_id', '=', 'orders.id')
            ->where('orders.order_status', OrderStatus::Completed->value)
            ->when($startDate, fn ($q) => $q->where('orders.created_at', '>=', $startDate))
            ->when($endDate, fn ($q) => $q->where('orders.created_at', '<=', $endDate))
            ->sum(DB::raw('order_items.provider_cost_usd * order_items.quantity'));
        $markupSharePct = $totalRevenue > 0
            ? round((((float) $totalRevenue - (float) $supplierCost) / (float) $totalRevenue) * 100, 2)
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
            $code = strtoupper((string) $code);
            $balance = (float) $balance;

            if ($code === 'USD') {
                $total += $balance;

                continue;
            }

            if ($code === Currency::RCOIN->value) {
                $total += $balance * $rcoinUsdRate;

                continue;
            }

            $rate = (float) ($ratesPerUsd[$code] ?? 0.0);
            if ($rate > 0) {
                $total += $balance / $rate;
            }
        }

        return round($total, 2);
    }

    /**
     * SQL expression converting order_items.subtotal_amount (display currency)
     * to USD via the order's metadata exchange-rate snapshot. Orders without a
     * snapshot divide by 1 — for those legacy rows the stored amount is the
     * only honest figure available (mirrors Order::usdTotal()'s fallback).
     */
    private function itemUsdSql(): string
    {
        $rate = $this->jsonDecimalSql('orders.metadata', '$.exchange_rate');

        return "order_items.subtotal_amount / COALESCE(NULLIF({$rate}, 0), 1)";
    }

    /**
     * Driver-aware numeric extraction from a JSON column. MySQL needs
     * JSON_UNQUOTE + DECIMAL cast; SQLite (the test driver) unquotes inside
     * json_extract already and casts with NUMERIC.
     */
    private function jsonDecimalSql(string $column, string $path): string
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            return "CAST(json_extract({$column}, '{$path}') AS NUMERIC)";
        }

        return "CAST(JSON_UNQUOTE(JSON_EXTRACT({$column}, '{$path}')) AS DECIMAL(16,8))";
    }

    /**
     * Driver-aware text extraction from a JSON column.
     */
    private function jsonTextSql(string $column, string $path): string
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
    private function dateFormatSql(string $column, string $format): string
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
     * Report builder used by /admin/reports. Aggregates completed PRODUCT
     * orders into buckets (daily / weekly / monthly), optionally narrowed to a
     * category, and returns honest USD numbers via Order::usdTotal() — so
     * legacy orders whose display_currency was mis-labelled don't poison the
     * totals.
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
     *   - sales_usd         sum of order USD totals
     *   - cost_usd          sum of supplier USD cost
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
            ->where('order_status', OrderStatus::Completed->value)
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

            $buckets[$bucketKey]['transactions']++;
            $buckets[$bucketKey]['sales'] += $order->usdTotal();
            $buckets[$bucketKey]['cost'] += $order->items->sum(
                fn ($item) => (float) $item->provider_cost_usd * (int) $item->quantity,
            );
        }

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
            $sales = round((float) $row['sales'], 4);
            $cost = round((float) $row['cost'], 4);
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
     * Get revenue chart data aggregated by date and product category.
     *
     * Accepts either a named preset ('7d' | '30d' | '6m' | '1y') or a raw int
     * day count (e.g. 15). Day counts always group daily; '6m' and '1y' group
     * monthly. The int path lets the dashboard's 1-30 day picker drive the
     * chart at full granularity without each day mapping to a coarser bucket.
     */
    /**
     * Daily sales + cost timeseries for the Trends chart. Sales is what the
     * customer paid (order_items.subtotal_amount, converted to USD); cost is the
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
            ->where('orders.order_status', OrderStatus::Completed->value)
            ->where('orders.completed_at', '>=', $startDate)
            ->select(
                DB::raw($this->dateFormatSql('orders.completed_at', '%Y-%m-%d').' as date'),
                // Item subtotals are display-currency figures; divide by the
                // order's exchange-rate snapshot so the chart's "sales" are
                // real USD, comparable against the USD supplier cost below.
                DB::raw('SUM('.$this->itemUsdSql().') as sales'),
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

        // Fill every day in the window so the chart line is continuous even
        // when there were no sales that day.
        $series = [];
        for ($i = 0; $i < $days; $i++) {
            $date = now()->subDays($days - 1 - $i)->format('Y-m-d');
            $row = $rows[$date] ?? null;
            $series[] = [
                'date' => $date,
                'sales' => $row ? round((float) $row->sales, 2) : 0.0,
                'cost' => $row ? round((float) $row->cost, 2) : 0.0,
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
            ->where('orders.order_status', OrderStatus::Completed->value)
            ->where('orders.completed_at', '>=', $startDate)
            ->select(
                DB::raw('UPPER('.$this->jsonTextSql('order_items.product_snapshot', '$.country_code').') as cc'),
                // Converted to USD via the order's rate snapshot — the map
                // labels these figures "USD", so a 4373 XAF sale must show
                // as ~$7, not as $4373.
                DB::raw('SUM('.$this->itemUsdSql().') as total'),
            )
            ->groupBy('cc');

        if ($category !== null && $category !== 'all') {
            $query->join('categories', 'order_items.category_id', '=', 'categories.id')
                ->where('categories.slug', 'like', '%'.$category.'%');
        }

        $rows = $query->get();

        $byCountry = [];
        foreach ($rows as $row) {
            $cc = (string) ($row->cc ?? '');
            // Skip rows with no country or a non-country marker (WW = global eSIM).
            if ($cc === '' || strlen($cc) !== 2 || $cc === 'WW') {
                continue;
            }
            $byCountry[$cc] = round((float) $row->total, 2);
        }

        return $byCountry;
    }

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

        // Aggregate completed + fulfilled orders by product type. Revenue is
        // recognised only when fulfillment finishes (order_status = Completed),
        // so a paid-but-still-processing order does NOT appear here yet.
        $results = DB::table('order_items')
            ->join('orders', 'order_items.order_id', '=', 'orders.id')
            ->join('categories', 'order_items.category_id', '=', 'categories.id')
            ->where('orders.order_status', OrderStatus::Completed->value)
            ->where('orders.completed_at', '>=', $startDate)
            ->select(
                DB::raw($this->dateFormatSql('orders.completed_at', $dateFormat).' as date'),
                'categories.slug as category_slug',
                // USD via the order's rate snapshot, matching the other charts.
                DB::raw('SUM('.$this->itemUsdSql().') as total')
            )
            ->groupBy('date', 'categories.slug')
            ->orderBy('date')
            ->get();

        $chartData = [];

        foreach ($results as $row) {
            $date = $row->date;

            if (! isset($chartData[$date])) {
                $chartData[$date] = [
                    'date' => $date,
                    'gift_cards' => 0,
                    'esim' => 0,
                    'topup' => 0,
                    'other' => 0,
                ];
            }

            // Pivot product types into fixed categories
            $slug = strtolower($row->category_slug);
            $key = match (true) {
                str_contains($slug, 'gift') => 'gift_cards',
                str_contains($slug, 'esim') => 'esim',
                str_contains($slug, 'topup') || str_contains($slug, 'airtime') || str_contains($slug, 'data') => 'topup',
                default => 'other',
            };

            $chartData[$date][$key] += (float) $row->total;
        }

        return array_values($chartData);
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
