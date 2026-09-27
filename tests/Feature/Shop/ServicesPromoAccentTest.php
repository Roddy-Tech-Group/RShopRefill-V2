<?php

namespace Tests\Feature\Shop;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * The "What we bring" panel highlights its service count in blue.
 *
 * `app.css` deliberately keeps light blue out of the app: the pale end of
 * Tailwind's blue ramp is redefined as neutral grey, so every `text-blue-300`
 * and `text-blue-400` accent in the codebase turns grey at once. The highlight
 * was written as `text-blue-400` and so rendered grey on the dark panel - the
 * class was there, the colour behind it was not.
 *
 * These tests pin the accent to a shade the override leaves alone, and derive
 * the neutralised set from the stylesheet so the guard follows the palette
 * rather than a hard-coded list.
 */
class ServicesPromoAccentTest extends TestCase
{
    private function promo(): string
    {
        return File::get(resource_path('views/components/home/services-promo.blade.php'));
    }

    private function css(): string
    {
        return File::get(resource_path('css/app.css'));
    }

    /**
     * The blue shades `app.css` redefines as neutral grey.
     *
     * @return array<int, int>
     */
    private function neutralisedBlueShades(): array
    {
        preg_match_all('/--color-blue-(\d+):/', $this->css(), $matches);

        return array_map('intval', $matches[1]);
    }

    /** The shade the highlight actually asks for. */
    private function accentShade(): int
    {
        preg_match(
            '/\$promoServices = \'<span class="[^"]*text-blue-(\d+)[^"]*">/',
            $this->promo(),
            $matches
        );

        $this->assertNotEmpty($matches, 'the service count must carry a text-blue-* accent');

        return (int) $matches[1];
    }

    public function test_the_service_count_is_highlighted_in_blue(): void
    {
        $this->assertNotContains(
            $this->accentShade(),
            $this->neutralisedBlueShades(),
            'the accent uses a blue that app.css redefines as grey, so it renders neutral'
        );
    }

    /** The override exists, or the test above would pass on an empty set. */
    public function test_the_pale_blue_ramp_is_still_neutralised(): void
    {
        $this->assertNotEmpty(
            $this->neutralisedBlueShades(),
            'app.css no longer remaps any blue - the accent guard would be vacuous'
        );
    }

    /** The count itself stays translatable rather than baked into the markup. */
    public function test_the_count_is_read_from_the_language_files(): void
    {
        $this->assertStringContainsString("__('home.promo_services_count')", $this->promo());
        $this->assertStringNotContainsString('23+ digital services', $this->promo());
    }
}
