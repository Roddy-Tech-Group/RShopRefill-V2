<?php

namespace App\Domain\Admin\Support;

use App\Models\OrderItem;
use App\Models\OrderRefund;
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
 * Finances already worked this way. This is the same reading, extracted so
 * Overview and Reports can share it instead of each page inventing its own
 * idea of what a month earned.
 */
class RefundAdjustments
{
    /**
     * Revenue reversed by customer refunds in the window. Null bounds mean
     * all time, matching the all-time figures on the Overview cards.
     */
    public function customerRefundsUsd(?Carbon $start = null, ?Carbon $end = null): float
    {
        return (float) OrderRefund::query()
            ->where('kind', OrderRefund::KIND_CUSTOMER_REFUND)
            ->when($start, fn ($q) => $q->where('refunded_at', '>=', $start))
            ->when($end, fn ($q) => $q->where('refunded_at', '<=', $end))
            ->sum('revenue_reversed_usd');
    }

    /**
     * Supplier credits granted in the window, which reduce what a period cost
     * us.
     */
    public function supplierCreditsUsd(?Carbon $start = null, ?Carbon $end = null): float
    {
        return (float) OrderRefund::query()
            ->where('supplier_credit_status', OrderRefund::CREDIT_CREDITED)
            ->whereNotNull('supplier_credited_at')
            ->when($start, fn ($q) => $q->where('supplier_credited_at', '>=', $start))
            ->when($end, fn ($q) => $q->where('supplier_credited_at', '<=', $end))
            ->sum('supplier_credit_usd');
    }

    /**
     * Customer refunds grouped into date buckets, for the chart and table
     * series. Keys are Y-m-d bucket starts matching the caller's granularity.
     *
     * @return array<string, float>
     */
    public function customerRefundsByBucket(Carbon $start, Carbon $end, string $granularity = 'daily'): array
    {
        return $this->bucket(
            OrderRefund::query()
                ->where('kind', OrderRefund::KIND_CUSTOMER_REFUND)
                ->whereBetween('refunded_at', [$start, $end])
                ->get(['refunded_at', 'revenue_reversed_usd']),
            'refunded_at',
            'revenue_reversed_usd',
            $granularity,
        );
    }

    /**
     * Supplier credits in the same bucket shape.
     *
     * @return array<string, float>
     */
    public function supplierCreditsByBucket(Carbon $start, Carbon $end, string $granularity = 'daily'): array
    {
        return $this->bucket(
            OrderRefund::query()
                ->where('supplier_credit_status', OrderRefund::CREDIT_CREDITED)
                ->whereNotNull('supplier_credited_at')
                ->whereBetween('supplier_credited_at', [$start, $end])
                ->get(['supplier_credited_at', 'supplier_credit_usd']),
            'supplier_credited_at',
            'supplier_credit_usd',
            $granularity,
        );
    }

    /**
     * Revenue reversed per ISO country code, read from each refund's own
     * per-item allocations so a refund lands against the country that made
     * the sale rather than being spread evenly.
     *
     * @return array<string, float>
     */
    public function customerRefundsByCountry(Carbon $start, Carbon $end): array
    {
        $refunds = OrderRefund::query()
            ->where('kind', OrderRefund::KIND_CUSTOMER_REFUND)
            ->whereBetween('refunded_at', [$start, $end])
            ->whereNotNull('allocations')
            ->get(['allocations']);

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
            foreach ((array) $refund->allocations as $allocation) {
                $itemId = $allocation['order_item_id'] ?? null;
                $country = $itemId === null ? null : ($countryByItem[$itemId] ?? null);

                if (! is_string($country) || strlen($country) !== 2) {
                    continue;
                }

                $byCountry[$country] = ($byCountry[$country] ?? 0.0)
                    + (float) ($allocation['revenue_reversed_usd'] ?? 0);
            }
        }

        return $byCountry;
    }

    /**
     * @param  Collection<int, OrderRefund>  $rows
     * @return array<string, float>
     */
    private function bucket(Collection $rows, string $dateField, string $amountField, string $granularity): array
    {
        $buckets = [];

        foreach ($rows as $row) {
            $date = $row->{$dateField};

            if (! $date instanceof Carbon) {
                continue;
            }

            $key = match ($granularity) {
                'weekly' => $date->copy()->startOfWeek()->format('Y-m-d'),
                'monthly' => $date->copy()->startOfMonth()->format('Y-m-d'),
                default => $date->copy()->startOfDay()->format('Y-m-d'),
            };

            $buckets[$key] = ($buckets[$key] ?? 0.0) + (float) $row->{$amountField};
        }

        return $buckets;
    }
}
