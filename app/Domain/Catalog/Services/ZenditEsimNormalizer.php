<?php

namespace App\Domain\Catalog\Services;

use App\Jobs\MediaProcessorJob;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Subcategory;
use Illuminate\Support\Str;

class ZenditEsimNormalizer implements CatalogNormalizerInterface
{
    public function normalizeAndSave(array $rawItem, string $providerName): void
    {
        // 1. Ensure eSIMs Category exists
        $category = Category::firstOrCreate(
            ['slug' => 'esims'],
            ['name' => 'eSIMs', 'type' => 'digital']
        );

        // 2. Map to Data eSIM Subcategory
        $subcategory = Subcategory::firstOrCreate(
            ['category_id' => $category->id, 'slug' => 'data-esim'],
            ['name' => 'Data eSIMs', 'is_featured' => false]
        );

        // 3. Determine Region/Country Grouping
        // For eSIMs, the product represents the coverage area (e.g., "United States eSIM", "Europe Regional eSIM")
        // We do NOT use the brand name to separate products unless it's a completely different region.
        $rawCountry = strtoupper($rawItem['country'] ?? 'WW');
        $countryCode = strlen($rawCountry) === 2 ? $rawCountry : 'WW';
        $currencyCode = strtoupper($rawItem['currency'] ?? 'USD');

        // Use region if available to make the name nicer, e.g., "Europe", "Global", or fallback to country.
        $regionName = $rawItem['region'] ?? ($countryCode === 'WW' ? 'Global' : $countryCode);

        // We will group by the country/region string.
        $productSlug = Str::slug("esim-{$countryCode}-{$regionName}");
        $productName = $countryCode === 'WW' ? 'Global Data eSIM' : "{$regionName} Data eSIM";

        // Preserve admin-arranged categories on existing rows so the 6h re-sync
        // doesn't wipe manual organisation.
        $existing = Product::where('slug', $productSlug)->first();

        $product = Product::updateOrCreate(
            [
                'provider_name' => $providerName,
                'slug' => $productSlug,
            ],
            [
                'category_id' => $existing?->category_id ?? $category->id,
                'subcategory_id' => $existing?->subcategory_id ?? $subcategory->id,
                // country_code and name are admin-editable; preserve any edits.
                'country_code' => $existing?->country_code ?? $countryCode,
                'currency_code' => $currencyCode,
                'name' => $existing?->name ?? $productName,
                'description' => "High-speed data eSIM for {$regionName}. Scan QR code to activate.",
                'redeem_instructions' => 'Go to Settings > Cellular > Add Cellular Plan and scan the QR Code.',
                // Keep existing logo if we already processed it, otherwise take the raw one
                'logo_url' => $existing?->logo_url ?? ($rawItem['logoUrl'] ?? null),
            ]
        );

        // 4. Create/Update Variant (The actual data plan)
        // Zendit sends `cost` and `price` as objects: { fixed: 8121, currencyDivisor: 100 }.
        // The real amount is fixed / currencyDivisor (e.g. 8121/100 = $81.21). Casting the
        // object straight to float previously yielded 1.0, flat-pricing every eSIM at ~$1.
        $offerId = $rawItem['offerId'];
        $cost = is_array($rawItem['cost'] ?? null) ? $rawItem['cost'] : [];
        $price = is_array($rawItem['price'] ?? null) ? $rawItem['price'] : [];
        $costDivisor = (($cost['currencyDivisor'] ?? 100) ?: 100);
        $priceDivisor = (($price['currencyDivisor'] ?? 100) ?: 100);
        $costPrice = isset($cost['fixed']) ? (float) $cost['fixed'] / $costDivisor : 0.0;
        $faceValue = isset($price['fixed']) ? (float) $price['fixed'] / $priceDivisor : $costPrice;

        // Extract eSIM specific metadata
        // Zendit usually returns these in fields like 'dataAmount', 'validity', etc.
        $metadata = [
            'provider' => 'zendit',
            'provider_package_id' => $offerId,
            'plan_type' => 'data_only',
            'supports_data' => true,
            'supports_voice' => false,
            'supports_sms' => false,
            'data_limit' => $rawItem['dataAmount'] ?? 'Unknown',
            'validity_days' => (int) ($rawItem['validity'] ?? 0),
            'coverage' => isset($rawItem['coverage']) ? (array) $rawItem['coverage'] : [$countryCode],
            'network' => $rawItem['brand'] ?? 'Multiple', // Brand is usually the telecom operator
            'activation_policy' => 'automatic',
            'supports_topup' => (bool) ($rawItem['supportsTopup'] ?? false),
            // Store entire raw snapshot for debugging
            'raw_payload' => $rawItem,
        ];

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
                'retail_price' => $faceValue, // Default retail to face value for now
                'is_variable' => false,
                'is_available' => true,
                'metadata' => $metadata,
            ]
        );

        // Dispatch media processor if logo exists
        if (isset($rawItem['logoUrl']) && ! str_starts_with($product->logo_url, 'local/')) {
            MediaProcessorJob::dispatch($product->id, $rawItem['logoUrl']);
        }
    }
}
