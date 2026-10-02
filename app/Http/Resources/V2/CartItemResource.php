<?php

namespace App\Http\Resources\V2;

use App\Models\CartItem;
use App\Support\ApiMoney;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One line in the cart.
 *
 * The prices here are SNAPSHOTS, taken when the item was added, and that is the
 * point rather than an accident: the customer sees what they were quoted, and a
 * supplier changing their cost mid-session does not silently move the total
 * underneath them. Checkout re-validates before charging.
 *
 * @property CartItem $resource
 */
class CartItemResource extends JsonResource
{
    /**
     * Per-item context the customer supplied, echoed back so the app can show and
     * edit it. An ALLOWLIST: metadata_snapshot also carries figures we compute for
     * our own bookkeeping, and echoing the whole thing would publish them and make
     * every future internal key a public field by accident.
     */
    private const VISIBLE_METADATA = [
        'recipient_phone',
        'sender_phone',
        'delivery_email',
        'account_id',
    ];

    public function toArray(Request $request): array
    {
        $metadata = (array) ($this->metadata_snapshot ?? []);

        return [
            'id' => $this->id,
            'quantity' => (int) $this->quantity,

            'unit_price' => ApiMoney::fromUsd((float) $this->unit_price_snapshot),
            'subtotal' => ApiMoney::fromUsd((float) $this->subtotal_snapshot),

            // For a variable item, the amount the customer actually typed, in the
            // currency they typed it in. Reconstructed at full precision when the
            // item was added, so ₦1,000 reads back as ₦1,000 and not ₦1,000.07.
            'requested_amount' => isset($metadata['requested_native'])
                ? ApiMoney::native(
                    (float) $metadata['requested_native'],
                    (string) ($this->variant->currency ?? 'USD'),
                )
                : null,

            'metadata' => array_intersect_key($metadata, array_flip(self::VISIBLE_METADATA)),

            'product' => $this->whenLoaded('product', fn () => [
                'id' => $this->product->id,
                'name' => $this->product->name,
                'slug' => $this->product->slug,
                'logo_url' => $this->product->logo_url ? asset($this->product->logo_url) : null,
            ]),

            // No sku: a supplier-side identifier that can name the supplier.
            'variant' => $this->whenLoaded('variant', fn () => [
                'id' => $this->variant->id,
                'is_variable' => (bool) $this->variant->is_variable,
                'face_value' => $this->variant->face_value !== null
                    ? ApiMoney::native(
                        (float) $this->variant->face_value,
                        (string) ($this->variant->currency ?: 'USD'),
                    )
                    : null,
            ]),
        ];
    }
}
