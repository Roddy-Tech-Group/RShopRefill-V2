<?php

namespace App\Domain\Catalog\Services;

use App\Domain\Shared\Services\OperationalAlerter;
use App\Enums\AlertSeverity;
use App\Models\OrderItem;
use App\Models\ProductVariant;
use Illuminate\Support\Facades\Log;

/**
 * Takes an offer off sale the moment the supplier refuses to sell it.
 *
 * The catalog sync hides dropped offers, but only on its next run; until then
 * every customer who picks the offer pays and then gets a refund. The first
 * rejected purchase is proof enough, so the offer is hidden there and then.
 * If the supplier still lists it, the next sync puts it back on sale.
 */
class RejectedOfferDelister
{
    public function __construct(private readonly OperationalAlerter $alerter) {}

    /**
     * Zendit answers a purchase of an offer it no longer carries with
     * "INVALID_VALUE: params.offerID: offer not found" (seen 2026-09-17 on
     * APPLE_US_10_EGIFT_WHS). The error text is matched rather than a field,
     * because the wording is the only part of the answer we have evidence for.
     */
    public static function isOfferNotFound(int $httpStatus, string $responseBody): bool
    {
        return $httpStatus >= 400
            && $httpStatus < 500
            && str_contains(strtolower($responseBody), 'offer not found');
    }

    public function delist(OrderItem $item): void
    {
        $variant = ProductVariant::query()
            ->where('provider_offer_id', $item->provider_offer_id)
            ->where('is_available', true)
            ->first();

        if ($variant === null) {
            return;
        }

        $variant->update([
            'is_available' => false,
            'delisted_at' => now(),
        ]);

        Log::warning('Offer hidden after the supplier rejected it as not found', [
            'provider' => $item->provider_name,
            'offer_id' => $item->provider_offer_id,
            'order_item_id' => $item->id,
        ]);

        $productName = $item->product_snapshot['name'] ?? $item->provider_offer_id;

        $this->alerter->send(
            severity: AlertSeverity::Sev2,
            key: "catalog:offer-not-found:{$item->provider_offer_id}",
            title: "{$productName} was sold but the supplier no longer has it",
            message: "The supplier rejected a paid order for {$item->provider_offer_id} as \"offer not found\". The offer is now hidden from the store, and the customer is refunded through the normal failed-item path.",
            context: [
                'provider' => $item->provider_name,
                'offer_id' => $item->provider_offer_id,
                'order_id' => $item->order_id,
            ],
            runbook: 'Nothing to do for the offer itself; the next catalog sync keeps it hidden or restores it if the supplier still lists it. Check the customer received the refund.',
        );
    }
}
