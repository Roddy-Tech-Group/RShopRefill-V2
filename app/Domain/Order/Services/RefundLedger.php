<?php

namespace App\Domain\Order\Services;

use App\Domain\Fulfillment\Enums\FulfillmentStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderRefund;
use Illuminate\Support\Collection;

/**
 * Writes the refund ledger (order_refunds): one row per refund, recorded once.
 *
 * Called from OrderService::applyRefundRecord, the one place every refund
 * already passes through (admin refunds, the automatic fulfilment-failure
 * reversals, and eSIM refunds), so no refund can reach a customer without
 * reaching the ledger. Idempotent on (order, tag), exactly like the metadata
 * record it sits beside: a retried job records nothing twice.
 *
 * The one judgement it makes is how much REVENUE a refund unwinds, and it
 * answers from what was captured, not from the order total:
 *
 *   fraction  = amount / (captured share of the items being refunded), max 1
 *   reversed  = fraction x (the revenue booked for those items)
 *
 * Captured share, not the order total, because the order total is wrong in
 * both directions that matter:
 *   - A discounted item captured less than its list price. An $8 payment for a
 *     $10 item, fully refunded, must reverse the whole $10 that was booked,
 *     or $2 of revenue survives a refund that gave everything back.
 *   - A failed item was already reversed and was never booked as revenue.
 *     Refunding the delivered item of a half-failed order must reverse all of
 *     it, not a fraction diluted by the failed item still sitting in the total.
 *
 * Revenue is booked at item subtotal / the order's exchange rate, the rule
 * DashboardMetricsQuery::itemUsdSql() applies, so a refund unwinds a sale at
 * the exact rate it was booked at and no exchange-rate noise leaks in.
 */
class RefundLedger
{
    public function __construct(private readonly GatewayRefundService $refunds) {}

    /**
     * Record a refund. Returns the ledger row, the existing one when this tag
     * was already recorded for the order.
     *
     * @param  array{
     *     kind?: string,
     *     order_item_id?: string|null,
     *     gateway?: string|null,
     *     reason?: string|null,
     *     refunded_by?: int|null,
     *     refunded_at?: \DateTimeInterface|null,
     *     supplier_credit_status?: string,
     *     supplier_credit_reference?: string|null,
     *     supplier_credit_reason?: string|null,
     *     supplier_credit_usd?: float|null,
     *     supplier_credited_at?: \DateTimeInterface|null,
     *     metadata?: array<string, mixed>
     * }  $context
     */
    public function record(Order $order, float $amount, string $tag, array $context = []): OrderRefund
    {
        $existing = OrderRefund::query()->where('order_id', $order->getKey())->where('tag', $tag)->first();

        if ($existing) {
            return $existing;
        }

        // createOrFirst, not firstOrCreate: two paths can record the same tag
        // at the same moment; the unique index decides, and the loser reads
        // the winner's row instead of failing the refund.
        return OrderRefund::createOrFirst(
            ['order_id' => $order->getKey(), 'tag' => $tag],
            $this->attributesFor($order, $amount, $tag, $context),
        );
    }

    /**
     * The row record() would write, without writing it. The backfill's dry
     * run reads this so it can report exactly what a real run would book.
     *
     * @param  array<string, mixed>  $context  same keys as record()
     * @return array<string, mixed>
     */
    public function attributesFor(Order $order, float $amount, string $tag, array $context = []): array
    {
        $kind = $context['kind'] ?? self::kindForTag($tag);
        $itemId = $context['order_item_id'] ?? self::itemIdForTag($tag);

        $allocations = $kind === OrderRefund::KIND_CUSTOMER_REFUND
            ? $this->allocate($order, round($amount, 2), $itemId)
            : [];

        return [
            'order_item_id' => $itemId,
            'user_id' => $order->user_id,
            'kind' => $kind,
            'amount' => round($amount, 4),
            'currency' => strtoupper((string) ($order->display_currency ?: 'USD')),
            'revenue_reversed_usd' => round(array_sum(array_column($allocations, 'revenue_reversed_usd')), 4),
            'allocations' => $allocations,
            'gateway' => $context['gateway'] ?? null,
            'reason' => $context['reason'] ?? null,
            'refunded_by' => $context['refunded_by'] ?? null,
            'refunded_at' => $context['refunded_at'] ?? now(),
            'supplier_credit_status' => $context['supplier_credit_status'] ?? OrderRefund::CREDIT_NOT_APPLICABLE,
            'supplier_credit_reference' => $context['supplier_credit_reference'] ?? null,
            'supplier_credit_reason' => $context['supplier_credit_reason'] ?? null,
            'supplier_credit_usd' => $context['supplier_credit_usd'] ?? null,
            'supplier_credited_at' => $context['supplier_credited_at'] ?? null,
            'metadata' => $context['metadata'] ?? null,
        ];
    }

    /**
     * Money back for goods the customer received reverses revenue; everything
     * else is the automatic reversal of an item that never delivered.
     *
     * Tags in use: admin-{uuid} (an admin refund), esim-{uuid} (an eSIM
     * refund), and the reversals settle-{order}, final-{order}, item-{item}
     * and release-item-{item}.
     */
    public static function kindForTag(string $tag): string
    {
        return str_starts_with($tag, 'admin-') || str_starts_with($tag, 'esim-')
            ? OrderRefund::KIND_CUSTOMER_REFUND
            : OrderRefund::KIND_FULFILMENT_REVERSAL;
    }

    /**
     * The item a reversal names in its own tag (item-{id}, release-item-{id}).
     */
    public static function itemIdForTag(string $tag): ?string
    {
        foreach (['release-item-', 'item-'] as $prefix) {
            if (str_starts_with($tag, $prefix)) {
                return substr($tag, strlen($prefix)) ?: null;
            }
        }

        return null;
    }

    /**
     * Split the revenue a customer refund unwinds across the items it covers.
     *
     * @return array<int, array{order_item_id: string, provider: string, category_id: int|null, revenue_reversed_usd: float}>
     */
    private function allocate(Order $order, float $amount, ?string $itemId): array
    {
        if ($amount <= 0) {
            return [];
        }

        $items = $this->bookedItems($order, $itemId);

        if ($items->isEmpty()) {
            return [];
        }

        $captured = (float) $order->total_amount;
        $basis = round((float) $items->sum(fn (OrderItem $item) => $this->refunds->capturedShareForItem($order, $item, $captured)), 2);

        // Nothing was captured for these items, so nothing can be unwound.
        if ($basis <= 0) {
            return [];
        }

        $fraction = min(1.0, $amount / $basis);
        $rate = self::exchangeRate($order);

        return $items
            ->map(fn (OrderItem $item) => [
                'order_item_id' => (string) $item->getKey(),
                'provider' => self::providerFor($item),
                'category_id' => $item->category_id !== null ? (int) $item->category_id : null,
                'revenue_reversed_usd' => round($fraction * ((float) $item->subtotal_amount / $rate), 4),
            ])
            ->values()
            ->all();
    }

    /**
     * The items whose revenue a refund can reverse: the named item, or every
     * item on the order that was not a failed delivery. A failed item was
     * never booked, so it has nothing to unwind.
     *
     * @return Collection<int, OrderItem>
     */
    private function bookedItems(Order $order, ?string $itemId): Collection
    {
        return $order->items()
            ->with('product:id,provider_name')
            ->when($itemId !== null, fn ($query) => $query->whereKey($itemId))
            ->where('fulfillment_status', '!=', FulfillmentStatus::Failed->value)
            ->orderBy('id')
            ->get();
    }

    /**
     * The same rate the Finances page books revenue at: the order's snapshot,
     * falling back to 1 when it is missing or zero.
     */
    public static function exchangeRate(Order $order): float
    {
        $rate = (float) data_get($order->metadata, 'exchange_rate', 1);

        return $rate > 0 ? $rate : 1.0;
    }

    /**
     * The partner we pay, which the Finances page prefers over the item's own
     * provider_name (for top-ups that can carry the telecom operator).
     */
    public static function providerFor(OrderItem $item): string
    {
        $provider = trim((string) ($item->product?->provider_name ?: $item->provider_name ?: 'unknown'));

        return strtolower($provider !== '' ? $provider : 'unknown');
    }
}
