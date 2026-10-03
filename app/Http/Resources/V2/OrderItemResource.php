<?php

namespace App\Http\Resources\V2;

use App\Models\OrderItem;
use App\Support\ApiMoney;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One line of a placed order, and what was delivered for it.
 *
 * A STRICT allowlist, and here that is not a style preference. The order_items row
 * also holds `provider_name` and `provider_offer_id` (which supplier filled this
 * and their id for it), `provider_cost_usd` (what we paid), `markup_amount` (what
 * we made) and `fulfillment_payload` (the supplier's entire response). Publishing
 * any of those would hand a customer our sourcing and our margins, and would name
 * suppliers on a customer-facing surface, which we do not do.
 *
 * The storefront's own OrderItemResource does publish provider_offer_id. That is a
 * leak on the web side, not a precedent to copy.
 *
 * @property OrderItem $resource
 */
class OrderItemResource extends JsonResource
{
    /**
     * The eSIM fields a customer needs to install and share their line. Named one
     * by one so a supplier adding something upstream tomorrow is not published by
     * accident.
     */
    private const ESIM_FIELDS = [
        'iccid',
        'lpaUrl',
        'manualActivationCode',
        'directInstallUrl',
        'sharingLink',
        'sharingAccessCode',
    ];

    public function toArray(Request $request): array
    {
        $snapshot = (array) ($this->product_snapshot ?? []);
        $variantSnapshot = (array) ($this->variant_snapshot ?? []);

        return [
            'id' => $this->id,

            // The product AS IT WAS when bought. A renamed or delisted product must
            // not change what an old receipt says.
            'product' => [
                'name' => $snapshot['name'] ?? null,
                'logo_url' => isset($snapshot['logo_url']) ? asset($snapshot['logo_url']) : null,
            ],

            'quantity' => (int) $this->quantity,

            // Historical: what was charged, in the currency it was charged in. The
            // USD figure comes from the rate the ORDER was placed at, because a line
            // has no USD column of its own; dividing by the rate stored on the order
            // is how the order's own settlement figures are derived.
            'subtotal' => ApiMoney::historic(
                (float) $this->subtotal_amount,
                (string) ($this->display_currency ?: 'USD'),
                $this->settlementUsd((float) $this->subtotal_amount),
            ),

            // What the recipient got, for a top-up where the customer typed an
            // amount. In the OFFER'S currency (a ₦1,000 MTN top-up delivers ₦1,000
            // whatever the buyer paid in), which the variant snapshot recorded.
            // Null for a fixed denomination.
            'delivered_face_value' => $this->provider_face_value !== null
                ? ApiMoney::native(
                    (float) $this->provider_face_value,
                    (string) ($variantSnapshot['currency'] ?? $this->display_currency ?: 'USD'),
                )
                : null,

            'fulfillment_status' => $this->fulfillment_status->value,
            'delivered_at' => $this->delivered_at?->toIso8601ZuluString(),
            'failed_at' => $this->failed_at?->toIso8601ZuluString(),

            // The goods. Only once actually delivered: publishing a half-written
            // payload mid-fulfilment is how a customer ends up with a code that
            // does not work yet.
            'delivery' => $this->when(
                $this->fulfillment_status->value === 'fulfilled',
                fn () => $this->deliveredGoods(),
            ),
        ];
    }

    /**
     * A line amount in settlement USD, at the order's own historical rate.
     *
     * Mirrors Order::resolveUsdAmount: the recorded rate when there is one, and the
     * amount as-is when there is none, which is only honest for a legacy row whose
     * display currency was USD anyway. The order is set as a relation by
     * OrderResource, so this costs no query per line.
     */
    private function settlementUsd(float $displayAmount): float
    {
        $rate = $this->relationLoaded('order') ? $this->order?->exchangeRate() : null;

        if ($rate !== null && $rate > 0) {
            return round($displayAmount / $rate, 4);
        }

        return round($displayAmount, 4);
    }

    /**
     * @return array{pins: list<mixed>, esim: array<string, mixed>|null}
     */
    private function deliveredGoods(): array
    {
        $payload = (array) ($this->fulfillment_payload ?? []);

        $esim = is_array($payload['esim'] ?? null)
            ? array_intersect_key($payload['esim'], array_flip(self::ESIM_FIELDS))
            : null;

        return [
            // Voucher codes, gift-card pins, betting tokens.
            'pins' => array_values((array) ($payload['pins'] ?? [])),
            'esim' => $esim === [] ? null : $esim,
        ];
    }
}
