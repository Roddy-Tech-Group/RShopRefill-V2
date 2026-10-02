<?php

namespace App\Domain\Catalog\Services;

use App\Domain\Cart\Services\CartPricingService;
use App\Domain\Cart\Services\CartValidationService;
use App\Models\CurrencyRate;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Works out what a variant costs a customer, for the mobile API.
 *
 * This exists because of a trap. The product_variants table has a `retail_price`
 * column, and it is NOT what the customer pays. The price is resolved through the
 * pricing rule chain in CartPricingService: a markup rule per product or category,
 * a manual USD override when one is set, and a minimum-margin floor clamped on
 * top. Reading the column instead would show a browse price that disagrees with
 * what checkout charges, which is the kind of discrepancy a customer screenshots.
 *
 * So the app's prices come from the SAME service the cart uses. Not a copy of its
 * arithmetic: the service itself.
 *
 * Prices are annotated onto the models rather than returned separately, so the
 * resource layer stays dumb and the whole collection is priced in one pass. The
 * rule chain caches its rules in memory, and each variant is handed its already
 * loaded product, so pricing a page of products is not a query per variant.
 *
 * A VARIABLE variant (the customer types the amount) has no fixed price, so its
 * PRICE_ATTRIBUTE is null. What it does get is the price AT ITS MINIMUM, so a list
 * tile can still say "from ₦100" for an operator that only sells typed amounts.
 */
class ApiCatalogPricer
{
    /** Where the resolved USD price is parked on a fixed variant; null on a variable one. */
    public const PRICE_ATTRIBUTE = 'api_price_usd';

    /** Where the price of a variable variant's MINIMUM amount is parked, for "from" labels. */
    public const MINIMUM_PRICE_ATTRIBUTE = 'api_price_at_minimum_usd';

    public function __construct(
        private CartPricingService $pricing,
        private CartValidationService $validation,
    ) {}

    /**
     * Annotate every variant of every product with its resolved USD price.
     *
     * @param  Collection<int, Product>|array<int, Product>  $products
     */
    public function priceProducts(Collection|array $products): void
    {
        foreach ($products as $product) {
            if (! $product->relationLoaded('variants')) {
                continue;
            }

            foreach ($product->variants as $variant) {
                // The rule chain reads $variant->product for its pricing basis and
                // markup rules. Eloquent does not set the inverse of an eager load,
                // so without this every variant on the page would lazy-load its own
                // product: a query per variant, which is the thing the docblock
                // promises not to do.
                if (! $variant->relationLoaded('product')) {
                    $variant->setRelation('product', $product);
                }
            }

            $this->priceVariants($product->variants);
        }
    }

    /**
     * @param  Collection<int, ProductVariant>|array<int, ProductVariant>  $variants
     */
    public function priceVariants(Collection|array $variants): void
    {
        foreach ($variants as $variant) {
            $this->priceVariant($variant);
        }
    }

    public function priceVariant(ProductVariant $variant): ProductVariant
    {
        if ($variant->is_variable) {
            // No fixed price: resolveMarkupBase would read face_value ?? retail_price
            // and produce a number that means nothing for a type-your-own-amount
            // option, often $0 or the raw column. The honest fixed figure is none.
            $variant->setAttribute(self::PRICE_ATTRIBUTE, null);
            $variant->setAttribute(self::MINIMUM_PRICE_ATTRIBUTE, $this->usdPriceAtMinimum($variant));

            return $variant;
        }

        $variant->setAttribute(self::PRICE_ATTRIBUTE, $this->usdPriceFor($variant));
        $variant->setAttribute(self::MINIMUM_PRICE_ATTRIBUTE, null);

        return $variant;
    }

    /**
     * What the smallest amount a variable variant accepts would cost, or null when
     * it cannot be quoted (no minimum set, or its currency has no rate yet).
     */
    private function usdPriceAtMinimum(ProductVariant $variant): ?float
    {
        $minimum = (float) ($variant->min_amount ?? 0);

        if ($minimum <= 0 || $variant->product === null) {
            return null;
        }

        try {
            return $this->quoteVariableAmount($variant, $minimum)['unit_price_usd'];
        } catch (ValidationException) {
            return null;
        }
    }

    /**
     * The resolved USD price for one variant, through the rule chain.
     */
    public function usdPriceFor(ProductVariant $variant): float
    {
        $base = $this->pricing->resolveMarkupBase($variant);

        return round($this->pricing->resolveVariantRetailPrice($variant, $base), 4);
    }

    /**
     * A firm price for a VARIABLE-amount product, where the customer types the
     * amount and the supplier cost is only knowable once they have.
     *
     * Why the app asks rather than working it out: the web hands the browser a
     * markup descriptor and asks the JavaScript to mirror the server's arithmetic.
     * That is fine in a browser, where the script is re-downloaded on every visit.
     * It is the wrong shape for an app, which cannot be corrected once installed:
     * the day a pricing rule changes, every old build would quote the old price and
     * then argue with checkout in front of the customer.
     *
     * The arithmetic below is CartManager::addItem's, step for step, because the
     * quote has to equal what the cart will charge:
     *
     *   estimated supplier cost = requested USD x the operator's real premium
     *   unit price              = that cost through the markup rule chain
     *
     * The amount arrives in the OFFER'S OWN currency, which is what the customer
     * typed: a Nigerian buying airtime enters ₦1,000, not $0.74. Converting that
     * is the server's job, not the app's, so there is one rate and one rounding.
     *
     * @param  float  $nativeAmount  the typed amount, in the variant's currency
     * @return array{native_amount: float, native_currency: string, requested_usd: float, unit_price_usd: float}
     *
     * @throws ValidationException when outside the variant's range
     */
    public function quoteVariableAmount(ProductVariant $variant, float $nativeAmount): array
    {
        $nativeCurrency = strtoupper((string) ($variant->currency ?: 'USD'));
        $requestedUsd = $nativeAmount / $this->nativeUnitsPerUsd($variant);

        // The cart's own guard, reused rather than restated: min_amount and
        // max_amount live in the native currency and the message has to read in
        // the currency the buyer typed. A quote that accepts an amount checkout
        // would refuse is worse than no quote at all.
        $this->validation->validateAddition($variant, $requestedUsd);

        $costPremium = $this->pricing->variableCostPremium($variant->product);
        $estimatedCostUsd = $requestedUsd * $costPremium;
        $unitPriceUsd = $this->pricing->resolveRetailPrice($variant->product, $estimatedCostUsd);

        return [
            'native_amount' => round($nativeAmount, 4),
            'native_currency' => $nativeCurrency,
            'requested_usd' => round($requestedUsd, 4),
            'unit_price_usd' => round($unitPriceUsd, 4),
        ];
    }

    /**
     * How many units of the variant's own currency one dollar buys.
     *
     * The same lookup CartManager and CartValidationService each do inline, kept in
     * one place for /v2 so every amount the app sends is converted identically.
     *
     * With one deliberate difference: a currency with NO active rate is refused,
     * not priced at par. The cart's validator falls back to 1.0 there, which would
     * read a typed ₦1,000 as $1,000, and the web never exposes that figure. The
     * quote endpoint does, so it must not produce it. USD itself needs no row.
     *
     * @throws ValidationException when the variant's currency cannot be priced
     */
    public function nativeUnitsPerUsd(ProductVariant $variant): float
    {
        $currency = strtoupper((string) ($variant->currency ?: 'USD'));

        if ($currency === 'USD') {
            return 1.0;
        }

        $rate = (float) (CurrencyRate::query()
            ->where('is_active', true)
            ->where('code', $currency)
            ->value('rate_per_usd') ?? 0.0);

        if ($rate <= 0) {
            throw ValidationException::withMessages([
                'amount' => 'This option cannot be priced in its currency right now. Please try again later.',
            ]);
        }

        return $rate;
    }
}
