<?php

namespace Tests\Feature\Shop;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class CategoryCardsTest extends TestCase
{
    use RefreshDatabase;

    private function css(): string
    {
        return File::get(resource_path('css/app.css'));
    }

    public function test_the_wash_sits_behind_the_cards_not_over_them(): void
    {
        // Both layers are negative-z inside their own stacking context. Without
        // `isolate` on the parent they would escape it and paint over the copy.
        $blade = File::get(resource_path('views/components/home/category-cards.blade.php'));

        $this->assertStringContainsString('class="relative isolate z-10', $blade);
        $this->assertStringContainsString('class="cat-cards-wash"', $blade);
        $this->assertStringContainsString('group relative isolate flex h-full', $blade);

        $css = $this->css();
        $this->assertMatchesRegularExpression('/\.cat-cards-wash \{[^}]*z-index: -1;/s', $css);
        $this->assertMatchesRegularExpression('/\.cat-card-glow \{[^}]*z-index: -1;/s', $css);
    }

    public function test_the_wash_is_light_mode_only(): void
    {
        // The dark palette has its own ground; a cyan wash over it reads as a
        // stain rather than a glow.
        $this->assertStringContainsString('.dark .cat-cards-wash { display: none; }', $this->css());
    }

    public function test_the_wash_fades_out_before_the_edges(): void
    {
        // Strongest through the middle and gone by the sides, so the end cards
        // still sit on the plain page instead of on a hard band.
        preg_match('/\.cat-cards-wash \{(.*?)\}/s', $this->css(), $m);

        $this->assertNotEmpty($m);
        $this->assertStringContainsString('#bfebff 0%', $m[1]);
        $this->assertStringContainsString('rgba(191, 235, 255, 0) 78%', $m[1]);
    }

    public function test_every_category_keeps_its_content(): void
    {
        $html = $this->withoutVite()->get('/')->assertOk()->getContent();

        // The cards take the nav's own labels, so the tile and the category
        // rail name a category the same way - "Bills", not "Bill Payments",
        // since 2026-09-20.
        foreach (['gift_cards', 'esims', 'mobile_refill', 'bill_payments'] as $key) {
            $this->assertStringContainsString(e(__("navigation.{$key}")), $html);
        }

        $this->assertStringContainsString('Bills', $html);

        // Four cards, four blooms, one wash. Counted inside the card list
        // only: the payment panel further down the page borrows the same bloom
        // layer, so a page-wide count would drift every time that changes.
        //
        // The slice used to open at the list's own class string, which no
        // longer exists: below sm the row became a snapping carousel, so the
        // class list now starts with the carousel utilities and every grid one
        // moved behind an sm: prefix. Anchoring on how the row happens to be
        // laid out this month is what broke it, so the slice starts at the
        // section's aria-label instead - what the row IS, which survives the
        // next layout change. The anchor is asserted before it is used: a
        // strpos miss returns false, which substr reads as 0, and the count
        // would then silently run over the whole document.
        $this->assertStringContainsString('aria-label="Browse categories"', $html);

        $list = substr($html, (int) strpos($html, 'aria-label="Browse categories"'));
        $list = substr($list, 0, (int) strpos($list, '</ul>'));

        $this->assertSame(4, substr_count($list, 'cat-card-glow'));
        $this->assertSame(1, substr_count($html, 'cat-cards-wash'));
    }

    /**
     * Airtime and data bundles are one thing to the customer, so the two tiles
     * became one. The separate Data Bundles tile is gone, and with it the
     * pre-filtered deep link it was the only caller of.
     */
    public function test_airtime_and_data_share_one_tile(): void
    {
        $blade = File::get(resource_path('views/components/home/category-cards.blade.php'));

        $this->assertStringContainsString("'label' => __('navigation.mobile_refill')", $blade);
        // Comments stripped: the header explains the merge and names the tile
        // it removed, and that prose must not be able to fail this.
        $markup = preg_replace('/\{\{--.*?--\}\}/s', '', $blade);
        $this->assertStringNotContainsString('Data Bundles', $markup);
        $this->assertStringNotContainsString('data_bundles', $markup);
        $this->assertStringNotContainsString("'subcategory' => 'bundles'", $blade);

        // The merged tile opens the full top-ups listing, which carries both.
        $this->assertStringContainsString("\$topupOn ? route('shop.topups') : null", $blade);

        $html = $this->withoutVite()->get('/')->assertOk()->getContent();
        $this->assertStringNotContainsString('Data Bundles', $html);
    }

    /** Four tiles divide evenly at both breakpoints, so no row keeps an orphan. */
    public function test_the_grid_divides_evenly_for_four_tiles(): void
    {
        $blade = File::get(resource_path('views/components/home/category-cards.blade.php'));

        $this->assertStringContainsString('sm:grid-cols-2', $blade);
        $this->assertStringContainsString('lg:grid-cols-4', $blade);

        // The old 5-up track would strand the fourth tile on its own row.
        $this->assertStringNotContainsString('lg:grid-cols-5', $blade);
        $this->assertStringNotContainsString('sm:grid-cols-3', $blade);
    }

    public function test_supporting_line_is_pinned_to_the_card_foot(): void
    {
        // mt-auto is what gives the reference its rhythm - mark and name at the
        // top, gap, supporting line at the bottom - and what keeps tiles of
        // different copy lengths finishing level.
        $blade = File::get(resource_path('views/components/home/category-cards.blade.php'));

        $this->assertStringContainsString('card-text mt-auto block pt-6', $blade);

        // No clamp anywhere. Even at four across the text column is only ~270px
        // and these descriptions still run several lines, so a two-line clamp
        // would hide most of every one of them. The cards are grid items with
        // h-full, so they take the tallest height in the row and finish level
        // without needing one.
        $this->assertStringNotContainsString('line-clamp', $blade);
        $this->assertStringContainsString('h-full', $blade);
    }

    public function test_the_blue_stays_on_the_edge_and_out_of_the_card(): void
    {
        // The card face keeps its own fill; the blue only ever arrives off the
        // card's edge, never as a flat tint over the whole card. The light-mode
        // glow previously carried a #f4f8ff tint down from the card's top,
        // which is exactly the fill this forbids.
        preg_match('/
\.cat-card-glow \{(.*?)
\}/s', $this->css(), $m);

        $this->assertNotEmpty($m, 'Light-mode glow rule missing.');
        // Comments stripped: the note explaining the change names the colour it
        // forbids, and prose must not be able to fail this.
        $decls = preg_replace('#/\*.*?\*/#s', '', $m[1]);

        $this->assertStringContainsString('rgba(255, 255, 255, 0.9)', $decls);
        $this->assertStringNotContainsString('#f4f8ff', $decls);

        // It travels well into the face now - but still as an inset shadow off
        // the edge, not a fill. 26px was enough on the old white card; against
        // a face this close to the page it washed out entirely.
        $this->assertStringContainsString('box-shadow: inset 0 0 70px 4px rgba(191, 235, 255', $decls);

        // This used to end by pinning the card's #dee3e1 hairline, on the
        // grounds that the blue arrived off THAT edge rather than out of the
        // face. The hairline is gone by design - the redesign took the borders
        // off the tiles so they read as surfaces rather than as boxes - so what
        // is left to hold is the other half of the same rule: the face is a
        // flat opaque fill of its own, and nothing blue rides on the card
        // element itself. The inset shadow asserted above is the only blue the
        // tile gets, and it is a wash off the edge, not a fill.
        $blade = File::get(resource_path('views/components/home/category-cards.blade.php'));
        $this->assertStringContainsString('rounded-[20px] bg-[#ffffff]', $blade);
        $this->assertStringNotContainsString('ring-[#bfebff]', $blade);
        $this->assertStringNotContainsString('bg-[#bfebff]', $blade);
    }

    public function test_the_wash_top_fades_instead_of_drawing_a_line(): void
    {
        // Against the hero above it, a hard boundary reads as a divider rule. A
        // radial cannot promise a soft top on its own - its edge lands wherever
        // the ellipse does - so the top is masked out explicitly.
        preg_match('/\.cat-cards-wash \{(.*?)
\}/s', $this->css(), $m);

        $this->assertNotEmpty($m);
        $this->assertStringContainsString('mask-image: linear-gradient(to bottom, transparent 0%', $m[1]);
        $this->assertStringContainsString('-webkit-mask-image:', $m[1]);

        // And the BOTTOM too - the wash must not run into the panel below it.
        $this->assertStringContainsString('transparent 100%)', $m[1]);
        $this->assertMatchesRegularExpression('/inset: -6% -6% -\d%;/', $m[1]);
    }

    public function test_card_text_matches_the_supplied_spec(): void
    {
        // The face is shared: the type lives on a colourless .card-text-face so
        // other surfaces can borrow it, and .card-text adds the card's ink.
        preg_match('/\.card-text-face,\n\.card-text \{(.*?)
\}/s', $this->css(), $m);

        $this->assertNotEmpty($m, '.card-text face rule missing.');

        foreach ([
            'font-weight: 399;',
            'font-size: 16px;',
            'line-height: 24px;',
            'font-style: normal;',
        ] as $decl) {
            $this->assertStringContainsString($decl, $m[1]);
        }

        // The supplied spec asked for Roobert 500 and this pinned exactly that.
        // Both halves moved. Roobert is a licensed face that was never brought
        // into the repo - no woff2, no @font-face - so leading the stack with it
        // resolved to a platform fallback on every machine and the spec was
        // decorative. DM Sans is the site face now, imported at the top of
        // app.css as a variable font, which is the only reason the weight can be
        // 399: that is the off-axis cut the design calls for, and no static cut
        // can render it. Asserting the exact weight rather than "some weight" is
        // the point - a build that silently drops back to a static face lands on
        // 400 and this notices.
        $this->assertStringContainsString("font-family: 'DM Sans', sans-serif;", $m[1]);
        $this->assertStringNotContainsString('Roobert', $m[1]);

        // "bitrefill" is the source site's own icon font. We do not have it, and
        // a dead family in the middle of a stack only slows matching.
        $this->assertStringNotContainsString('bitrefill', $m[1]);

        // The ink is the card's alone - it must not ride along with the face.
        $this->assertStringNotContainsString('color:', $m[1]);
        $this->assertStringContainsString('.card-text { color: #001A18; }', $this->css());
    }

    /**
     * The hero subtitle borrows the card face. It must take the type WITHOUT
     * the colour: the hero keeps light-theme ink in both themes, matching the
     * headline directly above it, so .card-text's dark-mode white would break it.
     */
    public function test_the_hero_subtitle_borrows_the_card_face_but_not_its_ink(): void
    {
        $hero = File::get(resource_path('views/components/home/hero.blade.php'));

        $this->assertStringContainsString(
            'class="card-text-face mx-auto mt-5 max-w-2xl text-zinc-900"',
            $hero
        );

        // The old 18px sizing has to go, or it just sits there contradicting the face.
        $this->assertStringNotContainsString('max-w-2xl text-lg leading-relaxed', $hero);
    }

    public function test_card_text_stays_legible_on_the_dark_surface(): void
    {
        // #001A18 is tuned for a white card and would vanish on the dark ground.
        $this->assertStringContainsString(
            '.dark .card-text { color: rgba(255, 255, 255, 0.72); }',
            $this->css()
        );
    }

    /**
     * Each card names its own destination, and the CTA is a span, never a
     * nested <a>: the card itself is the link, and an anchor inside an anchor
     * is invalid markup that browsers repair by splitting the outer link.
     *
     * All four used to read "Check it out", which is one sentence repeated four
     * times and says nothing about where any of them goes. The labels are
     * asserted distinct rather than just present, because a single shared
     * string is exactly the thing this replaced.
     */
    public function test_every_card_names_its_own_destination(): void
    {
        $html = $this->withoutVite()->get('/')->assertOk()->getContent();

        // Scoped to the row, not the page. The hero's own button also reads
        // "Shop Gift Cards", so a page-wide count of that label is 2 and says
        // nothing about whether each CARD carries its own.
        $this->assertStringContainsString('aria-label="Browse categories"', $html);
        $row = substr($html, (int) strpos($html, 'aria-label="Browse categories"'));
        $row = substr($row, 0, (int) strpos($row, '</ul>'));

        $ctas = ['Shop Gift Cards', 'Explore eSIMs', 'Buy Airtime &amp; Data', 'Pay Bills'];

        foreach ($ctas as $cta) {
            $this->assertSame(1, substr_count($row, $cta), $cta.' should appear once, on its own card.');
        }

        $this->assertStringNotContainsString('Check it out', $html);

        $blade = File::get(resource_path('views/components/home/category-cards.blade.php'));
        $this->assertStringContainsString('aria-hidden="true"', $blade);
        $this->assertStringContainsString("{{ \$card['cta'] }}", $blade);
        $this->assertStringNotContainsString("__('products.check_it_out')", $blade);
    }

    public function test_the_cta_reveals_on_hover_without_resizing_the_card(): void
    {
        $blade = File::get(resource_path('views/components/home/category-cards.blade.php'));

        // Opacity + transform only. Anything that removes it from flow (hidden,
        // max-h-0) would make the card grow under the cursor.
        $this->assertStringContainsString('sm:opacity-0', $blade);
        $this->assertStringContainsString('sm:group-hover:opacity-100', $blade);
        $this->assertStringContainsString('sm:translate-y-1', $blade);
        $this->assertStringContainsString('sm:group-hover:translate-y-0', $blade);

        $this->assertStringNotContainsString('group-hover:block', $blade);
        $this->assertStringNotContainsString('hidden group-hover', $blade);

        // group-hover needs the group marker on the card anchor.
        $this->assertStringContainsString('group relative isolate flex h-full', $blade);
    }

    /** Touch has no hover, so the CTA must not start hidden below sm. */
    public function test_the_cta_is_always_visible_on_touch_widths(): void
    {
        $blade = File::get(resource_path('views/components/home/category-cards.blade.php'));

        // Every hide/reveal utility on the CTA is sm-scoped. An unprefixed
        // opacity-0 would strand it invisible on phones forever.
        $this->assertMatchesRegularExpression('/aria-hidden="true"\s*\n\s*class="[^"]*"/', $blade);
        $this->assertStringNotContainsString(' opacity-0 ', $blade);
    }

    public function test_the_cta_wears_the_navy_to_blue_gradient(): void
    {
        $blade = File::get(resource_path('views/components/home/category-cards.blade.php'));

        $this->assertStringContainsString('bg-gradient-to-r from-[#0a1740] via-[#153a9e] to-[#2563eb]', $blade);

        // Project standard radius for buttons.
        $this->assertStringContainsString('rounded-[12px]', $blade);
    }

    /**
     * In dark the tiles are flat black plus their ring - nothing else touches
     * them, same call as the wash above.
     *
     * The dark rule used to swap only `background`, which left the light-mode
     * box-shadow painting. That shadow is a #bfebff inset authored for a white
     * face, and at the tiles' spread it filled a near-black card with a pale
     * halo instead of tracing its edge. Asserting `display: none` rather than
     * "no blue" is deliberate: it is the whole layer that must be off, so no
     * future declaration inside the rule can leak onto the card.
     */
    public function test_the_tiles_carry_no_bloom_in_dark(): void
    {
        $css = $this->css();

        $this->assertStringContainsString('.dark .cat-card-glow { display: none; }', $css);

        // The wash behind the row is already light-only; both have to stay that
        // way or the pair reappears together.
        $this->assertStringContainsString('.dark .cat-cards-wash { display: none; }', $css);

        // Light keeps its bloom - this is a dark-mode change, not a removal.
        preg_match('/\.cat-card-glow \{(.*?)
\}/s', $css, $m);
        $this->assertNotEmpty($m);
        $this->assertStringContainsString('box-shadow: inset 0 0 70px 4px', $m[1]);
    }

    /**
     * One outline colour across the storefront: #dee3e1. The category cards and
     * the payment panel used to wear a blue #bfebff ring and the hero chips a
     * mid-grey zinc-400, so three surfaces sitting within one screen of each
     * other had three different edges.
     *
     * Two of those three no longer draw an edge at all. The redesign took the
     * borders off the category tiles and off the panel below them, so they are
     * told apart from the page by their fill and their bloom instead. That is
     * not a licence for the old colours to creep back one surface at a time, so
     * this now asserts both halves: the hero chips, the surface that still
     * draws an edge, wear the shared colour, and the two that dropped theirs
     * carry no static hairline at all.
     *
     * The bloom stays blue deliberately - a neutral edge with a #bfebff wash off
     * it, rather than one colour doing both jobs.
     */
    public function test_the_storefront_outlines_share_one_colour(): void
    {
        $cards = File::get(resource_path('views/components/home/category-cards.blade.php'));
        $home = File::get(resource_path('views/welcome.blade.php'));
        $hero = File::get(resource_path('views/components/home/hero.blade.php'));

        $this->assertStringContainsString('ring-1 ring-[#dee3e1]', $hero, 'hero chips should wear the shared outline.');

        // `ring-1 ring-[` is the exact shape every one of these hairlines wore,
        // so it is what has to stay absent. It cannot catch the cards' focus
        // ring by accident: that one is focus-visible:ring-2, and it must keep
        // working - a keyboard user still needs to see where they are.
        foreach (['cards' => $cards, 'panel' => $home] as $what => $blade) {
            $this->assertStringNotContainsString('ring-1 ring-[', $blade, $what.' should carry no hairline.');
        }
        $this->assertStringContainsString('focus-visible:ring-2', $cards);

        // The colours the shared outline replaced must not linger on any of them.
        $markup = preg_replace('/\{\{--.*?--\}\}/s', '', $cards.$home.$hero);
        $this->assertStringNotContainsString('ring-[#bfebff]', $markup);
        $this->assertStringNotContainsString('ring-zinc-400', $markup);

        // The bloom is untouched - it is a wash, not an edge.
        $this->assertStringContainsString(
            'box-shadow: inset 0 0 70px 4px rgba(191, 235, 255, 0.85);',
            $this->css()
        );
    }

    /**
     * The card marks are 64px tall. w-auto is load-bearing: the icons are not
     * all square, so pinning a width alongside the height would squash the
     * wider ones rather than scale them.
     */
    public function test_the_card_marks_are_full_size(): void
    {
        $blade = File::get(resource_path('views/components/home/category-cards.blade.php'));

        $this->assertStringContainsString('no-dark-invert h-16 w-auto shrink-0 self-start object-contain', $blade);
        $this->assertStringNotContainsString('h-12 w-auto', $blade);

        // Four marks on the page, one per card, all at the new size.
        $html = $this->withoutVite()->get('/')->assertOk()->getContent();
        $this->assertSame(4, substr_count($html, 'no-dark-invert h-16 w-auto'));
    }

    /**
     * The hero subtitle borrows the card face and overrides the size to 18/28.
     * The cards must keep 16/24, so this is a scoped rule rather than a change
     * to the shared one - and it is CSS rather than a text-lg utility, because
     * .card-text-face is unlayered and beats Tailwind's utilities layer.
     */
    public function test_the_hero_subtitle_sizes_up_without_moving_the_card_face(): void
    {
        $css = $this->css();
        // Mobile first: 16/24 on a phone. 18px under a 34px headline on a
        // 375px screen runs to four lines and crowds the CTAs under it.
        $this->assertStringContainsString(
            '[data-anim="hero-subtitle"].card-text-face {'.'
'.'  font-size: 16px;'.'
'.'  line-height: 24px;',
            $css
        );

        // And the step up once there is room for it.
        $this->assertStringContainsString('font-size: 18px;', $css);
        $this->assertStringContainsString('@media (min-width: 640px) {', $css);

        // The shared face is untouched - the cards still read at 16/24.
        preg_match('/\.card-text-face,
\.card-text \{(.*?)
\}/s', $css, $m);
        $this->assertNotEmpty($m);
        $this->assertStringContainsString('font-size: 16px;', $m[1]);
        $this->assertStringContainsString('line-height: 24px;', $m[1]);

        // The element still carries the face, or the override has nothing to hook.
        $hero = File::get(resource_path('views/components/home/hero.blade.php'));
        $this->assertStringContainsString('data-anim="hero-subtitle" class="card-text-face', $hero);
    }
}
