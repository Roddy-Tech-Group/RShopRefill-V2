<?php

namespace Tests\Feature\Catalog;

use App\Models\Product;
use Tests\TestCase;

/**
 * Every mapped brand logo must point at a file that actually exists.
 *
 * A logo is wired in two places: the file in public/assets and the row in
 * config/brand_assets.php. Dropping the file in on its own changes nothing,
 * and a row pointing at a missing or renamed file renders a broken image
 * rather than the colour tile the view falls back to when no row exists. Both
 * failures are silent in the browser, so they are pinned here.
 */
class BrandLogoAssetTest extends TestCase
{
    /** Nigeria's four networks, none of which get a logo_url from the sync. */
    private const NIGERIAN_NETWORKS = ['MTN', 'Glo', 'Airtel', 'NineMobile'];

    public function test_every_mapped_logo_file_exists(): void
    {
        $logos = config('brand_assets.logos', []);

        $this->assertNotEmpty($logos, 'the brand logo map should not be empty');

        foreach ($logos as $brandKey => $file) {
            $this->assertFileExists(
                public_path('assets/'.$file),
                "{$brandKey} maps to assets/{$file}, which is not there"
            );
        }
    }

    /**
     * A file with no extension is served without an image content type and a
     * browser will not render it in an img tag, so the tile silently breaks.
     */
    public function test_every_mapped_logo_has_an_image_extension(): void
    {
        foreach (config('brand_assets.logos', []) as $brandKey => $file) {
            $this->assertMatchesRegularExpression(
                '/\.(webp|png|jpe?g|svg)$/i',
                $file,
                "{$brandKey} maps to \"{$file}\", which has no image extension"
            );
        }
    }

    /** All four Nigerian networks resolve to a logo, not the colour fallback. */
    public function test_the_nigerian_networks_all_resolve_to_a_logo(): void
    {
        foreach (self::NIGERIAN_NETWORKS as $brandKey) {
            $url = Product::brandLogoUrl($brandKey, null);

            $this->assertNotNull(
                $url,
                "{$brandKey} has no logo mapped, so its tile falls back to a flat colour"
            );

            $this->assertFileExists(public_path('assets/'.basename((string) $url)));
        }
    }
}
