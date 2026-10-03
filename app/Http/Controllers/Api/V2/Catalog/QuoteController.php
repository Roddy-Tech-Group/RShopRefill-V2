<?php

namespace App\Http\Controllers\Api\V2\Catalog;

use App\Domain\Catalog\Services\ApiCatalogPricer;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V2\QuoteVariableAmountRequest;
use App\Models\ProductVariant;
use App\Support\ApiMoney;
use App\Support\ApiV2Error;
use Illuminate\Http\JsonResponse;

/**
 * What a typed amount costs, answered by the server.
 *
 * Variable products (airtime, some bill payments) have no fixed denomination: the
 * customer types an amount and the price depends on the supplier's cost for that
 * amount, the markup rule and the minimum-margin floor.
 *
 * The website hands the browser the markup parameters and asks its JavaScript to
 * reproduce that calculation. An app cannot work that way. Once a build is on a
 * phone it is there for months, so the day a pricing rule changes, every old build
 * would quote the old price and then be contradicted by checkout, in front of the
 * customer, on a screen about money.
 *
 * So the app asks. One round trip, and the number it shows is the number it will be
 * charged, because both come from the same service.
 */
class QuoteController extends Controller
{
    public function __construct(private ApiCatalogPricer $pricer) {}

    public function __invoke(QuoteVariableAmountRequest $request): JsonResponse
    {
        $variant = ProductVariant::with('product.category')
            ->whereKey($request->integer('variant_id'))
            ->first();

        if ($variant === null || ! $variant->is_available || $variant->product?->is_active !== true) {
            // One answer for "no such variant" and "not buyable", so the endpoint
            // cannot be used to map the catalogue's hidden rows.
            return ApiV2Error::make('variant_unavailable', 'That option is not available right now.', 404);
        }

        if (! $variant->is_variable) {
            // A fixed denomination already has its price in the catalogue. Quoting
            // one would invite the app to send an amount that is then ignored.
            return ApiV2Error::make(
                'variant_not_variable',
                'That option has a fixed amount and does not need a quote.',
                422,
            );
        }

        // Throws ValidationException when the amount is outside the variant's
        // range, which the v2 error renderer turns into a 422 naming the field.
        // The bounds and the message come from the cart's own validator, so a
        // quote can never accept an amount checkout would refuse.
        $quote = $this->pricer->quoteVariableAmount($variant, (float) $request->input('amount'));

        return response()->json([
            'data' => [
                'variant_id' => $variant->id,
                // Echoed back so the app can prove it quoted what the customer
                // typed, and so a support screenshot is unambiguous.
                'requested' => ApiMoney::native($quote['native_amount'], $quote['native_currency']),
                'price' => ApiMoney::fromUsd($quote['unit_price_usd']),
            ],
        ]);
    }
}
