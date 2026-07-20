<?php

namespace App\Domain\Cart\Services;

use App\Domain\Cart\Events\CartUpdated;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\ProductVariant;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CartManager
{
    /**
     * Hard ceiling on the quantity of any single line item. Guards against
     * overflow of DECIMAL(12,4) monetary columns and unreasonable orders.
     */
    public const MAX_ITEM_QUANTITY = 100;

    public function __construct(
        private CartPricingService $pricingService,
        private CartValidationService $validationService
    ) {}

    /**
     * Get or create the active cart for the current session.
     */
    public function resolveCart(?int $userId = null, ?string $guestToken = null): Cart
    {
        if ($userId) {
            return Cart::firstOrCreate(
                ['user_id' => $userId, 'status' => 'active'],
                ['last_activity_at' => now()]
            );
        }

        if ($guestToken) {
            return Cart::firstOrCreate(
                ['guest_token' => $guestToken, 'status' => 'active'],
                ['last_activity_at' => now()]
            );
        }

        throw new \InvalidArgumentException('Must provide either a user ID or a guest token.');
    }

    /**
     * Add an item to the cart.
     *
     * @param  array<string, mixed>|null  $metadata  Per-item context (recipient
     *                                               phone for top-ups, delivery email for gift cards, etc.). Merged
     *                                               into cart_items.metadata_snapshot; later copied onto the order
     *                                               item at checkout so fulfilment providers can read it.
     */
    public function addItem(Cart $cart, ProductVariant $variant, int $quantity, ?float $requestedValue = null, ?array $metadata = null): CartItem
    {
        return DB::transaction(function () use ($cart, $variant, $quantity, $requestedValue, $metadata) {
            // 1. Validate addition
            $this->validationService->validateAddition($variant, $requestedValue);

            // 2. Check for existing identical variant in cart (and matching requested value if variable)
            $existingItems = $cart->items()->where('product_variant_id', $variant->id)->get();
            $existingItem = null;
            foreach ($existingItems as $item) {
                if ($variant->is_variable && $requestedValue !== null) {
                    // For variable products, treat the stored provider_cost_usd as the requested face value
                    // (Or infer it from display_amount/quantity for legacy items if provider_cost_usd is missing/0)
                    $itemRequestedValue = $item->provider_cost_usd > 0 ? $item->provider_cost_usd : ($item->display_amount / $item->quantity);
                    if (abs($itemRequestedValue - $requestedValue) < 0.001) {
                        $existingItem = $item;
                        break;
                    }
                } else {
                    $existingItem = $item;
                    break;
                }
            }

            // 3. Calculate pricing
            $pricing = $this->pricingService->calculatePricing($variant, $quantity);

            // For variable products (e.g. eSIMs or flexible Gift Cards), we might need to
            // override the subtotal/cost logic based on the requested face value.
            // Assuming for now that CartPricingService handles the variant's base configuration.
            // If requestedValue is passed, we overwrite display_amount and subtotal.
            if ($variant->is_variable && $requestedValue !== null) {
                $unitPriceUsd = $this->pricingService->resolveRetailPrice($variant->product, $requestedValue);
                $subtotalUsd = $unitPriceUsd * $quantity;

                $pricing['provider_cost_usd'] = $requestedValue;
                $pricing['markup_amount'] = $unitPriceUsd - $requestedValue;
                $pricing['unit_price_snapshot'] = $unitPriceUsd;
                $pricing['subtotal_snapshot'] = $subtotalUsd;
                $pricing['display_amount'] = $subtotalUsd;
            }

            // Merge any incoming metadata over the snapshot we may already
            // have stored on the cart row — newer values win so re-adding
            // the same top-up with a corrected phone number replaces the
            // earlier entry without losing other keys.
            $mergedMeta = array_filter(array_merge(
                (array) ($existingItem?->metadata_snapshot ?? []),
                $metadata ?? [],
            ), fn ($v) => $v !== null && $v !== '');

            if ($existingItem) {
                // Update existing
                $newQuantity = $existingItem->quantity + $quantity;

                if ($newQuantity > self::MAX_ITEM_QUANTITY) {
                    throw ValidationException::withMessages([
                        'quantity' => 'You can have at most '.self::MAX_ITEM_QUANTITY.' of this item in your cart (currently '.$existingItem->quantity.').',
                    ]);
                }

                $newPricing = $this->pricingService->calculatePricing($variant, $newQuantity);

                if ($variant->is_variable && $requestedValue !== null) {
                    $unitPriceUsd = $this->pricingService->resolveRetailPrice($variant->product, $requestedValue);
                    $subtotalUsd = $unitPriceUsd * $newQuantity;

                    $newPricing['provider_cost_usd'] = $requestedValue;
                    $newPricing['markup_amount'] = $unitPriceUsd - $requestedValue;
                    $newPricing['unit_price_snapshot'] = $unitPriceUsd;
                    $newPricing['subtotal_snapshot'] = $subtotalUsd;
                    $newPricing['display_amount'] = $subtotalUsd;
                }

                $existingItem->update(array_merge([
                    'quantity' => $newQuantity,
                    'metadata_snapshot' => $mergedMeta ?: null,
                ], $newPricing));

                $item = $existingItem;
            } else {
                // Create new
                $item = $cart->items()->create(array_merge([
                    'product_id' => $variant->product_id,
                    'product_variant_id' => $variant->id,
                    'quantity' => $quantity,
                    'display_currency' => $variant->currency ?? 'USD',
                    'metadata_snapshot' => $mergedMeta ?: null,
                ], $pricing));
            }

            $cart->touch('last_activity_at');

            // Dispatch event
            CartUpdated::dispatch($cart->fresh('items'));

            return $item;
        });
    }

    public function updateQuantity(Cart $cart, string $cartItemId, int $quantity): CartItem
    {
        $item = $cart->items()->findOrFail($cartItemId);

        if ($quantity <= 0) {
            $item->delete();
            CartUpdated::dispatch($cart->fresh('items'));

            return $item; // returning deleted item
        }

        $pricing = $this->pricingService->calculatePricing($item->variant, $quantity);

        // Handle variable products correctly if updating quantity
        if ($item->variant->is_variable) {
            $singleRequestedValue = $item->provider_cost_usd > 0 ? $item->provider_cost_usd : ($item->display_amount / $item->quantity);
            $unitPriceUsd = $this->pricingService->resolveRetailPrice($item->variant->product, $singleRequestedValue);
            $subtotalUsd = $unitPriceUsd * $quantity;

            $pricing['provider_cost_usd'] = $singleRequestedValue;
            $pricing['markup_amount'] = $unitPriceUsd - $singleRequestedValue;
            $pricing['unit_price_snapshot'] = $unitPriceUsd;
            $pricing['subtotal_snapshot'] = $subtotalUsd;
            $pricing['display_amount'] = $subtotalUsd;
        }

        $item->update(array_merge(['quantity' => $quantity], $pricing));

        $cart->touch('last_activity_at');
        CartUpdated::dispatch($cart->fresh('items'));

        return $item;
    }

    public function removeItem(Cart $cart, string $cartItemId): void
    {
        $cart->items()->findOrFail($cartItemId)->delete();
        $cart->touch('last_activity_at');
        CartUpdated::dispatch($cart->fresh('items'));
    }

    public function clearCart(Cart $cart): void
    {
        $cart->items()->delete();
        $cart->touch('last_activity_at');
        CartUpdated::dispatch($cart->fresh('items'));
    }
}
