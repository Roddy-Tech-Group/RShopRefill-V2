<?php

namespace App\Domain\Fulfillment\Services;

use App\Domain\Fulfillment\Enums\EsimStatus;
use App\Domain\Fulfillment\Enums\FulfillmentStatus;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Payment\Enums\PaymentStatus;
use App\Models\Esim;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderRefund;
use Illuminate\Support\Collection;

/**
 * Keeps the esims table in step with what we delivered.
 *
 * Called by OrderItemObserver whenever an item's delivery payload is saved,
 * which covers every path that delivers: the fulfilment job, the pending-order
 * poll and an admin's manual delivery. Also run once over history by
 * esims:backfill. Idempotent on (order item, ICCID), so saving the same
 * payload twice records nothing twice.
 */
class EsimRegistry
{
    public const CATEGORY_SLUG = 'esims';

    /**
     * Record every eSIM this item delivered. Returns the item's eSIM rows;
     * empty for anything that is not a delivered eSIM purchase.
     *
     * @return Collection<int, Esim>
     */
    public function recordFor(OrderItem $item): Collection
    {
        if (! $this->isDeliveredEsimPurchase($item)) {
            return collect();
        }

        $item->loadMissing(['order', 'product:id,name']);

        $esims = collect(self::iccidsFrom((array) $item->fulfillment_payload))
            ->map(fn (string $iccid) => Esim::firstOrCreate(
                ['order_item_id' => $item->getKey(), 'iccid' => $iccid],
                [
                    'order_id' => $item->order_id,
                    'user_id' => $item->order?->user_id,
                    'provider' => strtolower((string) $item->provider_name),
                    'package_name' => self::packageNameFor($item),
                    'package_code' => $item->provider_offer_id,
                    'purchased_at' => $item->delivered_at ?? $item->order?->completed_at ?? $item->order?->created_at ?? now(),
                ],
            ))
            ->values();

        // An order already refunded in full (by hand, before this record
        // existed) has nothing left to refund: its eSIMs read Refunded.
        if ($item->order && self::orderIsFullyRefunded($item->order)) {
            self::markOrderEsimsRefunded($item->order);
            $esims->each->refresh();
        }

        return $esims;
    }

    /**
     * The whole order went back to the customer.
     */
    public static function orderIsFullyRefunded(Order $order): bool
    {
        return $order->payment_status === PaymentStatus::Refunded
            || $order->order_status === OrderStatus::Refunded;
    }

    /**
     * Mark every eSIM on a fully refunded order Refunded, dated when the
     * refund was issued. Called by the backfill for orders refunded before
     * the eSIM record existed, and by the Orders page refund. Idempotent:
     * an eSIM already refunded keeps its own date.
     */
    public static function markOrderEsimsRefunded(Order $order): int
    {
        $refundedAt = OrderRefund::query()
            ->where('order_id', $order->getKey())
            ->where('kind', OrderRefund::KIND_CUSTOMER_REFUND)
            ->max('refunded_at') ?? $order->updated_at ?? now();

        return Esim::query()
            ->where('order_id', $order->getKey())
            ->whereNull('refunded_at')
            ->update(['status' => EsimStatus::Refunded->value, 'refunded_at' => $refundedAt, 'updated_at' => now()]);
    }

    /**
     * A delivered eSIM that is a new eSIM. A top-up names the eSIM it refills
     * (metadata.parent_iccid) and adds data to that row instead.
     */
    public function isDeliveredEsimPurchase(OrderItem $item): bool
    {
        if ($item->fulfillment_status !== FulfillmentStatus::Fulfilled) {
            return false;
        }

        if ((string) data_get($item->metadata, 'parent_iccid', '') !== '') {
            return false;
        }

        return $item->category()->where('slug', self::CATEGORY_SLUG)->exists();
    }

    /**
     * Every ICCID in the delivery. The supplier's raw response lists one sim
     * per unit bought; the normalised payload only kept the first, so read the
     * raw list first and fall back to the normalised fields.
     *
     * @param  array<string, mixed>  $payload
     * @return array<int, string>
     */
    public static function iccidsFrom(array $payload): array
    {
        $iccids = array_merge(
            (array) data_get($payload, 'raw_response.data.sims.*.iccid', []),
            [data_get($payload, 'iccid'), data_get($payload, 'esim.iccid')],
        );

        return array_values(array_unique(array_filter(
            array_map(fn ($iccid) => trim((string) $iccid), $iccids),
            fn (string $iccid) => $iccid !== '',
        )));
    }

    /**
     * The supplier's package name ("Change-7 days-5GB"), else the name in the
     * variant snapshot taken at checkout, else the product's.
     */
    public static function packageNameFor(OrderItem $item): ?string
    {
        $name = data_get($item->fulfillment_payload, 'raw_response.data.package')
            ?? data_get($item->variant_snapshot, 'name')
            ?? $item->product?->name;

        return $name !== null ? mb_substr(trim((string) $name), 0, 255) : null;
    }
}
