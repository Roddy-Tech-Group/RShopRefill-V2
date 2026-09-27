<?php

namespace Tests\Feature\Catalog;

use App\Http\Controllers\EsimStoreController;
use Tests\TestCase;

class EsimRegionMetaTest extends TestCase
{
    public function test_a_real_country_is_local_with_its_clean_name(): void
    {
        $meta = EsimStoreController::regionMeta('US', 'united-states-data-esim', 'United States Data eSIM');

        $this->assertSame('local', $meta['scope']);
        $this->assertSame('United States', $meta['name']);
        $this->assertSame('US', $meta['cc']);
    }

    public function test_continents_are_regional_named_from_the_slug(): void
    {
        // Suppliers label these "Global eSIM"; the real region lives in the slug.
        $this->assertSame('regional', EsimStoreController::regionMeta('WW', 'esim-ww-europe', 'Global eSIM')['scope']);
        $this->assertSame('Europe', EsimStoreController::regionMeta('WW', 'esim-ww-europe', 'Global eSIM')['name']);

        $this->assertSame('regional', EsimStoreController::regionMeta('WW', 'esim-ww-asia', 'Global eSIM')['scope']);
        $this->assertSame('regional', EsimStoreController::regionMeta('WW', 'esim-ww-latin-america', 'Global eSIM')['scope']);

        $mena = EsimStoreController::regionMeta('WW', 'esim-ww-middle-east-and-north-africa', 'Global eSIM');
        $this->assertSame('regional', $mena['scope']);
        $this->assertSame('Middle East and North Africa', $mena['name']);
    }

    public function test_worldwide_products_are_global(): void
    {
        $this->assertSame('global', EsimStoreController::regionMeta('WW', 'esim-ww-discover-global', 'Global eSIM')['scope']);
        // Sold as "Global", never under the supplier's own product name.
        $this->assertSame('Global', EsimStoreController::regionMeta('WW', 'esim-ww-discover-global', 'Global eSIM')['name']);

        // Zendit's empty-country catch-all.
        $zendit = EsimStoreController::regionMeta('', 'esim', ' Data eSIM');
        $this->assertSame('global', $zendit['scope']);
        $this->assertSame('Global', $zendit['name']);
    }

    public function test_single_named_territories_are_local(): void
    {
        $this->assertSame('local', EsimStoreController::regionMeta('WW', 'esim-ww-scotland', 'Global eSIM')['scope']);
        $this->assertSame('Scotland', EsimStoreController::regionMeta('WW', 'esim-ww-scotland', 'Global eSIM')['name']);
        $this->assertSame('local', EsimStoreController::regionMeta('WW', 'esim-ww-azores', 'Global eSIM')['scope']);
    }
}
