<?php

namespace App\Domain\Catalog\Services;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Subcategory;
use Illuminate\Support\Str;

/**
 * Turns Airalo's per-eSIM top-up packages (from /sims/{iccid}/topups) into
 * catalog rows so admins can SEE and PRICE each top-up in Admin -> Products,
 * exactly like a fresh eSIM plan.
 *
 * Airalo exposes no global top-up list - top-ups only come back per installed
 * eSIM - so the catalog is populated from live top-up fetches: the backfill
 * command (airalo:sync-topups) walks existing eSIMs, and the top-up page upserts
 * passively on view. Variants are keyed by the Airalo package id, so repeat
 * syncs update in place and never duplicate, and admin edits (a manual price
 * override, an availability toggle) are preserved across re-syncs.
 */
class AiraloTopupCatalogService
{
    /**
     * The eSIMs > "Airalo Top-Up" subcategory, created on first use.
     */
    public function subcategory(): Subcategory
    {
        $category = Category::firstOrCreate(
            ['slug' => 'esims'],
            ['name' => 'eSIMs', 'type' => 'digital'],
        );

        return Subcategory::firstOrCreate(
            ['category_id' => $category->id, 'slug' => 'airalo-topup'],
            ['name' => 'Airalo Top-Up', 'is_featured' => false],
        );
    }

    /**
     * Upsert the live top-up packages for one parent eSIM into the catalog and
     * return a map of [airalo package id => ProductVariant] the caller prices from.
     *
     * @param  array<int, array<string, mixed>>  $packages
     * @return array<string, ProductVariant>
     */
    public function upsertForParent(Product $parentEsim, array $packages): array
    {
        $subcategory = $this->subcategory();
        $product = $this->topupProductFor($parentEsim, $subcategory);

        $map = [];
        foreach ($packages as $pkg) {
            $id = $pkg['id'] ?? null;
            if (! is_scalar($id) || (string) $id === '') {
                continue;
            }
            $id = (string) $id;

            $netCost = (float) ($pkg['net_price'] ?? $pkg['price'] ?? 0);
            $faceValue = (float) ($pkg['price'] ?? $netCost);
            if ($netCost <= 0 && $faceValue <= 0) {
                continue;
            }

            $offerId = "airalo_topup_{$id}";
            // Preserve admin toggles across re-syncs: keep whatever availability
            // an admin set, and never overwrite a manual price override (we simply
            // don't pass manual_retail_price_usd, so updateOrCreate leaves it).
            $existing = ProductVariant::where('provider_offer_id', $offerId)->first();

            $variant = ProductVariant::updateOrCreate(
                ['provider_offer_id' => $offerId],
                [
                    'product_id' => $product->id,
                    'subcategory_id' => $product->subcategory_id,
                    'sku' => "AIRALO-TOPUP-{$id}",
                    'currency' => 'USD',
                    'face_value' => $faceValue,
                    'cost_price' => $netCost,
                    'retail_price' => $faceValue,
                    'is_variable' => false,
                    'is_available' => $existing?->is_available ?? true,
                    'metadata' => $this->variantMetadata($pkg, $id, $parentEsim),
                ],
            );

            $map[$id] = $variant;
        }

        return $map;
    }

    /**
     * Find/create the "{region} Top-Up" product this parent eSIM's top-ups hang
     * off. One product per parent eSIM region so the admin card reads e.g.
     * "United States Top-Up" instead of a single undifferentiated bucket.
     */
    private function topupProductFor(Product $parentEsim, Subcategory $subcategory): Product
    {
        $tail = (string) Str::of((string) $parentEsim->slug)->after('esim-');
        $slug = 'airalo-topup-'.($tail !== '' ? $tail : (Str::slug((string) $parentEsim->slug) ?: 'global'));

        // "United States eSIM" -> "United States Top-Up"
        $name = trim((string) preg_replace('/\beSIMs?\b/i', '', (string) $parentEsim->name));
        $name = ($name !== '' ? $name : 'Airalo').' Top-Up';

        $existing = Product::where('slug', $slug)->first();

        return Product::updateOrCreate(
            ['provider_name' => 'airalo', 'slug' => $slug],
            [
                // Preserve admin organisation/edits on re-sync.
                'category_id' => $existing?->category_id ?? $subcategory->category_id,
                'subcategory_id' => $existing?->subcategory_id ?? $subcategory->id,
                'country_code' => $existing?->country_code ?? $parentEsim->country_code,
                'currency_code' => 'USD',
                'name' => $existing?->name ?? $name,
                'description' => "Top-up packages for {$parentEsim->name}. Adds data/validity to an existing eSIM.",
                'logo_url' => $existing?->logo_url ?? $parentEsim->logo_url,
                // Hidden from the storefront eSIM store by default (top-ups are
                // bought per-eSIM from the top-up page, not browsed as fresh
                // eSIMs) - the store filters is_active. Admin still sees + prices
                // them (the admin catalog lists inactive products too), and the
                // top-up checkout reads the variant regardless. Preserve any admin
                // toggle on re-sync.
                'is_active' => $existing?->is_active ?? false,
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $pkg
     * @return array<string, mixed>
     */
    private function variantMetadata(array $pkg, string $id, Product $parentEsim): array
    {
        $voiceVal = $pkg['voice'] ?? null;
        $smsVal = $pkg['text'] ?? null;
        $supportsVoice = is_numeric($voiceVal) && (float) $voiceVal > 0;
        $supportsSms = is_numeric($smsVal) && (float) $smsVal > 0;

        return [
            'provider' => 'airalo',
            'provider_package_id' => $id,
            'is_topup' => true,
            'plan_type' => ($supportsVoice || $supportsSms) ? 'voice_sms_data' : 'data_only',
            'data_limit' => $pkg['data'] ?? null,
            'voice_limit' => $supportsVoice ? (string) $voiceVal : null,
            'sms_limit' => $supportsSms ? (string) $smsVal : null,
            'validity_days' => (int) ($pkg['day'] ?? 0),
            'parent_product_id' => $parentEsim->id,
            'parent_country_code' => $parentEsim->country_code,
            'raw_payload' => $pkg,
        ];
    }
}
