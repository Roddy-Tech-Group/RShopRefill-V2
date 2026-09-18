<?php

namespace Tests\Feature\Dashboard;

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

/**
 * The dashboard's icons are the same SVG files as before, drawn inline through
 * x-ui.anim-icon so they can animate on hover. <img> tags and CSS masks cannot
 * be animated or recoloured from inside.
 */
class AnimatedDashboardIconsTest extends TestCase
{
    private function layout(): string
    {
        return file_get_contents(resource_path('views/components/layouts/dashboard.blade.php'));
    }

    public function test_the_icon_is_the_asset_file_drawn_inline(): void
    {
        $html = Blade::render('<x-ui.anim-icon file="new cart.svg" class="h-5 w-5" />');

        // The cart's own artwork: its first path starts with this segment.
        $this->assertStringContainsString('M9.46921 1.50002', $html);
        $this->assertStringContainsString('<svg aria-hidden="true" focusable="false"', $html);

        // Black becomes currentColor so the icon follows the text colour; ids
        // and the fixed 48px size are gone so it can repeat and size by class.
        $this->assertStringContainsString('fill="currentColor"', $html);
        $this->assertStringNotContainsString('#000000', $html);
        $this->assertStringNotContainsString(' id="', $html);
        $this->assertStringNotContainsString('width="48"', $html);
        $this->assertStringNotContainsString('<desc>', $html);

        $this->assertStringContainsString('anim-icon--cart', $html);
        $this->assertStringContainsString('text-black', $html);
    }

    public function test_each_icon_gets_a_motion_that_suits_it(): void
    {
        foreach ([
            'notification 2.svg' => 'ring',
            'system setting.svg' => 'spin',
            'Back to shop.svg' => 'nudge',
            'Hamburger menu.svg' => 'squeeze',
        ] as $file => $motion) {
            $this->assertStringContainsString(
                'anim-icon--'.$motion,
                Blade::render('<x-ui.anim-icon :file="$file" />', ['file' => $file]),
                $file.' should '.$motion.'.'
            );
        }
    }

    public function test_tones_control_the_colour(): void
    {
        $this->assertStringContainsString('text-white', Blade::render('<x-ui.anim-icon file="Home.svg" tone="white" />'));
        $this->assertStringNotContainsString('text-black', Blade::render('<x-ui.anim-icon file="Home.svg" tone="inherit" />'));
    }

    /** It reads from disk, so a path that climbs out of assets/ renders nothing. */
    public function test_a_path_outside_assets_is_refused(): void
    {
        $html = Blade::render('<x-ui.anim-icon file="../../.env" />');

        $this->assertStringNotContainsString('<svg', $html);
        $this->assertStringNotContainsString('APP_KEY', $html);
    }

    public function test_the_dashboard_layout_has_no_static_icon_images_or_masks_left(): void
    {
        $layout = $this->layout();

        $this->assertStringNotContainsString('mask: url(', $layout);
        $this->assertStringNotContainsString('filter: brightness(0) saturate(100%)', $layout);
        $this->assertGreaterThanOrEqual(18, substr_count($layout, '<x-ui.anim-icon'));
    }

    /**
     * Both dashboard carts use the storefront's own glyph (x-icons.cart), not
     * the heavier filled "new cart.svg", and still roll on hover.
     */
    public function test_the_dashboard_cart_is_the_storefront_glyph(): void
    {
        $layout = $this->layout();

        $this->assertStringNotContainsString('new cart.svg', $layout);
        $this->assertSame(2, substr_count($layout, 'anim-icon anim-icon--cart'));
        $this->assertSame(2, substr_count($layout, '<x-icons.cart />'));
        $this->assertStringContainsString('<x-icons.cart', file_get_contents(resource_path('views/components/nav/account-cart.blade.php')));
    }

    /** Every icon file the layout names exists, or its slot would render empty. */
    public function test_every_icon_file_the_layout_names_exists(): void
    {
        preg_match_all('/x-ui\.anim-icon file="([^"]+)"/', $this->layout(), $literal);
        preg_match_all("/'icon'\s*=>\s*'([^']+\.svg)'/", $this->layout(), $listed);
        preg_match_all("/\['[^']+',\s*'[^']+',\s*'([^']+\.svg)'/", $this->layout(), $search);

        $files = array_unique(array_merge($literal[1], $listed[1], $search[1]));
        $this->assertNotEmpty($files);

        foreach ($files as $file) {
            $this->assertFileExists(public_path('assets/'.$file));
        }
    }

    /** Every motion has keyframes, and reduced motion turns them all off. */
    public function test_the_motions_are_defined_and_respect_reduced_motion(): void
    {
        $css = file_get_contents(resource_path('css/app.css'));

        foreach (['cart', 'ring', 'pop', 'bounce', 'spin', 'jiggle', 'tilt', 'shake', 'wiggle', 'nod', 'nudge', 'squeeze', 'flip'] as $motion) {
            $this->assertStringContainsString('@keyframes anim-icon-'.$motion.' ', $css);
            $this->assertStringContainsString('.anim-icon--'.$motion.':hover', $css);
        }

        $this->assertMatchesRegularExpression('/prefers-reduced-motion: reduce\)\s*\{\s*\.anim-icon \{ animation: none !important; \}/', $css);
    }
}
