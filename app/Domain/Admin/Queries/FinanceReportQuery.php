<?php

namespace App\Domain\Admin\Queries;

use App\Domain\Admin\Support\RefundAdjustments;
use App\Domain\Fulfillment\Enums\FulfillmentStatus;
use App\Domain\Order\Enums\OrderStatus;
use App\Models\OrderRefund;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Company-finance aggregations for the Management → Finances page: the
 * monthly P&L per fulfilment provider and per product category.
 *
 *   Gross revenue      the cash paid for delivered items on orders
 *                      completed in the month
 *   less Refunds       customer refunds recorded in the month
 *   = Revenue          (net)
 *   Supplier cost      what those delivered items cost us
 *   less Credits       supplier credits granted in the month
 *   = Cost             (net)
 *   Gross profit       Revenue - Cost
 *
 * Revenue is what the customer paid in cash. The part of an order paid with
 * Rcoin (reward points) is not revenue, so each item counts at its order's
 * cash share (DashboardMetricsQuery::itemCashUsdSql), and an order paid
 * entirely with Rcoin earns nothing while its supplier cost still counts. A
 * coupon needs no share, because checkout lowers the item's own price for it.
 *
 * Four rules keep a closed month closed and the page true to the supplier's
 * own invoice:
 *
 *   1. Revenue is booked when an order completes and never un-booked. An
 *      order refunded later keeps its completed_at, so it still counts in the
 *      month it sold. (It used to vanish: a full refund set the order to
 *      `refunded`, the page read `completed` only, and a closed month's revenue
 *      and cost silently changed.)
 *   2. Only delivered items are booked. A partially fulfilled order is marked
 *      `completed`, and its failed items (never delivered, already reversed)
 *      used to count as revenue and cost.
 *   3. A refund lands in the month it was issued and a supplier credit in the
 *      month it was granted, each from the refund ledger (order_refunds). The
 *      Airalo invoice for a month is that month's eSIMs less that month's
 *      credits, so the supplier cost line here reconciles to it.
 *   4. A refund or credit only counts against a sale that was booked (rule 1
 *      applied to its order), and never before that sale. An order refunded
 *      in full before it ever completed sold nothing, so its refund reverses
 *      nothing. If the order completes after all, a refund or credit (full or
 *      partial) taken before that lands in the month the order completed,
 *      next to the sale it reverses. Its own date would put it in a month
 *      that had already closed, so this is the one case where rule 3 gives
 *      way.
 *
 * USD conversion is DashboardMetricsQuery::itemCashUsdSql() (item subtotal
 * over the order's exchange-rate snapshot, times the order's cash share), and
 * a refund reverses revenue at that same rate (RefundLedger), so no
 * exchange-rate noise enters a refund. The ledger records what a refund
 * reversed at the item's full price; RefundAdjustments applies the same cash
 * share to it, so a refund never takes off revenue that was never counted.
 *
 * The ledger is read through RefundAdjustments, which Overview, Reports and
 * Trends read too, so rule 4 and the way a credit splits across categories
 * are the same everywhere. Reports and Trends book sales exactly as this page
 * does, and for a calendar month they sum to its totals; Reports narrowed to
 * a category matches that category's row here. The Overview revenue card
 * uses the same basis (delivered items on completed orders, less refunds and
 * credits on the later of their own date and the order's completion, see
 * rule 4) but windows its sales by the order's
 * created_at rather than completed_at, so an order placed in one month and
 * completed in the next lands in a different month there.
 *
 * Profit is GROSS profit (revenue - supplier cost). Gateway fees and operating
 * costs are not captured per payment yet, so a "net profit" claim would be
 * dishonest; the page labels the figure accordingly.
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

    public function __construct(
        private DashboardMetricsQuery $metrics,
        private RefundAdjustments $refunds,
    ) {}

    /**
     * Full monthly breakdown for the Finances page.
     *
     * Every row carries both the gross and the net figures: gross_revenue_usd,
     * refunds_usd, revenue_usd (net), gross_cost_usd, supplier_credits_usd,
     * cost_usd (net), profit_usd and margin_pct.
     *
     * @return array{
     *     month: string,
     *     totals: array<string, int|float>,
     *     previous_totals: array<string, int|float>,
     *     providers: array<int, array<string, int|float|string>>,
     *     categories: array<int, array<string, int|float|string>>,
     *     refunds: array{count: int, total_usd: float},
     *     supplier_credits: array{count: int, total_usd: float, pending_count: int}
     * }
     */
    public function monthlyBreakdown(Carbon $month): array
    {
        $start = $month->copy()->startOfMonth();
        $end = $month->copy()->endOfMonth();

        $previousStart = $start->copy()->subMonthNoOverflow()->startOfMonth();
        $previousEnd = $previousStart->copy()->endOfMonth();

        $adjustments = $this->adjustmentsFor($start, $end);

        return [
            'month' => $start->format('Y-m'),
            'totals' => $this->totalsRow($start, $end, $adjustments),
            'previous_totals' => $this->totalsRow($previousStart, $previousEnd, $this->adjustmentsFor($previousStart, $previousEnd)),
            'providers' => $this->providerRows($start, $end, $adjustments),
            'categories' => $this->categoryRows($start, $end, $adjustments),
            'refunds' => [
                'count' => $adjustments['refund_count'],
                'total_usd' => round($adjustments['refunds_usd'], 2),
            ],
            'supplier_credits' => [
                'count' => $adjustments['credit_count'],
                'total_usd' => round($adjustments['credits_usd'], 2),
                'pending_count' => $this->pendingSupplierCredits(),
            ],
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
     * @param  array<string, mixed>  $adjustments
     * @return array<int, array<string, int|float|string>>
     */
    private function providerRows(Carbon $start, Carbon $end, array $adjustments): array
    {
        $sales = $this->bookedItemsQuery($start, $end)
            ->leftJoin('products', 'order_items.product_id', '=', 'products.id')
            ->select(
                DB::raw("LOWER(COALESCE(NULLIF(products.provider_name, ''), NULLIF(order_items.provider_name, ''), 'unknown')) as provider"),
                DB::raw('COUNT(DISTINCT orders.id) as orders_count'),
                DB::raw('SUM(order_items.quantity) as units'),
                DB::raw('SUM('.$this->metrics->itemCashUsdSql().') as revenue_usd'),
                DB::raw('SUM(order_items.provider_cost_usd * order_items.quantity) as cost_usd'),
            )
            ->groupBy('provider')
            ->get()
            ->keyBy(fn ($row) => (string) $row->provider);

        // Every provider that sold, was refunded, or received a credit this
        // month, plus the core partners, which always appear zero-filled:
        // "Airalo: 0" is information; a missing row is a question.
        $keys = collect(self::CORE_PROVIDERS)
            ->merge($sales->keys())
            ->merge(array_keys($adjustments['providers']))
            ->unique();

        return $keys
            ->map(fn (string $provider) => $this->presentRow(
                (array) ($sales[$provider] ?? []),
                $adjustments['providers'][$provider] ?? [],
            ) + [
                'provider' => $provider,
                'label' => $this->providerLabel($provider),
            ])
            ->sortBy([['revenue_usd', 'desc'], ['label', 'asc']])
            ->values()
            ->all();
    }

    /**
     * The same money cut grouped by product category (gift cards, eSIMs, …).
     *
     * @param  array<string, mixed>  $adjustments
     * @return array<int, array<string, int|float|string>>
     */
    private function categoryRows(Carbon $start, Carbon $end, array $adjustments): array
    {
        $sales = $this->bookedItemsQuery($start, $end)
            ->join('categories', 'order_items.category_id', '=', 'categories.id')
            ->select(
                'categories.name as category',
                DB::raw('COUNT(DISTINCT orders.id) as orders_count'),
                DB::raw('SUM(order_items.quantity) as units'),
                DB::raw('SUM('.$this->metrics->itemCashUsdSql().') as revenue_usd'),
                DB::raw('SUM(order_items.provider_cost_usd * order_items.quantity) as cost_usd'),
            )
            ->groupBy('categories.name')
            ->get()
            ->keyBy(fn ($row) => (string) $row->category);

        // The canonical product lines (gift cards, eSIMs, top-ups, bills)
        // always appear, zero-filled, so best-seller comparisons read at a
        // glance instead of by noticing what's absent.
        $coreCategories = DB::table('categories')
            ->whereIn('slug', self::CORE_CATEGORY_SLUGS)
            ->pluck('name')
            ->map(fn ($name) => (string) $name);

        $keys = $coreCategories
            ->merge($sales->keys())
            ->merge(array_keys($adjustments['categories']))
            ->unique();

        return $keys
            ->map(fn (string $category) => $this->presentRow(
                (array) ($sales[$category] ?? []),
                $adjustments['categories'][$category] ?? [],
            ) + ['category' => $category])
            ->sortBy([['revenue_usd', 'desc'], ['category', 'asc']])
            ->values()
            ->all();
    }

    /**
     * Company totals for the window, in one aggregate query. Orders are
     * counted DISTINCT here rather than summed from the provider rows — a
     * mixed-provider order would otherwise be counted once per provider.
     *
     * @param  array<string, mixed>  $adjustments
     * @return array<string, int|float>
     */
    private function totalsRow(Carbon $start, Carbon $end, array $adjustments): array
    {
        $row = $this->bookedItemsQuery($start, $end)
            ->select(
                DB::raw('COUNT(DISTINCT orders.id) as orders_count'),
                DB::raw('SUM(order_items.quantity) as units'),
                DB::raw('SUM('.$this->metrics->itemCashUsdSql().') as revenue_usd'),
                DB::raw('SUM(order_items.provider_cost_usd * order_items.quantity) as cost_usd'),
            )
            ->first();

        return $this->presentRow((array) $row, [
            'refunds_usd' => $adjustments['refunds_usd'],
            'credits_usd' => $adjustments['credits_usd'],
        ]);
    }

    /**
     * What the refund ledger moves in the window: customer refunds (revenue
     * unwound, by refunded_at) and supplier credits (cost recovered, by
     * supplier_credited_at), each split by provider and by category. A row
     * taken before its order completed counts from the completion instead
     * (rule 4).
     *
     * Fulfilment reversals are left out on purpose. They return money for an
     * item that never delivered, and a never-delivered item is not booked as
     * revenue (bookedItemsQuery), so there is nothing for them to reverse.
     * For the same reason a refund on an order that never completed is left
     * out (RefundAdjustments applies rule 4).
     *
     * A refund takes off only its order's cash share of the revenue it
     * reversed, the share the sale was booked at. Credits are cost, and
     * count in full.
     *
     * @return array{
     *     refunds_usd: float, refund_count: int, credits_usd: float, credit_count: int,
     *     providers: array<string, array{refunds_usd: float, credits_usd: float}>,
     *     categories: array<string, array{refunds_usd: float, credits_usd: float}>
     * }
     */
    private function adjustmentsFor(Carbon $start, Carbon $end): array
    {
        $result = [
            'refunds_usd' => 0.0,
            'refund_count' => 0,
            'credits_usd' => 0.0,
            'credit_count' => 0,
            'providers' => [],
            'categories' => [],
        ];

        $refunds = $this->refunds->customerRefunds($start, $end)
            ->with('order:id,metadata')
            ->get(['id', 'order_id', 'revenue_reversed_usd', 'allocations']);

        $credits = $this->refunds->supplierCredits($start, $end)
            ->get(['id', 'supplier_credit_usd', 'allocations']);

        $categoryNames = DB::table('categories')
            ->whereIn('id', $refunds->concat($credits)
                ->flatMap(fn (OrderRefund $row) => array_column((array) $row->allocations, 'category_id'))
                ->filter()
                ->unique()
                ->values()
                ->all())
            ->pluck('name', 'id');

        foreach ($refunds as $refund) {
            $cashShare = $this->refunds->cashShareOf($refund);

            $result['refunds_usd'] += (float) $refund->revenue_reversed_usd * $cashShare;
            $result['refund_count']++;

            foreach ((array) $refund->allocations as $allocation) {
                $this->addTo($result, $allocation, $categoryNames, 'refunds_usd', (float) $allocation['revenue_reversed_usd'] * $cashShare);
            }
        }

        foreach ($credits as $credit) {
            $amount = (float) $credit->supplier_credit_usd;
            $result['credits_usd'] += $amount;
            $result['credit_count']++;

            // A credit recovers the cost of the items the refund covered, in
            // the same proportion their revenue was split. Reports narrows to
            // a category with the same split.
            foreach ($this->refunds->creditByAllocation($credit) as $share) {
                $this->addTo($result, $share['allocation'], $categoryNames, 'credits_usd', $share['credit_usd']);
            }
        }

        return $result;
    }

    /**
     * Add one allocation's amount to its provider and category buckets.
     *
     * @param  array<string, mixed>  $result
     * @param  array<string, mixed>  $allocation
     * @param  Collection<int|string, mixed>  $categoryNames
     */
    private function addTo(array &$result, array $allocation, Collection $categoryNames, string $field, float $amount): void
    {
        $provider = (string) ($allocation['provider'] ?? 'unknown');
        $result['providers'][$provider] ??= ['refunds_usd' => 0.0, 'credits_usd' => 0.0];
        $result['providers'][$provider][$field] += $amount;

        $categoryId = $allocation['category_id'] ?? null;
        $category = $categoryId !== null ? ($categoryNames[$categoryId] ?? null) : null;

        if ($category !== null) {
            $category = (string) $category;
            $result['categories'][$category] ??= ['refunds_usd' => 0.0, 'credits_usd' => 0.0];
            $result['categories'][$category][$field] += $amount;
        }
    }

    /**
     * Supplier claims still waiting on the supplier, across all months: the
     * cost is sunk until they are granted, so the page says how many are out.
     */
    private function pendingSupplierCredits(): int
    {
        return OrderRefund::query()
            ->where('supplier_credit_status', OrderRefund::CREDIT_PENDING)
            ->count();
    }

    /**
     * Base query: the items booked as revenue in the window. Delivered items
     * (anything but a failed delivery) on orders that completed in the window,
     * whatever has happened to the order since.
     */
    private function bookedItemsQuery(Carbon $start, Carbon $end): Builder
    {
        return DB::table('order_items')
            ->join('orders', 'order_items.order_id', '=', 'orders.id')
            ->whereIn('orders.order_status', OrderStatus::booked())
            ->whereNotNull('orders.completed_at')
            ->whereBetween('orders.completed_at', [$start, $end])
            // fulfillment_status is NOT NULL (default not_started), so a plain
            // inequality never meets the SQL NULL trap.
            ->where('order_items.fulfillment_status', '!=', FulfillmentStatus::Failed->value);
    }

    /**
     * Round a raw sales row plus its ledger adjustments into the shared
     * presentation shape: gross, less refunds / credits, net.
     *
     * @param  array<string, mixed>  $row
     * @param  array{refunds_usd?: float, credits_usd?: float}  $adjustment
     * @return array{orders: int, units: int, gross_revenue_usd: float, refunds_usd: float, revenue_usd: float, gross_cost_usd: float, supplier_credits_usd: float, cost_usd: float, profit_usd: float, margin_pct: float}
     */
    private function presentRow(array $row, array $adjustment = []): array
    {
        $grossRevenue = round((float) ($row['revenue_usd'] ?? 0), 2);
        $refunds = round((float) ($adjustment['refunds_usd'] ?? 0), 2);
        $grossCost = round((float) ($row['cost_usd'] ?? 0), 2);
        $credits = round((float) ($adjustment['credits_usd'] ?? 0), 2);

        $revenue = round($grossRevenue - $refunds, 2);
        $cost = round($grossCost - $credits, 2);
        $profit = round($revenue - $cost, 2);

        return [
            'orders' => (int) ($row['orders_count'] ?? 0),
            'units' => (int) ($row['units'] ?? 0),
            'gross_revenue_usd' => $grossRevenue,
            'refunds_usd' => $refunds,
            'revenue_usd' => $revenue,
            'gross_cost_usd' => $grossCost,
            'supplier_credits_usd' => $credits,
            'cost_usd' => $cost,
            'profit_usd' => $profit,
            'margin_pct' => $revenue > 0 ? round(($profit / $revenue) * 100, 2) : 0.0,
        ];
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
