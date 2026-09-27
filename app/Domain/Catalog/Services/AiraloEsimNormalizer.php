<?php

namespace App\Domain\Catalog\Services;

use App\Enums\PricingBasis;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Subcategory;
use Illuminate\Support\Str;

class AiraloEsimNormalizer implements CatalogNormalizerInterface
{
    public function normalizeAndSave(array $rawItem, string $providerName): array
    {
        // 1. Ensure eSIMs Category exists
        $category = Category::firstOrCreate(
            ['slug' => 'esims'],
            ['name' => 'eSIMs', 'type' => 'digital']
        );

        // 2. Map to Data eSIM Subcategory (fallback)
        $subcategory = Subcategory::firstOrCreate(
            ['category_id' => $category->id, 'slug' => 'data-esim'],
            ['name' => 'Data eSIMs', 'is_featured' => false]
        );

        // $rawItem is a Country/Region object from Airalo which contains operators -> packages
        $rawLocation = strtoupper($rawItem['country_code'] ?? 'WW');
        $countryCode = strlen($rawLocation) === 2 ? $rawLocation : 'WW';
        $regionName = $rawItem['title'] ?? ($countryCode === 'WW' ? 'Global' : $countryCode);

        // Name every product by its real region ("Middle East and North Africa eSIM",
        // "Discover Global eSIM", "Europe eSIM"). Non-country products used to be a
        // blanket "Global eSIM", which made the admin product list / command bar a wall
        // of identical rows. The storefront already derives the display name from the
        // slug; this stores the same region name so admin surfaces match.
        $productName = "{$regionName} eSIM";

        // Airalo's own slug ("united-states", "discover-global") is the stable
        // identity of a package group; the title is not (Airalo renamed
        // "Discover Global" to "Global" on 2026-08-27). It is stored in
        // provider_reference so a rename updates the row instead of inserting
        // a duplicate that then collides with another provider's slug.
        $providerReference = isset($rawItem['slug']) && is_string($rawItem['slug']) && $rawItem['slug'] !== ''
            ? $rawItem['slug']
            : null;

        $existing = $this->findExistingProduct($providerName, $providerReference, $countryCode, $regionName, $rawItem);

        // Slugs are storefront URLs, so an existing product keeps its slug;
        // only a brand-new product gets one, and only one nobody else owns.
        $productSlug = $existing?->slug ?? $this->availableSlug($providerName, $countryCode, $regionName);

        $product = $existing ?? new Product;
        $product->fill(
            [
                'provider_name' => $providerName,
                'provider_reference' => $providerReference ?? $existing?->provider_reference,
                'slug' => $productSlug,
                // Preserve admin-arranged categories on existing rows so the hourly
                // re-sync doesn't wipe manual organisation.
                'category_id' => $existing?->category_id ?? $category->id,
                'subcategory_id' => $existing?->subcategory_id ?? $subcategory->id,
                // country_code and name are admin-editable; preserve any edits.
                'country_code' => $existing?->country_code ?? $countryCode,
                'currency_code' => 'USD', // Airalo defaults to USD
                // face_value stores Airalo's recommended retail (pkg.price) —
                // markup runs on it. Never overwrites an existing choice.
                'pricing_basis' => $existing?->pricing_basis ?? PricingBasis::ProviderRetail,
                'name' => $existing?->name ?? $productName,
                // Update description generically to accommodate both data and voice
                'description' => "High-speed eSIM for {$regionName}. Scan QR code to activate.",
                'redeem_instructions' => 'Go to Settings > Cellular > Add Cellular Plan and scan the QR Code.',
                'logo_url' => $rawItem['image']['url'] ?? null,
            ]
        );
        $product->save();

        // 4. Variant Normalization
        $operators = $rawItem['operators'] ?? [];
        $savedOfferIds = [];

        foreach ($operators as $operator) {
            $network = $operator['title'] ?? 'Multiple';
            $packages = $operator['packages'] ?? [];

            // Real carrier networks for this operator (Airalo exposes them per
            // coverage as { name, networks: [{ name, types }] }). Two shapes
            // are kept:
            //   networks_detail   - flat, deduped by carrier name (single-country pages)
            //   coverage_networks - grouped per country, powering the
            //                       Countries & Networks modal on regional/global eSIMs
            $networksByName = [];
            $coverageNetworks = [];
            foreach (($operator['coverages'] ?? []) as $coverage) {
                $countryNets = [];
                foreach ((array) ($coverage['networks'] ?? []) as $net) {
                    if (! is_array($net) || empty($net['name'])) {
                        continue;
                    }
                    $speed = null;
                    foreach ((array) ($net['types'] ?? []) as $type) {
                        $s = is_array($type) ? ($type['name'] ?? null) : $type;
                        if ($s) {
                            $speed = $s;
                        }
                    }
                    $networksByName[$net['name']] = $speed;
                    $countryNets[] = ['name' => $net['name'], 'speed' => $speed];
                }
                $coverageCountry = is_array($coverage) ? ($coverage['name'] ?? null) : null;
                if ($coverageCountry && $countryNets !== []) {
                    $coverageNetworks[] = ['country' => (string) $coverageCountry, 'networks' => $countryNets];
                }
            }
            $networksDetail = [];
            foreach ($networksByName as $name => $speed) {
                $networksDetail[] = ['name' => $name, 'speed' => $speed];
            }

            foreach ($packages as $pkg) {
                $offerId = $pkg['id'] ?? null;
                if (! $offerId) {
                    continue;
                }

                $costPrice = (float) ($pkg['net_price'] ?? $pkg['price'] ?? 0);
                $faceValue = (float) ($pkg['price'] ?? $costPrice);

                // Voice/SMS come ONLY from the package's real numeric allowance. The
                // operator-level plan_type is unreliable (it tags data-only packages
                // as voice). No "Included" placeholder — show only what the data says.
                $voiceVal = $pkg['voice'] ?? null;
                $smsVal = $pkg['text'] ?? null;
                $supportsVoice = is_numeric($voiceVal) && (float) $voiceVal > 0;
                $supportsSms = is_numeric($smsVal) && (float) $smsVal > 0;
                $supportsData = ! empty($pkg['data']) || ! empty($pkg['is_unlimited']);

                $planType = ($supportsVoice || $supportsSms) ? 'voice_sms_data' : 'data_only';

                $metadata = [
                    'provider' => 'airalo',
                    'provider_package_id' => $offerId,
                    'plan_type' => $planType,
                    'supports_data' => $supportsData,
                    'supports_voice' => $supportsVoice,
                    'supports_sms' => $supportsSms,
                    'data_limit' => $pkg['data'] ?? null,
                    'voice_limit' => $supportsVoice ? (string) $voiceVal : null,
                    'sms_limit' => $supportsSms ? (string) $smsVal : null,
                    'validity_days' => (int) ($pkg['day'] ?? 0),
                    'countries' => [$countryCode], // Could be an array for regional
                    'network' => $network,
                    'networks_detail' => $networksDetail,
                    'coverage_networks' => $coverageNetworks,
                    // Provider policy notes, verbatim from the supplier - the
                    // storefront's Package details modal renders these as-is.
                    'operator_info' => array_values(array_filter(array_map(
                        fn ($i) => is_scalar($i) ? trim((string) $i) : null,
                        (array) ($operator['info'] ?? []),
                    ))),
                    'other_info' => is_scalar($operator['other_info'] ?? null) ? trim((string) $operator['other_info']) : null,
                    'activation_policy' => $operator['activation_policy'] ?? 'automatic',
                    'is_rechargeable' => (bool) ($operator['rechargeability'] ?? false),
                    'raw_payload' => $pkg,
                ];

                ProductVariant::updateOrCreate(
                    ['provider_offer_id' => "airalo_{$offerId}"], // Namespace to avoid ID collisions
                    [
                        'product_id' => $product->id,
                        // Mirror whatever subcategory the product is currently on so an
                        // admin-moved product propagates to its variants too.
                        'subcategory_id' => $product->subcategory_id,
                        'sku' => "AIRALO-{$offerId}",
                        'currency' => 'USD',
                        'face_value' => $faceValue,
                        'cost_price' => $costPrice,
                        'retail_price' => $faceValue,
                        'is_variable' => false,
                        'supplier_available' => true,
                        'is_available' => true,
                        'metadata' => $metadata,
                    ]
                );

                $savedOfferIds[] = "airalo_{$offerId}";
            }
        }

        return $savedOfferIds;
    }

    /**
     * Resolve the product this Airalo item already maps to, in order of how
     * stable the key is: Airalo's slug, then our derived slug, then whichever
     * product this item's packages were attached to last time (covers rows
     * synced before provider_reference was recorded, across a title rename).
     *
     * @param  array<string, mixed>  $rawItem
     */
    private function findExistingProduct(
        string $providerName,
        ?string $providerReference,
        string $countryCode,
        string $regionName,
        array $rawItem,
    ): ?Product {
        if ($providerReference !== null) {
            $byReference = Product::where('provider_name', $providerName)
                ->where('provider_reference', $providerReference)
                ->first();
            if ($byReference) {
                return $byReference;
            }
        }

        $bySlug = Product::where('provider_name', $providerName)
            ->where('slug', Str::slug("esim-{$countryCode}-{$regionName}"))
            ->first();
        if ($bySlug) {
            return $bySlug;
        }

        foreach (($rawItem['operators'] ?? []) as $operator) {
            foreach (($operator['packages'] ?? []) as $pkg) {
                if (empty($pkg['id'])) {
                    continue;
                }
                $variant = ProductVariant::with('product')
                    ->where('provider_offer_id', "airalo_{$pkg['id']}")
                    ->first();
                if ($variant?->product && $variant->product->provider_name === $providerName) {
                    return $variant->product;
                }
            }
        }

        return null;
    }

    /**
     * The products table enforces slug uniqueness globally, so when another
     * provider already owns the natural slug ("esim-ww-global" is Zendit's)
     * a new product takes a provider-suffixed one instead of crashing the
     * whole sync page on the unique index.
     */
    private function availableSlug(string $providerName, string $countryCode, string $regionName): string
    {
        $slug = Str::slug("esim-{$countryCode}-{$regionName}");
        if (! Product::where('slug', $slug)->exists()) {
            return $slug;
        }

        $suffixed = Str::slug("{$slug}-{$providerName}");
        $candidate = $suffixed;
        for ($i = 2; Product::where('slug', $candidate)->exists(); $i++) {
            $candidate = "{$suffixed}-{$i}";
        }

        return $candidate;
    }
}
