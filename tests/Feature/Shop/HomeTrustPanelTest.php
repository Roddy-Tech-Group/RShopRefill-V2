<?php

namespace Tests\Feature\Shop;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HomeTrustPanelTest extends TestCase
{
    use RefreshDatabase;

    private function home(): string
    {
        return file_get_contents(resource_path('views/welcome.blade.php'));
    }

    public function test_the_panel_holds_the_trust_signals(): void
    {
        // The payment pyramid used to sit above these; it was removed and the
        // trust strip is what the panel carries now.
        $home = $this->home();

        $this->assertStringContainsString('class="trust-panel isolate mt-8', $home);
        $this->assertStringContainsString('<x-home.trust-strip />', $home);
        $this->assertStringNotContainsString('<x-home.payment-methods />', $home);
    }

    /**
     * The panel wears the category card's surface, not a navy island fill, so
     * the two blocks read as one family down the page.
     */
    public function test_the_panel_wears_the_category_card_surface(): void
    {
        $home = $this->home();
        $cards = file_get_contents(resource_path('views/components/home/category-cards.blade.php'));

        foreach (['bg-[#f4f6f5]', 'ring-1 ring-[#dee3e1]', 'dark:bg-transparent', 'dark:ring-zinc-700/60'] as $token) {
            $this->assertStringContainsString($token, $home, "Panel is missing {$token}.");
            $this->assertStringContainsString($token, $cards, "Card is missing {$token}.");
        }
    }

    /**
     * A normally-themed surface needs no scoped ink remaps: the markup inside
     * already pairs every light utility with a dark: variant. The old remaps
     * existed only because the navy fill was applied in LIGHT mode too, where
     * no dark: variant fires - keeping them would now put near-white ink on a
     * white card.
     */
    public function test_the_panel_no_longer_overrides_its_own_ink(): void
    {
        $css = file_get_contents(resource_path('css/app.css'));

        $this->assertStringNotContainsString('background: #13294d;', $css);
        $this->assertStringNotContainsString('.trust-panel .text-zinc-900', $css);
        $this->assertStringNotContainsString('.trust-panel .border-zinc-200', $css);
        $this->assertStringNotContainsString('.trust-panel img.brightness-0', $css);

        // What the contents rely on instead.
        $strip = file_get_contents(resource_path('views/components/home/trust-strip.blade.php'));

        $this->assertStringContainsString("'brightness-0 dark:invert'", $strip);
        $this->assertStringContainsString('text-zinc-900 dark:text-white', $strip);
    }

    /**
     * The card face is not flat: the wash bleeds in from the edge as an inset
     * bloom. The panel borrows that exact layer, so it fades the same way
     * rather than sitting there as a slab of white.
     */
    public function test_the_panel_borrows_the_card_bloom(): void
    {
        $home = $this->home();

        $this->assertStringContainsString('class="cat-card-glow"', $home);

        // z-index -1 needs a stacking context on the panel, or the layer
        // escapes behind the section background and is never seen.
        $this->assertStringContainsString('class="trust-panel isolate mt-8', $home);

        $css = file_get_contents(resource_path('css/app.css'));
        $this->assertMatchesRegularExpression('/\.cat-card-glow \{[^}]*z-index: -1;/s', $css);

        // Rendered: four card blooms plus the panel's.
        $html = $this->withoutVite()->get('/')->assertOk()->getContent();
        $this->assertSame(5, substr_count($html, 'cat-card-glow'));
    }

    /**
     * The panel is roughly four times a tile in both directions, so the shared
     * 26px edge bleed reads as a hairline on it. Only the spread is scoped up -
     * the face gradient stays shared, or the two become different effects
     * rather than one treatment at two sizes.
     */
    public function test_the_panel_bloom_is_scaled_to_the_panel(): void
    {
        $css = file_get_contents(resource_path('css/app.css'));

        $this->assertStringContainsString(
            '.trust-panel .cat-card-glow {'.'
'.'  box-shadow: inset 0 0 200px 10px rgba(191, 235, 255, 0.85);',
            $css
        );

        // Dark kills the bloom on the tiles outright, so the panel has to opt
        // back in - and on the accent: #bfebff at this spread is a haze on
        // near-black rather than light coming off an edge.
        $this->assertStringContainsString(
            '.dark .trust-panel .cat-card-glow {'.'
'.'  display: block;',
            $css
        );
        $this->assertStringContainsString(
            'box-shadow: inset 0 0 200px 10px rgba(122, 162, 255, 0.22);',
            $css
        );

        // The scoped rules must not touch `background` - that is what keeps the
        // panel and the cards the same effect.
        preg_match('/\.trust-panel \.cat-card-glow \{(.*?)\}/s', $css, $m);
        $this->assertNotEmpty($m);
        $this->assertStringNotContainsString('background', $m[1]);

        // The card keeps its own, smaller spread in light - the panel override
        // must not be what the tiles are reading - and none of it in dark.
        $this->assertStringContainsString('box-shadow: inset 0 0 70px 4px rgba(191, 235, 255, 0.85);', $css);
        $this->assertStringContainsString('.dark .cat-card-glow { display: none; }', $css);
    }

    /**
     * tex-grain paints dark noise at 0.5 opacity - built for a near-black
     * ground, grey static on a white card. The footer keeps it; this panel
     * must not, and the shared rule must survive for the footer's sake.
     */
    public function test_the_panel_drops_the_grain_but_the_footer_keeps_it(): void
    {
        $this->assertStringNotContainsString('trust-panel tex-grain', $this->home());

        $footer = file_get_contents(resource_path('views/components/footer.blade.php'));
        $this->assertStringContainsString('tex-grain', $footer);

        $css = file_get_contents(resource_path('css/app.css'));
        $this->assertStringContainsString('.tex-grain::after', $css);
    }

    /**
     * The payment pyramid is off the home page entirely. The component file is
     * left in place, unreferenced, rather than deleted - so this guards the
     * page, not the file.
     */
    public function test_home_no_longer_shows_the_payment_methods(): void
    {
        $html = $this->withoutVite()->get('/')->assertOk()->getContent();

        $this->assertStringNotContainsString('Payment methods we accept', $html);
        $this->assertStringNotContainsString('Payment Methods We Accept', $html);

        // The four trust tiles went earlier; min-h-[110px] was their marker, so
        // its absence is what proves those are still gone too.
        $this->assertStringNotContainsString('min-h-[110px]', $html);

        // The trust strip below it survives the removal.
        $this->assertStringContainsString('Trusted by Thousands', $html);
    }

    /**
     * Home runs its own light-mode surface. Everywhere else the page is
     * #f4f6f5 with #ffffff cards lifted off it; here it is the other way round -
     * a #ffffff page with #f4f6f5 cards sitting on it, ringed in #dee3e1.
     */
    public function test_home_runs_its_own_light_mode_surface(): void
    {
        $home = $this->home();
        $cards = file_get_contents(resource_path('views/components/home/category-cards.blade.php'));

        // Both home surfaces are white. The wrapper needs it as well as the
        // section: the hero sits above the section and would otherwise show
        // <main>'s #f4f6f5 as a band across the top of the page.
        $this->assertStringContainsString('<section class="relative bg-[#ffffff] dark:bg-[#0c1a36]">', $home);
        $this->assertStringContainsString('<section class="relative z-10 bg-[#ffffff] dark:bg-[#0c1a36]">', $home);

        // Literal hex, never bg-white: that is this codebase's idiom for opting
        // out of the dark-mode flip, and dark is pinned beside it. Comments
        // stripped - both files explain the idiom in prose.
        foreach ([$home, $cards] as $file) {
            $this->assertStringNotContainsString(
                'bg-white',
                preg_replace('/\{\{--.*?--\}\}/s', '', $file)
            );
        }

        // Surface, fill and border are three distinct values: a #ffffff page,
        // #f4f6f5 cards, a #dee3e1 ring. The bloom inside stays blue - a
        // neutral edge with a #bfebff wash off it.
        $this->assertStringContainsString('rounded-[20px] bg-[#f4f6f5] px-5 pb-6 pt-5', $cards);
        $this->assertStringContainsString('ring-1 ring-[#dee3e1]', $cards);

        $css = file_get_contents(resource_path('css/app.css'));
        $this->assertStringContainsString('box-shadow: inset 0 0 70px 4px rgba(191, 235, 255, 0.85);', $css);

        // The app-wide pair is untouched - this is home's alone.
        $this->assertStringContainsString('html:not(.dark) .bg-\[\#eff6ff\] { background-color: #f4f6f5; }', $css);
        $this->assertStringContainsString('html:not(.dark) .bg-\[\#eff6ff\].dash-shimmer { background-color: #ffffff; }', $css);
    }
}
