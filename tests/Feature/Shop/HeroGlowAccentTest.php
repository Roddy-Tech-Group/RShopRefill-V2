<?php

namespace Tests\Feature\Shop;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * The hero's cursor-glow accent has to out-rank the storefront's ink override.
 *
 * The heading is `text-zinc-900`, and light-mode storefront pages remap that
 * to the brand navy through `html:not(.dark) body.storefront .text-zinc-900`.
 * A bare `.hero-glow-accent` class cannot outrank a selector that specific, so
 * the glow painted the exact colour of the heading beneath it and the effect
 * was invisible - in light only. Dark worked by accident: both rules there are
 * `.dark`-scoped, so the later one simply won.
 */
class HeroGlowAccentTest extends TestCase
{
    private function css(): string
    {
        return File::get(resource_path('css/app.css'));
    }

    public function test_the_light_accent_matches_the_ink_overrides_specificity(): void
    {
        $css = $this->css();

        $this->assertStringContainsString(
            'html:not(.dark) body.storefront .hero-glow-accent { color: #0044ff; }',
            $css,
            'the light accent must wear the same selector shape as the override it competes with'
        );
    }

    /** It must also come after that override, or equal specificity still loses. */
    public function test_the_accent_is_declared_after_the_ink_override(): void
    {
        $css = $this->css();

        $override = strpos($css, 'html:not(.dark) body.storefront .text-zinc-900');
        $accent = strpos($css, 'html:not(.dark) body.storefront .hero-glow-accent');

        $this->assertNotFalse($override);
        $this->assertNotFalse($accent);
        $this->assertGreaterThan($override, $accent, 'equal specificity is decided by source order');
    }

    /** Dark keeps its brighter blue - the deep one sinks into the navy hero. */
    public function test_dark_keeps_its_own_lifted_swatch(): void
    {
        $this->assertStringContainsString('.dark .hero-glow-accent { color: #5b8dff; }', $this->css());
    }

    /**
     * The glow is a second copy of the heading, masked to a circle that follows
     * the cursor. Both copies must read the same keys, or the glow spells out
     * different words than the heading it sits on.
     */
    public function test_the_glow_overlay_mirrors_the_heading(): void
    {
        $hero = File::get(resource_path('views/components/home/hero.blade.php'));

        $this->assertStringContainsString('hero-glow-accent', $hero);
        $this->assertSame(2, substr_count($hero, "__('home.hero_heading_line_one')"));
    }
}
