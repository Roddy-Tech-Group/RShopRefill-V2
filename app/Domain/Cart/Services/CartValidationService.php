<?php

namespace App\Domain\Cart\Services;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\CurrencyRate;
use App\Models\ProductVariant;
use Illuminate\Validation\ValidationException;

class CartValidationService
{
    /**
     * Slack (in the variant's native currency) allowed on a min/max range check,
     * to absorb the USD<->native float round-trip so an exact boundary entry is
     * never falsely rejected.
     */
    private const RANGE_TOLERANCE = 0.01;

    public function __construct(private CartPricingService $pricingService) {}

    /**
     * Validate an entire cart, checking all items.
     * Returns an array of validation errors or warnings keyed by cart_item_id.
     */
    public function validateCart(Cart $cart): array
    {
        $issues = [];

        foreach ($cart->items as $item) {
            $itemIssues = $this->validateItem($item);
            if (! empty($itemIssues)) {
                $issues[$item->id] = $itemIssues;
            }
        }

        return $issues;
    }

    /**
     * Validate a single cart item against current product/variant state.
     */
    public function validateItem(CartItem $item): array
    {
        $issues = [];
        $variant = $item->variant;
        $product = $item->product;

        // 1. Check availability
        if (! $variant || ! $variant->is_available) {
            $issues[] = 'variant_unavailable';
        }

        if (! $product || ! $product->is_active) {
            $issues[] = 'product_unavailable';
        }

        if (! empty($issues)) {
            // If it's unavailable, no need to check pricing limits
            return $issues;
        }

        // 2. Check min/max limits for variable items. display_amount is the USD
        // line total (requested face value x quantity); min_amount/max_amount are
        // per-unit figures in the variant's native currency. Convert the per-unit
        // USD amount back to native so the comparison is unit-consistent.
        if ($variant->is_variable) {
            $perUnitUsd = $item->quantity > 0
                ? (float) $item->display_amount / $item->quantity
                : (float) $item->display_amount;
            $requestedNative = $perUnitUsd * $this->nativeUnitsPerUsd($variant);

            if ($variant->min_amount && $requestedNative < (float) $variant->min_amount - self::RANGE_TOLERANCE) {
                $issues[] = 'below_minimum_amount';
            }
            if ($variant->max_amount && $requestedNative > (float) $variant->max_amount + self::RANGE_TOLERANCE) {
                $issues[] = 'above_maximum_amount';
            }
        }

        // 3. Check pricing snapshot integrity — recalculate what the unit price
        // *should* be right now and compare against the snapshot.
        //
        // Money is compared with a 1-cent tolerance: a strict !== on floats
        // falsely flags economically-identical prices that differ only in their
        // binary representation (e.g. 11.01 is not exactly representable), which
        // would block every checkout. A genuine markup/catalog change shifts the
        // price by far more than a cent and is still caught.
        $currentPricing = $this->pricingService->calculatePricing($variant, $item->quantity);

        if (abs((float) $item->unit_price_snapshot - (float) $currentPricing['unit_price_snapshot']) > 0.01) {
            $issues[] = 'price_updated';
        }

        return $issues;
    }

    /**
     * Validates if a new item CAN be added to the cart.
     */
    public function validateAddition(ProductVariant $variant, ?float $requestedValue = null): void
    {
        $product = $variant->product;

        if (! $product || ! $product->is_active) {
            throw ValidationException::withMessages([
                'product_variant_id' => 'This product is no longer available.',
            ]);
        }

        if (! $variant->is_available) {
            throw ValidationException::withMessages([
                'product_variant_id' => 'This specific variant is currently unavailable.',
            ]);
        }

        if ($variant->is_variable && $requestedValue !== null) {
            // $requestedValue arrives in USD (the cart's settlement currency), but
            // min_amount/max_amount are stored in the variant's native currency.
            // Convert the request back to native with the same rate the storefront
            // applied so the range check is unit-consistent and the message reads
            // in the currency the buyer typed.
            $requestedNative = $requestedValue * $this->nativeUnitsPerUsd($variant);
            $min = (float) $variant->min_amount;
            $max = (float) $variant->max_amount;

            if ($variant->min_amount && $requestedNative < $min - self::RANGE_TOLERANCE) {
                throw ValidationException::withMessages([
                    'requested_value' => "The minimum amount is {$min} {$variant->currency}.",
                ]);
            }
            if ($variant->max_amount && $requestedNative > $max + self::RANGE_TOLERANCE) {
                throw ValidationException::withMessages([
                    'requested_value' => "The maximum amount is {$max} {$variant->currency}.",
                ]);
            }
        }
    }

    /**
     * The variant's native-currency units per 1 USD. Variable amounts are entered
     * and stored (min_amount/max_amount) in this currency while the cart settles
     * in USD, so range checks convert the USD figure back with this rate. Falls
     * back to 1.0 when no rate row exists (mirrors the storefront's `|| 1`).
     */
    private function nativeUnitsPerUsd(ProductVariant $variant): float
    {
        return (float) (CurrencyRate::where('code', strtoupper((string) $variant->currency))
            ->value('rate_per_usd') ?: 1.0);
    }
}
