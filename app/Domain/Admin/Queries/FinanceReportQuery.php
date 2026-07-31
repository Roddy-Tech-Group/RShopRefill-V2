<?php

namespace App\Domain\Admin\Queries;

use App\Domain\Order\Enums\OrderStatus;
use App\Models\CurrencyRate;
use App\Models\Setting;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Company-finance aggregations for the Management → Finances page.
 *
 * Answers the monthly P&L question per fulfillment provider: for a given
 * month, what did each provider (Airalo, Zendit, …) cost us, what revenue did
 * it produce, and what gross profit is left — plus the same cut by product
 * category and a 12-month trend for context.
 *
 * All revenue figures are converted to USD through the order's exchange-rate
 * snapshot via {@see DashboardMetricsQuery::itemUsdSql()} and revenue is
 * recognised on `order_status = completed` / `completed_at` — the exact rules
 * the Overview dashboard and Reports page already use, so the three surfaces
 * can never disagree about a number.
 *
 * Profit here is GROSS profit (revenue − supplier cost). Gateway fees and
 * operating costs are not captured per payment yet, so a "net profit" claim
 * would be dishonest — the page labels the figure accordingly.
 */
class FinanceReportQuery
{
    /** The platform went live June 2026 — no finance month exists before it. */
    public const LAUNCH_MONTH = '2026-06';

    /**
     * Fulfillment partners that must ALWAYS appear as a line item, zero-filled
     * in months they sold nothing. An accountant's report never silently drops
     * a ledger line just because it is empty.
     */
    private const CORE_PROVIDERS = ['zendit', 'airalo'];

    /** Canonical category slugs that get the same always-present treatment. */
    private const CORE_CATEGORY_SLUGS = ['gift-cards', 'esims', 'mobile-airtime', 'bill-payments'];

    public function __construct(private DashboardMetricsQuery $metrics) {}

    /**
     * Full monthly breakdown for the Finances page.
     *
     * @return array{
     *     month: string,
     *     totals: array{orders: int, units: int, revenue_usd: float, cost_usd: float, profit_usd: float, margin_pct: float},
     *     previous_totals: array{orders: int, units: int, revenue_usd: float, cost_usd: float, profit_usd: float, margin_pct: float},
     *     providers: array<int, array{provider: string, label: string, orders: int, units: int, revenue_usd: float, cost_usd: float, profit_usd: float, margin_pct: float}>,
     *     categories: array<int, array{category: string, orders: int, units: int, revenue_usd: float, cost_usd: float, profit_usd: float, margin_pct: float}>,
     *     refunds: array{count: int, total_usd: float}
     * }
     */
    public function monthlyBreakdown(Carbon $month): array
    {
        $start = $month->copy()->startOfMonth();
        $end = $month->copy()->endOfMonth();

        $previousStart = $start->copy()->subMonthNoOverflow()->startOfMonth();
        $previousEnd = $previousStart->copy()->endOfMonth();

        return [
            'month' => $start->format('Y-m'),
            'totals' => $this->totalsRow($start, $end),
            'previous_totals' => $this->totalsRow($previousStart, $previousEnd),
            'providers' => $this->providerRows($start, $end),
            'categories' => $this->categoryRows($start, $end),
            'refunds' => $this->refundsForPeriod($start, $end),
        ];
    }

    /**
     * Revenue / cost / profit per fulfillment provider for the window.
     *
     * The provider dimension prefers `products.provider_name` (always the
     * platform partner: zendit / airalo) over `order_items.provider_name`,
     * which for top-ups can carry the telecom operator written from variant
     * metadata rather than the partner we actually pay.
     *
     * @return array<int, array{provider: string, label: string, orders: int, units: int, revenue_usd: float, cost_usd: float, profit_usd: float, margin_pct: float}>
     */
    private function providerRows(Carbon $start, Carbon $end): array
    {
        $rows = $this->completedItemsQuery($start, $end)
            ->leftJoin('products', 'order_items.product_id', '=', 'products.id')
            ->select(
                DB::raw("LOWER(COALESCE(NULLIF(products.provider_name, ''), NULLIF(order_items.provider_name, ''), 'unknown')) as provider"),
                DB::raw('COUNT(DISTINCT orders.id) as orders_count'),
                DB::raw('SUM(order_items.quantity) as units'),
                DB::raw('SUM('.$this->metrics->itemUsdSql().') as revenue_usd'),
                DB::raw('SUM(order_items.provider_cost_usd * order_items.quantity) as cost_usd'),
            )
            ->groupBy('provider')
            ->get();

        $presented = $rows
            ->map(fn ($row) => $this->presentRow((array) $row) + [
                'provider' => (string) $row->provider,
                'label' => $this->providerLabel((string) $row->provider),
            ])
            ->keyBy('provider');

        // Core partners always appear, zero-filled when they sold nothing —
        // "Airalo: 0" is information; a missing row is a question.
        foreach (self::CORE_PROVIDERS as $provider) {
            if (! $presented->has($provider)) {
                $presented->put($provider, $this->presentRow([]) + [
                    'provider' => $provider,
                    'label' => $this->providerLabel($provider),
                ]);
            }
        }

        return $presented
            ->sortBy([['revenue_usd', 'desc'], ['label', 'asc']])
            ->values()
            ->all();
    }

    /**
     * The same money cut grouped by product category (gift cards, eSIMs, …).
     *
     * @return array<int, array{category: string, orders: int, units: int, revenue_usd: float, cost_usd: float, profit_usd: float, margin_pct: float}>
     */
    private function categoryRows(Carbon $start, Carbon $end): array
    {
        $rows = $this->completedItemsQuery($start, $end)
            ->join('categories', 'order_items.category_id', '=', 'categories.id')
            ->select(
                'categories.name as category',
                DB::raw('COUNT(DISTINCT orders.id) as orders_count'),
                DB::raw('SUM(order_items.quantity) as units'),
                DB::raw('SUM('.$this->metrics->itemUsdSql().') as revenue_usd'),
                DB::raw('SUM(order_items.provider_cost_usd * order_items.quantity) as cost_usd'),
            )
            ->groupBy('categories.name')
            ->get();

        $presented = $rows
            ->map(fn ($row) => $this->presentRow((array) $row) + ['category' => (string) $row->category])
            ->keyBy('category');

        // The canonical product lines (gift cards, eSIMs, top-ups, bills)
        // always appear, zero-filled, so best-seller comparisons read at a
        // glance instead of by noticing what's absent.
        $coreCategories = DB::table('categories')
            ->whereIn('slug', self::CORE_CATEGORY_SLUGS)
            ->pluck('name');

        foreach ($coreCategories as $name) {
            if (! $presented->has((string) $name)) {
                $presented->put((string) $name, $this->presentRow([]) + ['category' => (string) $name]);
            }
        }

        return $presented
            ->sortBy([['revenue_usd', 'desc'], ['category', 'asc']])
            ->values()
            ->all();
    }

    /**
     * Refunds issued in the window, converted to USD.
     *
     * There is no refunds table — a refund is a wallet credit with
     * `transaction_category = refund` (see GatewayRefundService), stored in
     * the wallet's own currency, so each currency bucket converts via the
     * platform's canonical balance-to-USD rule.
     *
     * @return array{count: int, total_usd: float}
     */
    private function refundsForPeriod(Carbon $start, Carbon $end): array
    {
        $buckets = DB::table('wallet_transactions')
            ->where('transaction_category', 'refund')
            ->where('type', 'credit')
            ->whereBetween('created_at', [$start, $end])
            ->select('currency', DB::raw('COUNT(*) as refunds'), DB::raw('SUM(amount) as total'))
            ->groupBy('currency')
            ->get();

        if ($buckets->isEmpty()) {
            return ['count' => 0, 'total_usd' => 0.0];
        }

        $ratesPerUsd = CurrencyRate::query()->where('is_active', true)->pluck('rate_per_usd', 'code');
        $rcoinUsdRate = (float) Setting::rcoinUsdRate();

        $count = 0;
        $totalUsd = 0.0;
        foreach ($buckets as $bucket) {
            $count += (int) $bucket->refunds;
            $totalUsd += $this->metrics->balanceToUsd((string) $bucket->currency, (float) $bucket->total, $ratesPerUsd, $rcoinUsdRate);
        }

        return ['count' => $count, 'total_usd' => round($totalUsd, 2)];
    }

    /**
     * Base query: completed-order items in the window. Revenue recognition
     * matches the dashboard — `order_status = completed` on `completed_at`.
     */
    private function completedItemsQuery(Carbon $start, Carbon $end): Builder
    {
        return DB::table('order_items')
            ->join('orders', 'order_items.order_id', '=', 'orders.id')
            ->where('orders.order_status', OrderStatus::Completed->value)
            ->whereBetween('orders.completed_at', [$start, $end]);
    }

    /**
     * Round a raw aggregate row into the shared presentation shape.
     *
     * @param  array<string, mixed>  $row
     * @return array{orders: int, units: int, revenue_usd: float, cost_usd: float, profit_usd: float, margin_pct: float}
     */
    private function presentRow(array $row): array
    {
        $revenue = round((float) ($row['revenue_usd'] ?? 0), 2);
        $cost = round((float) ($row['cost_usd'] ?? 0), 2);
        $profit = round($revenue - $cost, 2);

        return [
            'orders' => (int) ($row['orders_count'] ?? 0),
            'units' => (int) ($row['units'] ?? 0),
            'revenue_usd' => $revenue,
            'cost_usd' => $cost,
            'profit_usd' => $profit,
            'margin_pct' => $revenue > 0 ? round(($profit / $revenue) * 100, 2) : 0.0,
        ];
    }

    /**
     * Company totals for the window, in one aggregate query. Orders are
     * counted DISTINCT here rather than summed from the provider rows — a
     * mixed-provider order would otherwise be counted once per provider.
     *
     * @return array{orders: int, units: int, revenue_usd: float, cost_usd: float, profit_usd: float, margin_pct: float}
     */
    private function totalsRow(Carbon $start, Carbon $end): array
    {
        $row = $this->completedItemsQuery($start, $end)
            ->select(
                DB::raw('COUNT(DISTINCT orders.id) as orders_count'),
                DB::raw('SUM(order_items.quantity) as units'),
                DB::raw('SUM('.$this->metrics->itemUsdSql().') as revenue_usd'),
                DB::raw('SUM(order_items.provider_cost_usd * order_items.quantity) as cost_usd'),
            )
            ->first();

        return $this->presentRow((array) $row);
    }

    private function providerLabel(string $provider): string
    {
        return match ($provider) {
            'zendit' => 'Zendit',
            'airalo' => 'Airalo',
            'manual' => 'Manual fulfilment',
            'unknown' => 'Unattributed',
            default => ucfirst($provider),
        };
    }
}
