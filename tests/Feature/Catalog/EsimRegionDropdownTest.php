<?php

namespace Tests\Feature\Catalog;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The eSIM store "Change country or region" dropdown: it must (1) follow the page's
 * navy design system (a solid panel matching the selector card, not the foreign grey
 * .glass-panel), and (2) stay backdrop-filter-free so the country list scrolls on
 * mobile - backdrop-filter + the overflow-hidden used for rounded corners is the iOS
 * Safari combo that broke touch scrolling.
 */
class EsimRegionDropdownTest extends TestCase
{
    use RefreshDatabase;

    private function esimProduct(): Product
    {
        $category = Category::create(['name' => 'eSIMs', 'slug' => 'esims', 'type' => 'digital']);

        $product = Product::create([
            'category_id' => $category->id,
            'provider_name' => 'zendit',
            'country_code' => 'US',
            'currency_code' => 'USD',
            'name' => 'United States Data eSIM',
            'slug' => 'united-states-data-esim',
            'is_active' => true,
        ]);

        ProductVariant::create([
            'product_id' => $product->id,
            'provider_offer_id' => 'ESIM-US-7D-1GB',
            'currency' => 'USD',
            'face_value' => 2.03,
            'cost_price' => 1.50,
            'retail_price' => 2.03,
            'is_available' => true,
            'metadata' => ['raw_payload' => ['dataGB' => 1, 'durationDays' => 7, 'dataUnlimited' => false]],
        ]);

        return $product;
    }

    public function test_the_region_dropdown_scroll_list_has_the_mobile_touch_scroll_recipe(): void
    {
        $this->withoutVite();
        $this->esimProduct();

        $html = $this->get(route('shop.esim', 'united-states-data-esim'))->assertOk()->getContent();

        // The dropdown exists...
        $this->assertStringContainsString('Search a country or region', $html);
        // ...and its list carries the exact recipe the page's modal lists use to
        // scroll on iOS Safari (the region list was the only overlay missing it).
        // p-1 (not the modals' pr-1) keeps this assertion specific to the dropdown.
        $this->assertStringContainsString('max-h-72 overflow-y-auto overscroll-contain p-1 [-webkit-overflow-scrolling:touch]', $html);
    }

    public function test_the_region_dropdown_uses_a_solid_navy_panel_not_the_grey_glass_panel(): void
    {
        $this->withoutVite();
        $this->esimProduct();

        $html = $this->get(route('shop.esim', 'united-states-data-esim'))->assertOk()->getContent();

        // Reskinned to a solid navy panel (matching the selector card). It is
        // deliberately NOT a backdrop-filter panel: backdrop-filter + the
        // overflow-hidden needed for rounded corners is the iOS combo that broke
        // touch scrolling, so the panel string must stay backdrop-blur-free.
        $this->assertStringContainsString('mt-2 overflow-hidden rounded-[12px] bg-white shadow-xl shadow-zinc-900/15 ring-1 ring-zinc-200 dark:bg-[#0c1a36] ', $html);
        // ...off the foreign grey .glass-panel and its grey item hover.
        $this->assertStringNotContainsString('glass-panel', $html);
        $this->assertStringNotContainsString('dark:hover:bg-zinc-800', $html);
        // Navy hover token instead of the grey one.
        $this->assertStringContainsString('dark:hover:bg-[#26416b]', $html);
    }

    public function test_the_region_dropdown_exposes_listbox_accessibility(): void
    {
        $this->withoutVite();
        $this->esimProduct();

        $html = $this->get(route('shop.esim', 'united-states-data-esim'))->assertOk()->getContent();

        $this->assertStringContainsString('aria-haspopup="listbox"', $html);
        $this->assertStringContainsString('role="listbox"', $html);
    }
}
