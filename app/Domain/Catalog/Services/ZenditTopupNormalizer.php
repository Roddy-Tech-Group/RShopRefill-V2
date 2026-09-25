<?php

namespace App\Domain\Catalog\Services;

use App\Domain\Catalog\Support\FaceValueCheck;
use App\Enums\PricingBasis;
use App\Jobs\MediaProcessorJob;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Subcategory;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ZenditTopupNormalizer implements CatalogNormalizerInterface
{
    public function normalizeAndSave(array $rawItem, string $providerName): array
    {
        // 1. Ensure the Mobile Airtime category exists.
        $category = Category::firstOrCreate(
            ['slug' => 'mobile-airtime'],
            ['name' => 'Mobile Airtime', 'type' => 'digital']
        );

        // 2. Top-ups are no longer split into Data/Bundle subcategories - the storefront
        // distinguishes them per-offer (network badges + the Credit/Data/Bundle switcher),
        // reading each offer's subType from variant.metadata. Keep every operator under a
        // single "Mobile Top Up" subcategory.
        $subcategory = Subcategory::firstOrCreate(
            ['category_id' => $category->id, 'slug' => 'mobile-top-up'],
            ['name' => 'Mobile Top Up', 'is_featured' => false]
        );

        // 3. Brand = the mobile operator (e.g. "MTN", "Vodafone"). Zendit exposes a
        // machine key and a human label; topup payloads may use brand/brandName or
        // operator/operatorName depending on the offer, so accept either.
        $brandKey = $rawItem['brand'] ?? $rawItem['operator'] ?? 'unknown';
        $brandLabel = $rawItem['brandName'] ?? $rawItem['operatorName'] ?? $brandKey;
        $countryCode = strtoupper($rawItem['country'] ?? 'US');

        // Money lives on the nested send/price/cost objects (minor units via currencyDivisor).
        $priceBlock = $rawItem['price'] ?? [];
        $sendBlock = $rawItem['send'] ?? [];
        $costBlock = $rawItem['cost'] ?? [];
        // The SEND block is the value credited to the recipient's phone, in its own
        // currency (a Ghana top-up is GHS). The price block is Zendit's USD price;
        // the cost block is our USD cost. Use the send block for the card currency;
        // an EMPTY send label (data bundles) falls through to the price block.
        $currencyCode = FaceValueCheck::currency($sendBlock, $priceBlock, $rawItem);

        // Products are grouped by operator + country. The `topup-` slug prefix keeps
        // it from ever colliding with a gift-card brand that shares a brand_key.
        $productSlug = Str::slug("topup-{$brandKey}-{$countryCode}-{$providerName}");

        // Preserve any fields hydrated elsewhere so a routine re-sync doesn't blow them away.
        $existing = Product::where('slug', $productSlug)->first();

        $product = Product::updateOrCreate(
            [
                'provider_name' => $providerName,
                'slug' => $productSlug,
            ],
            [
                // Preserve admin-arranged categories on existing rows so the 6h
                // re-sync doesn't wipe manual organisation. Only set the
                // supplier-derived value when the product is brand new.
                'category_id' => $existing?->category_id ?? $category->id,
                'subcategory_id' => $existing?->subcategory_id ?? $subcategory->id,
                'provider_reference' => null,
                // brand_key, country_code and name are admin-editable in the
                // catalog UI. Preserve any edits so re-sync does not revert them.
                'brand_key' => $existing?->brand_key ?? $brandKey,
                'country_code' => $existing?->country_code ?? $countryCode,
                'currency_code' => $currencyCode,
                // Airtime carries no built-in discount — supplier cost is the
                // only honest markup base (US carriers charge at/above face).
                // Never overwrites an admin/backfill choice on existing rows.
                'pricing_basis' => $existing?->pricing_basis ?? PricingBasis::SupplierCost,
                'name' => $existing?->name ?? "{$brandLabel} ({$countryCode})",
                // A top-up product groups every operator offer, so a single offer's
                // per-bundle shortNotes ("3GB + Call (30d)") must never stand in for
                // the brand-level description. Leave it unset on sync so the storefront
                // renders its own top-up tagline; preserve any admin-edited copy.
                'description' => $existing?->description,
                'redeem_instructions' => $existing?->redeem_instructions ?: ($rawItem['redeem_instructions'] ?? null),
                'terms_and_conditions' => $existing?->terms_and_conditions ?: ($rawItem['terms'] ?? null),
                'logo_url' => $existing?->logo_url ?: ($rawItem['logoUrl'] ?? null),
                'featured_image' => $existing?->featured_image,
                'brand_color' => $existing?->brand_color,
            ]
        );

        // 4. Create/Update the variant (one airtime amount). Zendit money fields are
        // integer minor units; divide by currencyDivisor.
        $offerId = $rawItem['offerId'];
        $priceType = $rawItem['priceType'] ?? 'FIXED';

        $priceDiv = (float) ($priceBlock['currencyDivisor'] ?? 100);
        $sendDiv = (float) ($sendBlock['currencyDivisor'] ?? 100);
        $costDiv = (float) ($costBlock['currencyDivisor'] ?? 100);

        // Face value = the SEND block (what the phone is credited, in its own
        // currency). cost_price is the COST block — always USD, our markup base.
        $faceValue = isset($sendBlock['fixed']) ? ((float) $sendBlock['fixed']) / $sendDiv
                     : (isset($priceBlock['fixed']) ? ((float) $priceBlock['fixed']) / $priceDiv : null);
        // Zendit charges some offers a separate per-unit fee on top of the
        // fixed cost (STRAIGHTTALK_US_38: fixed 3900 + fee 50 = a real $39.50
        // wallet deduction; Boost's small denominations carry $1). feePct is a
        // fraction, like the price block's margin. Both MUST land in
        // cost_price: it is the markup base and the enforce floor's anchor,
        // and a fee left out of it is a silent below-cost sale at the floor.
        $costFee = is_numeric($costBlock['fee'] ?? null) ? ((float) $costBlock['fee']) / $costDiv : 0.0;
        $costFeePct = is_numeric($costBlock['feePct'] ?? null) ? (float) $costBlock['feePct'] : 0.0;
        $costPrice = isset($costBlock['fixed'])
            ? (((float) $costBlock['fixed']) / $costDiv) * (1 + $costFeePct) + $costFee
            : ($faceValue ?? 0.0);
        $retailPrice = $faceValue ?? $costPrice;

        // Variable top-ups expose min/max on the send block. Zendit labels variable
        // top-ups as RANGE (vouchers use VARIABLE) — accept both.
        $minAmount = isset($sendBlock['min']) ? ((float) $sendBlock['min']) / $sendDiv : null;
        $maxAmount = isset($sendBlock['max']) ? ((float) $sendBlock['max']) / $sendDiv : null;

        $isVariable = in_array($priceType, ['VARIABLE', 'RANGE'], true)
            || ($minAmount !== null && $maxAmount !== null && $minAmount !== $maxAmount);

        // A face the supplier labelled or sized wrongly (a "3 PEN" bundle that
        // costs $3.40) is kept but not offered, with the reading on the row,
        // until the offer is corrected.
        $faceCheck = FaceValueCheck::verdict($faceValue, $currencyCode, $costPrice, $isVariable);

        if (FaceValueCheck::isSuspect($faceCheck)) {
            Log::warning('Catalog offer hidden: face value out of line with its cost', [
                'offer' => $offerId,
                'brand' => $brandKey,
                'country' => $countryCode,
                'face' => $faceValue,
                'currency' => $currencyCode,
            ] + $faceCheck);
        }

        ProductVariant::updateOrCreate(
            ['provider_offer_id' => $offerId],
            [
                'product_id' => $product->id,
                // Mirror whatever subcategory the product is currently on so an
                // admin-moved product propagates to its variants too.
                'subcategory_id' => $product->subcategory_id,
                'sku' => $rawItem['sku'] ?? $offerId,
                'currency' => $currencyCode,
                'face_value' => $faceValue,
                'cost_price' => $costPrice,
                'retail_price' => $retailPrice,
                'min_amount' => $minAmount,
                'max_amount' => $maxAmount,
                'is_variable' => $isVariable,
                'is_available' => (bool) ($rawItem['enabled'] ?? true) && ! FaceValueCheck::isSuspect($faceCheck),
                'metadata' => array_merge($rawItem, ['face_check' => $faceCheck]),
            ]
        );

        // Optional logo processing — topup operator logos arrive on the offer itself.
        if (! empty($rawItem['logoUrl']) && ! str_starts_with((string) $product->logo_url, 'local/')) {
            MediaProcessorJob::dispatch($product->id, $rawItem['logoUrl']);
        }

        return [$offerId];
    }
}
