<?php

namespace App\Http\Resources\V2;

use App\Domain\Catalog\Services\ApiCatalogPricer;
use App\Models\Product;
use App\Support\ApiMoney;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One thing the customer can buy: a network's airtime, a gift-card brand, a data
 * plan. The buyable denominations hang off it as variants.
 *
 * `price_from` exists for list tiles, so the app can show "from ₦100" without
 * rendering every variant. It is the cheapest thing the customer could actually
 * buy: the cheapest fixed variant, or for a type-your-own-amount variant the
 * price of its minimum. Null when nothing can be priced, which is how the app
 * knows to grey the tile rather than show a price it cannot honour.
 *
 * `is_available` folds in the category's feature switch. An admin turning airtime
 * off for an incident must grey the tile on the app exactly as on the website, and
 * the app cannot learn that from the product row alone.
 *
 * @property Product $resource
 */
class ProductResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,

            'country_code' => $this->country_code,
            // The currency this product's face values are denominated in, which is
            // not the currency the customer pays in. See ProductVariantResource.
            'currency_code' => $this->currency_code,

            'description' => $this->description,
            'redeem_instructions' => $this->redeem_instructions,
            'protip' => $this->protip,
            'terms_and_conditions' => $this->terms_and_conditions,

            'logo_url' => $this->logo_url ? asset($this->logo_url) : null,
            'featured_image' => $this->featured_image ? asset($this->featured_image) : null,
            'brand_color' => $this->brand_color,

            'is_featured' => (bool) $this->is_featured,
            'is_popular' => (bool) $this->is_popular,
            // Set by an admin or a supplier feed. The app should show the product
            // and refuse to add it, rather than hide it, so a customer searching
            // for it learns it exists and is temporarily unavailable.
            'is_out_of_stock' => (bool) $this->is_out_of_stock,
            'is_available' => ! $this->is_out_of_stock && $this->isCategoryEnabled(),

            'price_from' => $this->when(
                $this->relationLoaded('variants'),
                fn () => $this->cheapestPrice(),
            ),

            'category' => new CategoryResource($this->whenLoaded('category')),
            'subcategory' => new SubcategoryResource($this->whenLoaded('subcategory')),
            'variants' => ProductVariantResource::collection($this->whenLoaded('variants')),
        ];
    }

    /**
     * @return array{amount: float, currency: string, usd: float}|null
     */
    private function cheapestPrice(): ?array
    {
        $prices = $this->variants
            ->map(fn ($variant) => $variant->is_variable
                ? $variant->getAttribute(ApiCatalogPricer::MINIMUM_PRICE_ATTRIBUTE)
                : $variant->getAttribute(ApiCatalogPricer::PRICE_ATTRIBUTE))
            ->filter(fn ($price) => is_numeric($price) && $price > 0)
            ->map(fn ($price) => (float) $price);

        if ($prices->isEmpty()) {
            return null;
        }

        return ApiMoney::fromUsd($prices->min());
    }
}
