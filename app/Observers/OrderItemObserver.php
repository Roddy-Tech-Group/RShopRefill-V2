<?php

namespace App\Observers;

use App\Domain\Fulfillment\Enums\FulfillmentStatus;
use App\Domain\Fulfillment\Services\EsimRegistry;
use App\Models\OrderItem;

class OrderItemObserver
{
    public function __construct(private readonly EsimRegistry $esims) {}

    /**
     * Record the eSIMs an item delivered the moment its delivery is saved, on
     * every path that delivers. Fail-open: the customer already has the eSIM,
     * and a record that fails to write must never fail the delivery. The
     * backfill command writes anything missed.
     */
    public function saved(OrderItem $item): void
    {
        if ($item->fulfillment_status !== FulfillmentStatus::Fulfilled) {
            return;
        }

        if (! $item->wasChanged(['fulfillment_payload', 'fulfillment_status']) && ! $item->wasRecentlyCreated) {
            return;
        }

        try {
            $this->esims->recordFor($item);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
