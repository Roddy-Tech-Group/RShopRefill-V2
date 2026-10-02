<?php

namespace App\Http\Resources\V2;

use App\Domain\Cart\Services\CartPricingService;
use App\Domain\Cart\Services\CartValidationService;
use App\Models\Cart;
use App\Support\ApiMoney;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The customer's basket.
 *
 * Totals come from CartPricingService rather than being summed here, so the figure
 * the app shows is the figure checkout will work from. Adding up the lines in the
 * resource would be a second implementation of the same arithmetic, and the day
 * the two disagreed the customer would be the one to notice.
 *
 * `issues` is the cart's own re-validation, per line: a variant that was switched
 * off, a product out of stock, a category an admin turned off, an amount now out of
 * range. Checkout refuses a cart with any of these, so the app has to be able to
 * show WHICH line and WHY before the customer taps Pay, rather than after.
 *
 *   "issues": { "<item id>": ["variant_unavailable"] }
 *
 * @property Cart $resource
 */
class CartResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $totals = app(CartPricingService::class)->calculateCartTotals($this->items);
        $issues = app(CartValidationService::class)->validateCart($this->resource);

        return [
            'id' => $this->id,
            'items' => CartItemResource::collection($this->items)->resolve(),
            'item_count' => $this->items->sum('quantity'),
            'is_checkout_ready' => $this->items->isNotEmpty() && $issues === [],
            'issues' => (object) $issues,
            'totals' => [
                'subtotal' => ApiMoney::fromUsd((float) $totals['subtotal']),
                'total' => ApiMoney::fromUsd((float) $totals['total']),
                // Whole Rcoin, not money, so it is a plain integer rather than an
                // amount object. What the customer earns if this cart completes.
                'estimated_rcoin_reward' => (int) $totals['estimated_rcoin_reward'],
            ],
        ];
    }
}
