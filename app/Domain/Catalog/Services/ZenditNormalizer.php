<?php

namespace App\Domain\Catalog\Services;

use App\Jobs\MediaProcessorJob;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Subcategory;
use Illuminate\Support\Str;

class ZenditNormalizer implements CatalogNormalizerInterface
{
    public function normalizeAndSave(array $rawItem, string $providerName): void
    {
        // 1. Normalize Subtype. Zendit returns subTypes as an array; pick the first.
        $subTypes = $rawItem['subTypes'] ?? [];
        $subtypeName = is_array($subTypes) && ! empty($subTypes) ? $subTypes[0] : ($rawItem['subtype'] ?? 'Uncategorized');
        $subcategorySlug = Str::slug($subtypeName);

        // 2. Resolve the Category. Prepaid Utilities arrive on the SAME
        // /vouchers/offers feed as gift cards (Zendit tags both productType
        // VOUCHER); the "Utilities" subType is the classifier. Route those into
        // their own Bill Payments category so they form a distinct storefront
        // instead of polluting the gift-card grid.
        $isUtility = strtolower($subtypeName) === 'utilities';

        $category = $isUtility
            ? Category::firstOrCreate(['slug' => 'bill-payments'], ['name' => 'Bill Payments', 'type' => 'digital'])
            : Category::firstOrCreate(['slug' => 'gift-cards'], ['name' => 'Gift Cards', 'type' => 'digital']);

        $subcategory = Subcategory::firstOrCreate(
            ['category_id' => $category->id, 'slug' => $subcategorySlug],
            ['name' => $subtypeName, 'is_featured' => false]
        );

        // 3. Determine Brand and Product Grouping.
        // Zendit exposes both a short machine key (brand) and a human label (brandName).
        $brandKey = $rawItem['brand'] ?? 'unknown';
        $brandLabel = $rawItem['brandName'] ?? $brandKey;
        $countryCode = strtoupper($rawItem['country'] ?? 'US');

        // Currency lives on the nested send/price objects (denominated in cents via currencyDivisor).
        $priceBlock = $rawItem['price'] ?? [];
        $sendBlock = $rawItem['send'] ?? [];
        $costBlock = $rawItem['cost'] ?? [];
        // The card's currency is the SEND block — the value loaded onto the recipient's
        // account, in the card's own currency (e.g. a UK Amazon card is GBP). The price
        // block is Zendit's USD price; the cost block is our USD cost. Don't use those
        // for the card currency, or a £-card shows as a $-card.
        $currencyCode = strtoupper($sendBlock['currency'] ?? $priceBlock['currency'] ?? $rawItem['currency'] ?? 'USD');

        // Products are grouped by Brand + Country
        $productSlug = Str::slug("{$brandKey}-{$countryCode}-{$providerName}");

        // Preserve any fields that were hydrated by ZenditBrandSyncService (logo, hero,
        // brand_color, description, redeem_instructions, terms_and_conditions). The
        // /vouchers/offers payload doesn't include those — only /brands/* does — so
        // we mustn't blow them away on a routine catalog re-sync.
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
                'name' => $existing?->name ?? "{$brandLabel} ({$countryCode})",
                'description' => $existing?->description
                    ?: ($rawItem['shortNotes'] ?? $rawItem['notes'] ?? $rawItem['description'] ?? "{$brandLabel} Gift Card for {$countryCode}"),
                'redeem_instructions' => $existing?->redeem_instructions ?: ($rawItem['redeem_instructions'] ?? null),
                'terms_and_conditions' => $existing?->terms_and_conditions ?: ($rawItem['terms'] ?? null),
                'logo_url' => $existing?->logo_url ?: ($rawItem['logoUrl'] ?? null),
                'featured_image' => $existing?->featured_image,
                'brand_color' => $existing?->brand_color,
            ]
        );

        // 4. Create/Update Variant. Zendit money fields are integer minor units; divide by currencyDivisor.
        $offerId = $rawItem['offerId'];
        $priceType = $rawItem['priceType'] ?? 'FIXED';

        $priceDiv = (float) ($priceBlock['currencyDivisor'] ?? 100);
        $sendDiv = (float) ($sendBlock['currencyDivisor'] ?? 100);
        $costDiv = (float) ($costBlock['currencyDivisor'] ?? 100);

        // Face value = the SEND block (what the recipient actually receives, in the
        // card's own currency). cost_price below is the COST block — always USD, our
        // settlement base for the markup engine.
        $faceValue = isset($sendBlock['fixed']) ? ((float) $sendBlock['fixed']) / $sendDiv
                     : (isset($priceBlock['fixed']) ? ((float) $priceBlock['fixed']) / $priceDiv : null);
        $costPrice = isset($costBlock['fixed']) ? ((float) $costBlock['fixed']) / $costDiv : ($faceValue ?? 0.0);
        $retailPrice = $faceValue ?? $costPrice;

        // VARIABLE-priced cards expose min/max on the send block.
        $minAmount = isset($sendBlock['min']) ? ((float) $sendBlock['min']) / $sendDiv : null;
        $maxAmount = isset($sendBlock['max']) ? ((float) $sendBlock['max']) / $sendDiv : null;

        $isVariable = $priceType === 'VARIABLE'
            || ($minAmount !== null && $maxAmount !== null && $minAmount !== $maxAmount);

        ProductVariant::updateOrCreate(
            ['provider_offer_id' => $offerId],
            [
                'product_id' => $product->id,
                // Mirror whatever subcategory the product is currently on (which
                // may have been moved by an admin) instead of re-applying the
                // raw provider subcategory.
                'subcategory_id' => $product->subcategory_id,
                'sku' => $rawItem['sku'] ?? $offerId,
                'currency' => $currencyCode,
                'face_value' => $faceValue,
                'cost_price' => $costPrice,
                'retail_price' => $retailPrice,
                'min_amount' => $minAmount,
                'max_amount' => $maxAmount,
                'is_variable' => $isVariable,
                'is_available' => (bool) ($rawItem['enabled'] ?? true),
                'metadata' => $rawItem,
            ]
        );

        // Optional logo processing (Zendit offers list doesn't return one, but keep the hook for future).
        if (! empty($rawItem['logoUrl']) && ! str_starts_with((string) $product->logo_url, 'local/')) {
            MediaProcessorJob::dispatch($product->id, $rawItem['logoUrl']);
        }
    }
}
