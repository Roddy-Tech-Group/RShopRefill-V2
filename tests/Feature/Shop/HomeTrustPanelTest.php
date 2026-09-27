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

    /**
     * What the panel carries, in the order it carries it.
     *
     * Two things have been through here. The payment pyramid went first. The
     * four-signal trust strip went with the rework that followed it: three of
     * its signals were unverifiable marketing lines sitting next to figures the
     * database can actually stand behind, so it was replaced by the live counts
     * rather than kept beside them.
     *
     * The gift-card category row went out in that same rework, and this is the
     * assertion that keeps it out. The box was given over to the counts, and
     * the category row is the thing the counts replaced. It reads like an
     * accident from the code alone, because the component is still in the repo
     * and still maintained while nothing mounts it, so a reader who only has
     * the diff concludes it was dropped by mistake and puts it back. That has
     * already happened once.
     *
     * The order is the argument the panel makes, which is why it is asserted
     * and not just the membership: the heading makes the claim, the counts back
     * it with real figures, and the assurance strip closes with the promises
     * and the payment rails - the rails being the one part of the old pyramid
     * worth keeping, now a single line rather than a block of its own.
     */
    public function test_the_panel_holds_the_trust_signals(): void
    {
        $home = $this->home();

        $this->assertStringContainsString('class="trust-panel isolate mt-8', $home);
        $this->assertStringContainsString('<x-home.customer-counts />', $home);
        $this->assertStringContainsString('<x-home.assurance-strip />', $home);

        $this->assertStringNotContainsString('<x-home.payment-methods />', $home);
        $this->assertStringNotContainsString('<x-home.trust-strip />', $home);
        $this->assertStringNotContainsString('<x-home.gift-card-categories />', $home);

        $counts = strpos($home, '<x-home.customer-counts />');
        $assurance = strpos($home, '<x-home.assurance-strip />');

        $this->assertLessThan($assurance, $counts);
    }

    /**
     * The panel wears the category card's surface, not a navy island fill, so
     * the two blocks read as one family down the page.
     *
     * Both are fill-only now. The #dee3e1 hairline they used to share is gone
     * from both, and the dark half is the shared --ash card tone rather than a
     * transparent face held together by a ring - with the ring gone, a
     * transparent block simply disappeared into the page in dark mode. What
     * gives either of them an edge is the bloom bleeding in from it, which is
     * the layer the two tests below cover.
     *
     * So the tokens come in two lists. The fills must match, and the ring pair
     * must be absent from BOTH: leaving it on one of them is exactly how the
     * panel and the tiles stop reading as one family, which is the only thing
     * this test exists to prevent.
     */
    public function test_the_panel_wears_the_category_card_surface(): void
    {
        $home = $this->home();
        $cards = file_get_contents(resource_path('views/components/home/category-cards.blade.php'));

        foreach (['bg-[#ffffff]', 'dark:bg-[var(--ash)]'] as $token) {
            $this->assertStringContainsString($token, $home, "Panel is missing {$token}.");
            $this->assertStringContainsString($token, $cards, "Card is missing {$token}.");
        }

        foreach (['ring-1 ring-[#dee3e1]', 'dark:ring-zinc-700/60', 'dark:bg-transparent'] as $token) {
            $this->assertStringNotContainsString($token, $home, "Panel still carries {$token}.");
            $this->assertStringNotContainsString($token, $cards, "Card still carries {$token}.");
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
     *
     * Dark now has no scoped rule at all, which is the change here. The panel
     * used to opt back into the bloom in dark mode with an accent wash across
     * its whole face; at four times a tile's size that stopped reading as light
     * coming off an edge and became a fill, so the panel came out navy while
     * the four tiles above it stayed near-black and the page looked like two
     * themes meeting. It now inherits the blanket kill the tiles get, which is
     * why both halves of the old dark override are asserted ABSENT.
     *
     * The stylesheet is read off disk, so its line endings are whatever the
     * checkout produced. They are normalised before the multi-line needle is
     * matched: this is an assertion about the shape of a CSS rule, and a Windows
     * working copy must not be able to fail it.
     */
    public function test_the_panel_bloom_is_scaled_to_the_panel(): void
    {
        $css = str_replace("\r\n", "\n", file_get_contents(resource_path('css/app.css')));

        $this->assertStringContainsString(
            ".trust-panel .cat-card-glow {\n".'  box-shadow: inset 0 0 200px 10px rgba(191, 235, 255, 0.85);',
            $css
        );

        // No dark override of any kind: the panel takes the same outright kill
        // the tiles take, rather than a second colour for the same layer.
        $this->assertStringNotContainsString('.dark .trust-panel .cat-card-glow', $css);
        $this->assertStringNotContainsString('rgba(122, 162, 255, 0.22)', $css);

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

        // The trust strip that replaced those tiles has since gone the same way,
        // and for the same reason the pyramid did: three of its four signals
        // were claims nothing could check ("Trusted by Thousands") sitting
        // beside a figure read live from the catalogue.
        $this->assertStringNotContainsString('Trusted by Thousands', $html);

        // What closes the panel instead. Asserting these is what keeps this test
        // honest: a home page that had simply stopped rendering the panel would
        // otherwise satisfy every absence above it.
        $this->assertStringContainsString(__('home.assurance_instant_title'), $html);
        $this->assertStringContainsString(__('home.assurance_pay_with'), $html);
    }

    /**
     * Home follows the storefront light-mode surface: a #f4f6f5 page with
     * #ffffff cards lifted off it.
     *
     * The #dee3e1 ring is no longer part of that story. Surface and fill are now
     * the only two values: the card is told apart from the page by its own tone
     * plus the bloom bleeding in from its edge, and a hairline drawn on top of
     * that bloom read as a second outline competing with it. So the ring is
     * asserted absent here, and the bloom - which is now doing the edge's whole
     * job - is asserted at the spread that makes it visible.
     */
    public function test_home_runs_its_own_light_mode_surface(): void
    {
        $home = $this->home();
        $cards = file_get_contents(resource_path('views/components/home/category-cards.blade.php'));

        // Both home surfaces carry the page tone. The wrapper needs it as well
        // as the content section so the hero and the content share one ground.
        $this->assertStringContainsString('<section class="relative bg-[#f4f6f5] dark:bg-[#0c1a36]">', $home);
        $this->assertStringContainsString('<section class="relative z-10 bg-[#f4f6f5] dark:bg-[#0c1a36]">', $home);

        // Literal hex, never bg-white: that is this codebase's idiom for opting
        // out of the dark-mode flip, and dark is pinned beside it. Comments
        // stripped - both files explain the idiom in prose.
        foreach ([$home, $cards] as $file) {
            $this->assertStringNotContainsString(
                'bg-white',
                preg_replace('/\{\{--.*?--\}\}/s', '', $file)
            );
        }

        // Surface and fill are two distinct values: a #f4f6f5 page with #ffffff
        // cards on it, and no third value for a border. The bloom stays blue on
        // a neutral card, which is the same pairing the hero chips use.
        $this->assertStringContainsString('rounded-[20px] bg-[#ffffff] px-5 pb-6 pt-5', $cards);
        $this->assertStringNotContainsString('ring-1 ring-[#dee3e1]', $cards);

        // 70px, not the 26px this started at: with the ring gone the wash has to
        // travel well into the face to give the card an edge at all, and at a
        // hairline's worth of spread it left the card indistinguishable from the
        // page behind it.
        $css = file_get_contents(resource_path('css/app.css'));
        $this->assertStringContainsString('box-shadow: inset 0 0 70px 4px rgba(191, 235, 255, 0.85);', $css);

        // The app-wide pair is untouched - this is home's alone.
        $this->assertStringContainsString('html:not(.dark) .bg-\[\#eff6ff\] { background-color: #f4f6f5; }', $css);
        $this->assertStringContainsString('html:not(.dark) .bg-\[\#eff6ff\].dash-shimmer { background-color: #ffffff; }', $css);
    }
}
