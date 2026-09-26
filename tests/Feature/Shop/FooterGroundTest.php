<?php

namespace Tests\Feature\Shop;

use Tests\TestCase;

/**
 * The storefront footer's surface, laid out as an inset card: #dee3e1 in light,
 * the grainy-black --ground slab plus the .tex-grain layer in dark. It used to
 * be that black in BOTH themes. The old charcoal band (#2f3540 in light,
 * #070f1c in dark) is gone.
 *
 * Source-reading only - no RefreshDatabase here, so nothing in this class may
 * hit a route. Rendered coverage lives in FooterBrandColumnTest.
 */
class FooterGroundTest extends TestCase
{
    private function footerBlade(): string
    {
        return file_get_contents(resource_path('views/components/footer.blade.php'));
    }

    private function css(): string
    {
        return file_get_contents(resource_path('css/app.css'));
    }

    public function test_footer_wears_the_ground_and_grain_classes_not_the_old_charcoal(): void
    {
        $blade = $this->footerBlade();

        preg_match('/<footer class="([^"]*)">/', $blade, $footerClasses);
        $this->assertNotEmpty($footerClasses, 'The <footer> element is missing.');
        $this->assertStringContainsString('footer-ground', $footerClasses[1]);
        $this->assertStringContainsString('tex-grain', $footerClasses[1]);

        // The charcoal band and its per-theme background classes are retired.
        $this->assertStringNotContainsString('footer-charcoal', $blade);
        $this->assertStringNotContainsString('#2f3540', $blade);
        $this->assertStringNotContainsString('dark:bg-[#070f1c]', $blade);

        // The slab looks the same in both themes, so it carries no dark:
        // background variant at all - nothing swaps it for the page tone.
        $this->assertStringNotContainsString('dark:bg-', $footerClasses[1]);
    }

    public function test_footer_is_an_inset_card_rounded_on_every_corner(): void
    {
        $blade = $this->footerBlade();

        // The layout still renders it bare; the inset margin lives in the
        // component. The gutter wears the page's own surface classes, and must
        // be a page-level element so app.css gives it the page tone in dark
        // rather than the card grey a bare div would get.
        $layout = file_get_contents(resource_path('views/components/layouts/app/header.blade.php'));
        $this->assertStringContainsString('<x-footer />', $layout);
        // The band's padding is what insets the card, so it is also what sets
        // how wide the footer reads. It was thinned to p-2/p-3 when the footer
        // was asked to run almost the full width of a phone.
        $this->assertStringContainsString('<section class="rounded-t-[20px] bg-[#ffffff] p-2 sm:p-3 lg:px-4 lg:py-5">', $blade);
        // Comments stripped: the note explaining the opt-out names bg-white, and
        // prose must not be able to fail this.
        $markup = preg_replace('/\{\{--.*?--\}\}/s', '', $blade);
        $this->assertDoesNotMatchRegularExpression('/(?<![\w:\/-])bg-white(?![\w\/-])/', $markup, 'a bare bg-white is remapped to the card grey in dark');

        // 15px on every corner, and no dark: variant - one value per theme.
        preg_match('/<footer class="([^"]*)">/', $blade, $m);
        $this->assertStringContainsString('rounded-[15px]', $m[1]);
        $this->assertStringNotContainsString('dark:rounded', $m[1]);

        // Nothing squares the corners back off anywhere in the stylesheet.
        $this->assertStringNotContainsString('footer.footer-ground { border-radius', $this->css());
    }

    public function test_ground_and_ash_tokens_are_defined(): void
    {
        $css = $this->css();

        $this->assertStringContainsString('--ground: #060607;', $css);
        $this->assertStringContainsString('--ash: #232329;', $css);

        // Two surface levels only: the footer sits on the ground, its tiles on ash.
        $this->assertStringContainsString('footer.footer-ground {', $css);
        $this->assertStringContainsString('background: var(--ground);', $css);
        $this->assertStringContainsString('footer.footer-ground .bg-zinc-100 { background-color: var(--ash); }', $css);
        $this->assertStringContainsString('footer.footer-ground .bg-zinc-900 { background-color: var(--ash); }', $css);
    }

    public function test_grain_layer_matches_the_spec(): void
    {
        $css = $this->css();

        // 220px tile, 0.92 frequency, four octaves, 0.14 in the rect, 0.5 on the layer.
        $this->assertStringContainsString("baseFrequency='0.92'", $css);
        $this->assertStringContainsString("numOctaves='4'", $css);
        $this->assertStringContainsString("opacity='0.14'", $css);
        $this->assertStringContainsString('background-size: 220px 220px;', $css);

        // Painted normally: overlay against a near-black ground vanishes. Read
        // the declaration block itself, so the prose warning above it (which
        // names the property precisely to rule it out) cannot satisfy this.
        preg_match('/\.tex-grain::after\s*\{(.*?)\}/s', $css, $grainBlock);
        $this->assertNotEmpty($grainBlock, 'The .tex-grain::after rule is missing.');
        $this->assertStringNotContainsString('mix-blend-mode', $grainBlock[1]);
        $this->assertStringContainsString('pointer-events: none;', $grainBlock[1]);

        // The grain layer anchors to the footer, so the footer must be positioned.
        preg_match('/footer\.footer-ground\s*\{(.*?)\}/s', $css, $footerBlock);
        $this->assertNotEmpty($footerBlock, 'The footer.footer-ground rule is missing.');
        $this->assertStringContainsString('position: relative;', $footerBlock[1]);
    }

    /**
     * The slab is per-theme now: #dee3e1 in light, the grainy --ground black in
     * dark. It used to be that black in BOTH, which is what the ink remaps
     * below existed for.
     */
    public function test_the_slab_is_light_in_light_and_ground_in_dark(): void
    {
        $css = $this->css();

        preg_match('/footer\.footer-ground\s*\{(.*?)\}/s', $css, $block);
        $this->assertNotEmpty($block, 'The footer.footer-ground rule is missing.');
        $this->assertStringContainsString('background: #dee3e1;', $block[1]);

        // It still carries no edge of its own - the white gutter frames it.
        $this->assertStringNotContainsString('box-shadow', $block[1]);

        $this->assertStringContainsString('.dark footer.footer-ground {', $css);
        $this->assertStringContainsString('background: var(--ground);', $css);
    }

    /**
     * Light needs NO ink remaps: the markup is authored in light-mode utilities
     * and #dee3e1 is a light surface, so they are already correct on it. Every
     * remap has to be dark-scoped, or near-white text lands on a light card.
     */
    public function test_the_ink_remaps_are_dark_only(): void
    {
        $css = $this->css();

        $this->assertStringContainsString('.dark footer.footer-ground .text-zinc-900 { color: #fafafa; }', $css);
        $this->assertStringContainsString('.dark footer.footer-ground .text-zinc-600 { color: #b6bcc6; }', $css);

        // None of them may be left unscoped. Checked line-by-line so a single
        // stray remap cannot hide behind the dark-scoped ones.
        foreach (explode('
', $css) as $line) {
            if (str_starts_with(trim($line), 'footer.footer-ground .')) {
                $this->fail('Unscoped footer ink remap would apply in light: '.trim($line));
            }
        }
    }

    /**
     * The grain is dark noise at 0.5 opacity, built for a near-black ground. On
     * #dee3e1 it is grey static, so light drops it - without touching the
     * shared .tex-grain rule that other surfaces still use.
     */
    public function test_light_drops_the_grain_and_dark_keeps_it(): void
    {
        $css = $this->css();

        $this->assertStringContainsString(
            'html:not(.dark) footer.footer-ground.tex-grain::after { display: none; }',
            $css
        );
        $this->assertStringContainsString('.tex-grain::after', $css);
    }

    /**
     * The footer deliberately does NOT share the nav's column any more. It runs
     * to the window, inset only by the white band's thin padding, so it reads
     * as a floor under the page rather than one more column in it.
     *
     * The nav column is asserted here anyway, because the FAQ slab above the
     * footer is aligned to the footer rather than to the nav - and if the nav's
     * width ever silently became the footer's again, the breakout would be
     * invisible rather than broken.
     */
    public function test_the_footer_shares_the_page_column_with_the_nav(): void
    {
        $nav = file_get_contents(resource_path('views/components/nav/main-nav.blade.php'));
        $footer = file_get_contents(resource_path('views/components/footer.blade.php'));

        // The page column. Both nav strips share it, and so does the slab now,
        // so the footer's edges line up with the nav's instead of running past.
        $this->assertStringContainsString('max-w-[1550px] mx-auto px-4 sm:px-6 lg:px-8', $nav);
        $this->assertStringContainsString(
            'footer-ground tex-grain mx-auto w-full max-w-[1550px] rounded-[15px]',
            $footer
        );
        $this->assertStringNotContainsString('max-w-[1420px]', $footer);

        // The band's padding is the only inset, and it is thin on a phone -
        // the footer was asked to read as almost full width there.
        $this->assertStringContainsString(
            '<section class="rounded-t-[20px] bg-[#ffffff] p-2 sm:p-3 lg:px-4 lg:py-5">',
            $footer
        );
    }

    /**
     * The FAQ sits directly above the footer, so the two slabs must share an
     * edge. It gets there the same way: .full-bleed out of the page column,
     * then the footer band's own horizontal padding.
     */
    public function test_the_faq_slab_lines_up_with_the_footer(): void
    {
        $faq = file_get_contents(resource_path('views/components/home/faq.blade.php'));

        // The top margin is a prop now, so the gift-cards page can run its FAQ
        // straight on from the row above it without changing the spacing here.
        // full-bleed and the footer band's padding - what this test is actually
        // about - are unchanged.
        $this->assertStringContainsString('class="full-bleed {{ $topMargin }} px-2 sm:px-3 lg:px-4"', $faq);
        $this->assertStringContainsString("'topMargin' => 'mt-12 lg:mt-16'", $faq);

        // .full-bleed measures the page rather than trusting 100vw, which
        // includes the scrollbar and so overhangs by its width.
        $css = file_get_contents(resource_path('css/app.css'));
        $this->assertStringContainsString('width: var(--page-w, 100vw)', $css);
    }

    /**
     * The white band the slab sits in carries a 20px top radius, so the footer
     * region lifts off the page with the same corner the category cards use.
     *
     * Top only: the bottom runs into the viewport edge, where a radius would
     * show the page behind it as two notches rather than reading as a corner.
     */
    public function test_the_white_band_is_rounded_at_the_top_only(): void
    {
        $footer = file_get_contents(resource_path('views/components/footer.blade.php'));

        $this->assertStringContainsString(
            '<section class="rounded-t-[20px] bg-[#ffffff] p-2 sm:p-3 lg:px-4 lg:py-5">',
            $footer
        );
        $this->assertStringNotContainsString('rounded-b-[20px]', $footer);
    }
}
