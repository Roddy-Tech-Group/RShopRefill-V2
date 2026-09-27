<?php

namespace App\Domain\Admin\Support;

use App\Domain\Order\Enums\OrderStatus;
use App\Models\OrderItem;
use App\Models\OrderRefund;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * What the refund ledger takes back off a period's figures.
 *
 * Two adjustments, each dated by when it actually happened rather than when
 * the sale did, which is the whole point of keeping a ledger:
 *
 *   - a CUSTOMER refund reverses revenue on the day the customer got their
 *     money back. An eSIM sold in September and refunded in October is
 *     September revenue and an October deduction, because that is what
 *     happened. A fulfilment reversal reverses nothing: the item never
 *     delivered, so it was never booked as revenue in the first place, and
 *     callers exclude failed items from sales anyway.
 *
 *   - a SUPPLIER credit reduces cost on the day the supplier granted it,
 *     which is not the day we refunded the customer, because the monthly
 *     supplier invoice follows the supplier's date.
 *
 * Both only count against a sale that was booked, meaning its order
 * completed. A refund on an order that never completed gives back money for a
 * sale no page ever counted, so there is nothing for it to reverse. And
 * neither can land before the sale it reverses: one taken while the order
 * still waited on the supplier counts from the day the order completed, so a
 * month that has closed never changes when a late order finally delivers.
 *
 * Revenue is what the customer paid in cash, never the part paid with Rcoin
 * (DashboardMetricsQuery::itemCashUsdSql). The ledger records what a refund
 * reversed at the item's full price, so every figure here takes off only its
 * order's cash share of that (Order::cashShare). A refund can never remove
 * revenue that was never counted. Supplier credits are cost and count in
 * full.
 *
 * Finances, Overview, Reports, Trends, the map and the category chart all read
 * the ledger through this class, so no page can invent its own idea of what a
 * month earned.
 */
class RefundAdjustments
{
    /**
     * Customer refunds that reverse booked revenue and count inside the
     * window (see onBookedSales for the day a refund counts on). Null bounds
     * mean all time. Callers window through here, never on refunded_at
     * directly, or a refund taken before its order completed would land in
     * the wrong month.
     *
     * @return Builder<OrderRefund>
     */
    public function customerRefunds(?Carbon $start = null, ?Carbon $end = null): Builder
    {
        return $this->onBookedSales(
            OrderRefund::query()->where('kind', OrderRefund::KIND_CUSTOMER_REFUND),
            'refunded_at',
            $start,
            $end,
        );
    }

    /**
     * Supplier credits granted against booked cost that count inside the
     * window, on the same terms as customerRefunds().
     *
     * @return Builder<OrderRefund>
     */
    public function supplierCredits(?Carbon $start = null, ?Carbon $end = null): Builder
    {
        return $this->onBookedSales(
            OrderRefund::query()
                ->where('supplier_credit_status', OrderRefund::CREDIT_CREDITED)
                ->whereNotNull('supplier_credited_at'),
            'supplier_credited_at',
            $start,
            $end,
        );
    }

    /**
     * Revenue reversed by customer refunds in the window, the cash share of
     * each. Null bounds mean all time, matching the all-time figures on the
     * Overview cards.
     */
    public function customerRefundsUsd(?Carbon $start = null, ?Carbon $end = null): float
    {
        return (float) $this->customerRefunds($start, $end)
            ->with('order:id,metadata')
            ->get(['id', 'order_id', 'revenue_reversed_usd'])
            ->sum(fn (OrderRefund $refund) => (float) $refund->revenue_reversed_usd * $this->cashShareOf($refund));
    }

    /**
     * Supplier credits granted in the window, which reduce what a period cost
     * us.
     */
    public function supplierCreditsUsd(?Carbon $start = null, ?Carbon $end = null): float
    {
        return (float) $this->supplierCredits($start, $end)->sum('supplier_credit_usd');
    }

    /**
     * Customer refunds grouped into date buckets, for the chart and table
     * series. Keys are Y-m-d bucket starts matching the caller's granularity.
     *
     * Given a category, only the part of each refund that reversed that
     * category's items counts, read from the refund's own allocations.
     *
     * @return array<string, float>
     */
    public function customerRefundsByBucket(Carbon $start, Carbon $end, string $granularity = 'daily', ?int $categoryId = null): array
    {
        $categoryIds = $categoryId === null ? null : [$categoryId];

        return $this->bucket(
            $this->customerRefunds($start, $end)
                ->with('order:id,completed_at,metadata')
                ->get(['order_id', 'refunded_at', 'revenue_reversed_usd', 'allocations']),
            'refunded_at',
            fn (OrderRefund $refund) => $this->revenueReversed($refund, $categoryIds),
            $granularity,
        );
    }

    /**
     * Supplier credits in the same bucket shape. Given a category, only that
     * category's share of each credit counts, split the way Finances splits
     * it (creditByAllocation).
     *
     * @return array<string, float>
     */
    public function supplierCreditsByBucket(Carbon $start, Carbon $end, string $granularity = 'daily', ?int $categoryId = null): array
    {
        $categoryIds = $categoryId === null ? null : [$categoryId];

        return $this->bucket(
            $this->supplierCredits($start, $end)
                ->with('order:id,completed_at')
                ->get(['order_id', 'supplier_credited_at', 'supplier_credit_usd', 'allocations']),
            'supplier_credited_at',
            fn (OrderRefund $credit) => $this->creditRecovered($credit, $categoryIds),
            $granularity,
        );
    }

    /**
     * Customer refunds per date bucket and per category id, for the revenue
     * by category chart. A refund lands in the category of each item it
     * reversed, on the day it counts (see onBookedSales).
     *
     * @return array<string, array<int, float>>
     */
    public function customerRefundsByCategory(Carbon $start, Carbon $end, string $granularity = 'daily'): array
    {
        $refunds = $this->customerRefunds($start, $end)
            ->with('order:id,completed_at,metadata')
            ->get(['order_id', 'refunded_at', 'allocations']);

        $buckets = [];

        foreach ($refunds as $refund) {
            $countsOn = $this->countsOn($refund, 'refunded_at');

            if ($countsOn === null) {
                continue;
            }

            $key = $this->bucketKey($countsOn, $granularity);
            $cashShare = $this->cashShareOf($refund);

            foreach ((array) $refund->allocations as $allocation) {
                $categoryId = $allocation['category_id'] ?? null;

                if ($categoryId === null) {
                    continue;
                }

                $buckets[$key][(int) $categoryId] = ($buckets[$key][(int) $categoryId] ?? 0.0)
                    + (float) ($allocation['revenue_reversed_usd'] ?? 0) * $cashShare;
            }
        }

        return $buckets;
    }

    /**
     * Revenue reversed per ISO country code, read from each refund's own
     * per-item allocations so a refund lands against the country that made
     * the sale rather than being spread evenly. Given category ids, only
     * allocations in those categories count.
     *
     * @param  list<int>|null  $categoryIds
     * @return array<string, float>
     */
    public function customerRefundsByCountry(Carbon $start, Carbon $end, ?array $categoryIds = null): array
    {
        $refunds = $this->customerRefunds($start, $end)
            ->whereNotNull('allocations')
            ->with('order:id,metadata')
            ->get(['order_id', 'allocations']);

        if ($refunds->isEmpty()) {
            return [];
        }

        $itemIds = $refunds
            ->flatMap(fn (OrderRefund $refund) => array_column((array) $refund->allocations, 'order_item_id'))
            ->filter()
            ->unique()
            ->all();

        if ($itemIds === []) {
            return [];
        }

        // The country a sale was booked against is the snapshot taken at sale
        // time, the same source the map aggregates, so a product that later
        // changes country cannot rewrite history. Read through the model's
        // array cast rather than a JSON function: MariaDB and the sqlite the
        // suite runs on do not spell those the same way.
        $countryByItem = OrderItem::query()
            ->whereIn('id', $itemIds)
            ->get(['id', 'product_snapshot'])
            ->mapWithKeys(fn (OrderItem $item) => [
                $item->id => strtoupper(trim((string) (($item->product_snapshot['country_code'] ?? '')))),
            ])
            ->all();

        $byCountry = [];

        foreach ($refunds as $refund) {
            $cashShare = $this->cashShareOf($refund);

            foreach ((array) $refund->allocations as $allocation) {
                if (! $this->inCategories($allocation, $categoryIds)) {
                    continue;
                }

                $itemId = $allocation['order_item_id'] ?? null;
                $country = $itemId === null ? null : ($countryByItem[$itemId] ?? null);

                if (! is_string($country) || strlen($country) !== 2) {
                    continue;
                }

                $byCountry[$country] = ($byCountry[$country] ?? 0.0)
                    + (float) ($allocation['revenue_reversed_usd'] ?? 0) * $cashShare;
            }
        }

        return $byCountry;
    }

    /**
     * How a supplier credit divides across the items its refund covered: in
     * the same proportion their revenue was reversed, evenly when none was.
     * Finances splits its provider and category rows with this and Reports
     * narrows to a category with it, so the two cannot place a credit
     * differently.
     *
     * A credit whose refund carries no allocations cannot be placed in any
     * category. It still counts in every unfiltered total.
     *
     * @return list<array{allocation: array<string, mixed>, credit_usd: float}>
     */
    public function creditByAllocation(OrderRefund $credit): array
    {
        $amount = (float) $credit->supplier_credit_usd;
        $allocations = array_values(array_filter((array) $credit->allocations, 'is_array'));
        $weight = array_sum(array_map(fn (array $allocation) => (float) ($allocation['revenue_reversed_usd'] ?? 0), $allocations));

        return array_map(fn (array $allocation) => [
            'allocation' => $allocation,
            'credit_usd' => $weight > 0
                ? $amount * ((float) ($allocation['revenue_reversed_usd'] ?? 0) / $weight)
                : $amount / max(1, count($allocations)),
        ], $allocations);
    }

    /**
     * The share of a refund's reversed revenue that was ever counted: its
     * order's cash share, the same share the sale was booked at. The row's
     * order must be loaded with its metadata.
     */
    public function cashShareOf(OrderRefund $refund): float
    {
        return $refund->order?->cashShare() ?? 1.0;
    }

    /**
     * Narrow a ledger query to refunds of booked sales that count inside the
     * window.
     *
     * Booked means the order's status is a booked one AND it completed. That
     * is exactly the rule every page books revenue by, so a refund can only
     * ever reverse something that was counted. An order refunded in full
     * while it still waited on the supplier is `refunded` but has no
     * completed_at, and stays out.
     *
     * A row counts on the later of its own date and the day its order
     * completed. A refund is nearly always taken after the sale, so that is
     * its own date. One taken while the order still waited on the supplier
     * reverses a sale that was only booked when the order completed, so it
     * counts then too. Dated by its own day it would appear in an earlier
     * month the moment the order completed, and change a month that had
     * already closed.
     *
     * @param  Builder<OrderRefund>  $query
     * @return Builder<OrderRefund>
     */
    private function onBookedSales(Builder $query, string $dateColumn, ?Carbon $start, ?Carbon $end): Builder
    {
        $ownDate = $query->qualifyColumn($dateColumn);

        return $query
            // A row never counts before its own date, so the window's end
            // bounds that date too, where the ledger's date index can use it.
            ->when($end, fn (Builder $q) => $q->where($ownDate, '<=', $end))
            ->whereHas('order', function (Builder $order) use ($ownDate, $start, $end): void {
                $completedAt = $order->qualifyColumn('completed_at');
                $countsOn = "CASE WHEN {$completedAt} > {$ownDate} THEN {$completedAt} ELSE {$ownDate} END";

                $order->whereIn('order_status', OrderStatus::booked())
                    ->whereNotNull($completedAt)
                    ->when($start, fn (Builder $q) => $q->whereRaw("{$countsOn} >= ?", [$start]))
                    ->when($end, fn (Builder $q) => $q->whereRaw("{$countsOn} <= ?", [$end]));
            });
    }

    /**
     * The day a ledger row counts on, by the same rule onBookedSales windows
     * with. The row's order must be loaded with its completed_at.
     */
    private function countsOn(OrderRefund $row, string $dateField): ?Carbon
    {
        $ownDate = $row->{$dateField};

        if (! $ownDate instanceof Carbon) {
            return null;
        }

        $completedAt = $row->order?->completed_at;

        return $completedAt instanceof Carbon && $completedAt->gt($ownDate) ? $completedAt : $ownDate;
    }

    /**
     * The revenue a refund reversed, or only the part that reversed items in
     * the given categories, at its order's cash share.
     *
     * @param  list<int>|null  $categoryIds
     */
    private function revenueReversed(OrderRefund $refund, ?array $categoryIds): float
    {
        if ($categoryIds === null) {
            return (float) $refund->revenue_reversed_usd * $this->cashShareOf($refund);
        }

        $reversed = 0.0;

        foreach ((array) $refund->allocations as $allocation) {
            if ($this->inCategories($allocation, $categoryIds)) {
                $reversed += (float) ($allocation['revenue_reversed_usd'] ?? 0);
            }
        }

        return $reversed * $this->cashShareOf($refund);
    }

    /**
     * The cost a supplier credit recovered, or only the given categories'
     * share of it.
     *
     * @param  list<int>|null  $categoryIds
     */
    private function creditRecovered(OrderRefund $credit, ?array $categoryIds): float
    {
        if ($categoryIds === null) {
            return (float) $credit->supplier_credit_usd;
        }

        $recovered = 0.0;

        foreach ($this->creditByAllocation($credit) as $share) {
            if ($this->inCategories($share['allocation'], $categoryIds)) {
                $recovered += $share['credit_usd'];
            }
        }

        return $recovered;
    }

    /**
     * @param  list<int>|null  $categoryIds  null means every category
     */
    private function inCategories(mixed $allocation, ?array $categoryIds): bool
    {
        if ($categoryIds === null) {
            return true;
        }

        $categoryId = is_array($allocation) ? ($allocation['category_id'] ?? null) : null;

        return $categoryId !== null && in_array((int) $categoryId, $categoryIds, true);
    }

    /**
     * @param  Collection<int, OrderRefund>  $rows
     * @param  callable(OrderRefund): float  $amountOf
     * @return array<string, float>
     */
    private function bucket(Collection $rows, string $dateField, callable $amountOf, string $granularity): array
    {
        $buckets = [];

        foreach ($rows as $row) {
            $date = $this->countsOn($row, $dateField);

            if ($date === null) {
                continue;
            }

            $key = $this->bucketKey($date, $granularity);

            $buckets[$key] = ($buckets[$key] ?? 0.0) + $amountOf($row);
        }

        return $buckets;
    }

    private function bucketKey(Carbon $date, string $granularity): string
    {
        return match ($granularity) {
            'weekly' => $date->copy()->startOfWeek()->format('Y-m-d'),
            'monthly' => $date->copy()->startOfMonth()->format('Y-m-d'),
            default => $date->copy()->startOfDay()->format('Y-m-d'),
        };
    }
}
