<?php

namespace App\Domain\Order\Services;

use App\Domain\Cart\Services\CartPricingService;
use App\Models\CartItem;
use App\Models\Coupon;
use Illuminate\Support\Facades\Log;

/**
 * THE single implementation of coupon discount math, shared by the live
 * preview endpoint and CheckoutService so the discount the customer is shown
 * is exactly the discount they are charged. Also owns the coupon cost-floor
 * clamp: in enforce mode (config pricing.floor_mode) a discount may not take
 * a cart line below supplier cost x (1 + min margin) unless the coupon itself
 * is flagged allow_below_cost (deliberate loss-making promo).
 */
class CouponApplicationService
{
    public function __construct(private CartPricingService $pricing) {}

    /**
     * The USD discount this coupon grants on a cart line.
     *
     * The line's provider_cost_usd is the per-unit supplier cost snapshotted
     * at add-to-cart. The clamp rounds DOWN so the discounted line lands at or
     * above the floor, never a cent under it.
     *
     * @return array{discount_usd: float, clamped: bool}
     */
    public function discountFor(Coupon $coupon, CartItem $item): array
    {
        $quantity = max(1, (int) $item->quantity);
        $lineSubtotal = (float) $item->subtotal_snapshot;
        $unitPriceUsd = $lineSubtotal / $quantity;

        $discountedUnit = $coupon->applyTo($unitPriceUsd);
        $discountUsd = round(($unitPriceUsd - $discountedUnit) * $quantity, 2);

        if ($discountUsd <= 0) {
            return ['discount_usd' => 0.0, 'clamped' => false];
        }

        if ($this->floorMode() !== 'enforce' || $coupon->allow_below_cost) {
            return ['discount_usd' => $discountUsd, 'clamped' => false];
        }

        $costUsd = (float) $item->provider_cost_usd;
        if ($costUsd <= 0) {
            // No cost snapshot to anchor on — nothing to clamp against.
            return ['discount_usd' => $discountUsd, 'clamped' => false];
        }

        // Same floor the markup path uses, so a coupon cannot undercut what the
        // catalogue would refuse to price at in the first place.
        $lineFloor = $this->pricing->costFloor($costUsd) * $quantity;

        // floor() (round down) keeps the discounted line >= the cost floor;
        // round() could overshoot under it by half a cent. The 1e-9 epsilon
        // only absorbs float representation error (22.00 - 19.19 = 2.80999...),
        // not real remainder.
        $maxDiscount = max(0.0, floor(($lineSubtotal - $lineFloor) * 100 + 1e-9) / 100);

        if ($discountUsd <= $maxDiscount) {
            return ['discount_usd' => $discountUsd, 'clamped' => false];
        }

        Log::info('pricing.coupon_discount_clamped', [
            'coupon_id' => $coupon->id,
            'cart_item_id' => $item->id,
            'requested_discount_usd' => $discountUsd,
            'granted_discount_usd' => $maxDiscount,
            'line_cost_floor_usd' => $lineFloor,
        ]);

        return ['discount_usd' => $maxDiscount, 'clamped' => true];
    }

    private function floorMode(): string
    {
        $mode = (string) config('pricing.floor_mode', 'legacy');

        return in_array($mode, ['shadow', 'enforce'], true) ? $mode : 'legacy';
    }
}
