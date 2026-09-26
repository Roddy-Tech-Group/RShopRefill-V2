<?php

namespace Tests\Feature\Shop;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Dark mode is a single grainy-black palette. Soft dark - the lighter variant
 * that lived on `.dark:not(.pure-dark)` - is removed, along with its toggle,
 * its stored preference and its cookie.
 */
class DarkThemeTest extends TestCase
{
    use RefreshDatabase;

    private function css(): string
    {
        return file_get_contents(resource_path('css/app.css'));
    }

    public function test_no_soft_dark_rules_survive(): void
    {
        $css = $this->css();

        // Strip comments first: the note explaining the removal names the old
        // selector, and prose must not be able to satisfy or fail this.
        $rules = preg_replace('#/\*.*?\*/#s', '', $css);

        $this->assertStringNotContainsString(':not(.pure-dark)', $rules);
    }

    public function test_dark_surfaces_use_the_ground_and_ash_tokens(): void
    {
        $css = $this->css();

        $this->assertStringContainsString('--ground: #060607;', $css);
        $this->assertStringContainsString('--ash: #232329;', $css);

        // The old ramp is gone from the stylesheet entirely: page surfaces were
        // #0d0d0d and cards #2a2a2a.
        $this->assertStringNotContainsString('#0d0d0d', $css);
        $this->assertStringNotContainsString('#2a2a2a', $css);
    }

    public function test_no_page_wide_grain_layer(): void
    {
        // A fixed, page-wide grain is a positioned element, so it paints ABOVE
        // every non-positioned block - a noise sheet over all the content, not
        // behind it. The footer's own .tex-grain stays; it is scoped to a
        // surface with nothing competing beneath it.
        // Comments stripped: the note explaining the removal names the old
        // selector, and prose must not be able to fail this.
        $rules = preg_replace('#/\*.*?\*/#s', '', $this->css());

        $this->assertStringNotContainsString('body::after', $rules);
        $this->assertStringContainsString('.tex-grain::after', $rules);
    }

    public function test_theme_engine_has_one_dark_palette_and_clears_the_old_key(): void
    {
        $engine = file_get_contents(resource_path('views/partials/theme-engine.blade.php'));

        // .pure-dark rides along with .dark rather than being chosen.
        $this->assertStringContainsString("root.classList.toggle('pure-dark', isDark);", $engine);

        // The retired preference is cleared from both storage and the cookie.
        $this->assertStringContainsString("localStorage.removeItem('theme.pure_dark')", $engine);
        $this->assertStringContainsString("localStorage.removeItem('admin.theme.pure_dark')", $engine);
        $this->assertStringContainsString("document.cookie = 'pure_dark=; Max-Age=0", $engine);

        // Nothing writes the preference any more.
        $this->assertStringNotContainsString('pureDarkKey', $engine);
        $this->assertStringNotContainsString('writePureDarkCookie', $engine);
    }

    public function test_soft_dark_controls_are_gone_from_every_surface(): void
    {
        foreach ([
            'components/theme-toggle.blade.php',
            'livewire/settings/appearance.blade.php',
            'livewire/dashboard/overview.blade.php',
            'partials/head.blade.php',
        ] as $view) {
            // Comments stripped first: the notes explaining the removal name
            // the retired feature, and prose must not fail this.
            $blade = file_get_contents(resource_path('views/'.$view));
            $blade = preg_replace(['/\{\{--.*?--\}\}/s', '#^\s*//.*$#m'], '', $blade);

            $this->assertStringNotContainsString('softDark', $blade, $view.' still wires the soft-dark toggle.');
            $this->assertStringNotContainsString('Soft dark', $blade, $view.' still offers soft dark.');
            $this->assertStringNotContainsString('setPureDark', $blade, $view.' still writes the retired preference.');
        }
    }

    public function test_first_paint_frame_matches_the_new_ground(): void
    {
        $head = file_get_contents(resource_path('views/partials/head.blade.php'));

        // The pre-CSS frame must be the ground, or dark flashes a lighter grey
        // before the stylesheet lands.
        $this->assertStringContainsString('html.dark { background-color: #060607; }', $head);

        // The second cookie that used to pick a ramp is gone.
        $this->assertStringNotContainsString('puredark', $head);
        $this->assertStringNotContainsString('themeIsPureDarkInitial', $head);
    }

    public function test_page_surfaces_are_elements_that_qualify_for_the_page_tone(): void
    {
        // app.css gives the page tone only to the elements it names. On any
        // other element the same classes fall through to the CARD tone, which
        // is why home once read a shade lighter than every other page.
        //
        // Home no longer rides that mapping at all: it pins its own literal
        // #f4f6f5 and lets the cards take #ffffff, so the element-type trap
        // cannot bite it. The guard still has to hold for anything that goes
        // back to the shared class, though - hence the second assertion.
        $home = file_get_contents(resource_path('views/welcome.blade.php'));

        $this->assertStringContainsString(
            '<section class="relative z-10 bg-[#f4f6f5] dark:bg-[#0c1a36]">',
            $home
        );

        // The outer wrapper too. It is a <section> for exactly this reason: on
        // a div the same dark:bg-[#0c1a36] falls to the CARD tone (#1a1a1e) and
        // the hero band reads a shade lighter than the page under it.
        $this->assertStringContainsString(
            '<section class="relative bg-[#f4f6f5] dark:bg-[#0c1a36]">',
            $home
        );
        $this->assertStringNotContainsString(
            '<div class="relative bg-[#f4f6f5] dark:bg-[#0c1a36]">',
            $home
        );

        // Whatever home uses, it must never be the page-tone class on a div.
        $this->assertStringNotContainsString(
            '<div class="relative z-10 bg-[#eff6ff] dark:bg-[#0c1a36]">',
            $home
        );
    }

    public function test_brand_logo_plates_are_never_darkened(): void
    {
        // bg-[#ffffff] used to be a blanket opt-out of the dark flip: nothing in
        // the stylesheet named it, so a plate authored with the literal hex was
        // guaranteed to stay white and brand artwork with dark linework always
        // had something to sit on. That guarantee could not survive the light
        // redesign, which made the same class the DASHBOARD card marker - white
        // cards on a #f4f6f5 page. Dark mode has to repaint a card, so it now
        // repaints this class too, and asserting the class is untouched would be
        // asserting that dashboard cards stay white in dark mode.
        //
        // What the plates actually need is narrower, and it is both halves of
        // this test. First, the flip is scoped to body.dashboard, so a storefront
        // plate is still never touched by it. Second, a plate that DOES sit on a
        // dashboard page opts back out with .keep-light, and that hatch really
        // repaints white - it is declared after the flip and at higher
        // specificity, so it wins the cascade rather than merely existing.
        //
        // The views that carry brand artwork are checked for the hatch here as
        // well. A CSS escape hatch nothing uses would pass the rules above while
        // every logo on the dashboard still sank into the card grey, which is the
        // regression this test exists to catch.
        $rules = preg_replace("#/\*.*?\*/#s", '', $this->css());

        preg_match_all('/([^{}]+)\{([^{}]*)\}/s', $rules, $m, PREG_SET_ORDER);

        $unscoped = [];
        $hatches = [];

        foreach ($m as [, $selector, $body]) {
            if (! str_contains($selector, 'bg-\[\#ffffff\]') || ! str_contains($selector, '.dark')) {
                continue;
            }

            if (! str_contains($selector, 'body.dashboard')) {
                $unscoped[] = trim($selector);
            }

            if (str_contains($selector, '.keep-light')) {
                $hatches[] = $body;
            }
        }

        $this->assertSame(
            [],
            $unscoped,
            'A dark rule repaints bg-[#ffffff] outside body.dashboard, so storefront logo plates go dark with it.'
        );

        $this->assertNotEmpty($hatches, 'Nothing lets a plate opt back out of the dashboard card flip.');

        foreach ($hatches as $body) {
            $this->assertStringContainsString('background-color: #ffffff;', $body);
        }

        // Every view that puts brand artwork (or a QR, which a scanner reads the
        // same way) on a literal-white plate names the hatch.
        foreach ([
            'components/shop/brand-tile.blade.php',
            'components/home/brand-card.blade.php',
            'shop/product.blade.php',
            'shop/topups.blade.php',
            'shop/bills.blade.php',
            'livewire/dashboard/fund-wallet.blade.php',
        ] as $view) {
            $this->assertStringContainsString(
                'keep-light',
                file_get_contents(resource_path('views/'.$view)),
                $view.' puts artwork on a plate that dark mode is free to repaint.'
            );
        }
    }

    public function test_esim_plans_carry_the_grain(): void
    {
        // Their rule sets background-image to beat the gradients and glass
        // tints below it, so the grain has to go through that same declaration
        // or these are the only cards left flat.
        preg_match("/\.dark \.esim-tile,\s*\.dark \.pure-card \{(.*?)\}/s", $this->css(), $m);
        $this->assertNotEmpty($m, 'esim-tile rule missing.');

        // Comments stripped: the note explaining the change quotes the old
        // declaration, and prose must not be able to fail this.
        $decls = preg_replace('#/\*.*?\*/#s', '', $m[1]);
        $this->assertStringContainsString('background-image: var(--grain) !important;', $decls);
        $this->assertStringNotContainsString('background-image: none', $decls);
    }

    public function test_no_dark_hover_paints_the_resting_card_colour(): void
    {
        // var(--ash) is what cards REST at. A :hover that paints var(--ash) on
        // a surface already sitting at var(--ash) leaves the row the exact
        // colour it was, so the hover reads as broken although the rule fired.
        // That is the bug the whole --ash-hover split exists to prevent, and it
        // survived on .admin-table long after the rest were converted. Hovers
        // lift to var(--ash-hover); nothing hovers to the resting colour.
        $rules = preg_replace('#/\*.*?\*/#s', '', $this->css());

        preg_match_all('/([^{}]+)\{([^{}]*)\}/s', $rules, $m, PREG_SET_ORDER);
        $offenders = [];
        foreach ($m as [$whole, $selector, $body]) {
            if (! str_contains($selector, ':hover') || ! str_contains($selector, '.dark')) {
                continue;
            }
            // var(--ash-hover) does not match: the closing paren is required.
            if (preg_match('/background-color:\s*var\(--ash\)/', $body)) {
                $offenders[] = trim($selector);
            }
        }

        $this->assertSame([], $offenders, 'Dark hover rules painting the resting card colour.');
    }

    public function test_no_dark_rule_paints_pure_black(): void
    {
        // The old Extra Dark ramp painted #000 and rode on `.dark.pure-dark`,
        // which now applies unconditionally - so it beat the retone and turned
        // pages pure black instead of the grainy ground.
        $css = $this->css();
        $rules = preg_replace('#/\*.*?\*/#s', '', $css);

        preg_match_all('/([^{}]+)\{([^{}]*)\}/s', $rules, $m, PREG_SET_ORDER);
        $offenders = [];
        foreach ($m as [$whole, $selector, $body]) {
            if (! str_contains($selector, '.dark')) {
                continue;
            }
            if (preg_match('/background(-color)?:\s*(#000000|rgb\(0 0 0)/', $body)) {
                $offenders[] = trim($selector);
            }
        }

        $this->assertSame([], $offenders, 'Dark rules still painting pure black.');
    }

    public function test_body_carries_the_ground_in_dark(): void
    {
        // Straight from the grainy-black spec: the ground goes on body, not
        // just on the individual surface remaps.
        preg_match("/html\.dark body \{(.*?)\}/s", $this->css(), $m);
        $this->assertNotEmpty($m, 'html.dark body rule is missing.');
        $this->assertStringContainsString('background-color: var(--ground);', $m[1]);
        $this->assertStringContainsString('color: #fff;', $m[1]);

        // Ground alone reads as flat pure-dark. The grain is what makes it the
        // grainy palette, and it is a background-image rather than a positioned
        // overlay so it can never sit on top of content.
        $this->assertStringContainsString('background-image: var(--grain);', $m[1]);

        $css = $this->css();
        $this->assertStringContainsString('--grain:', $css);
        $this->assertStringContainsString("opacity='0.07'", $css);

        // The opaque page surfaces sit above body, so they carry it too or
        // they mask it everywhere but the margins.
        $this->assertGreaterThanOrEqual(
            2,
            substr_count($css, 'background-image: var(--grain) !important'),
            'Page surfaces must carry the grain or they mask body.'
        );
    }

    public function test_storefront_still_renders(): void
    {
        $this->withoutVite()->get('/')->assertOk();
    }

    public function test_storefront_controls_keep_their_component_dark_hover(): void
    {
        $css = $this->css();
        $regionPill = file_get_contents(resource_path('views/components/nav/region-pill.blade.php'));
        $footer = file_get_contents(resource_path('views/components/footer.blade.php'));

        // A broad late override used to replace each control's dark:hover value
        // with --ash. The header already rests on --ash, so that made hover
        // visually identical to rest across the primary navigation.
        $this->assertStringNotContainsString(
            'html.dark body:not(.dashboard) .hover\\:bg-\\[\\#f4f6f5\\]:hover',
            $css
        );

        // Storefront controls that also have a light hover declare their own
        // visible dark tint instead of relying on a global repaint. The region
        // pill keeps the dashboard chip's faint white wash; a tint of its blue
        // edge was tried and reverted.
        $this->assertStringContainsString('dark:hover:bg-white/5', $regionPill);
        $this->assertStringNotContainsString('dark:hover:bg-edge/20', $regionPill);
        $this->assertStringContainsString('dark:hover:bg-white/10', $footer);
    }

    public function test_dark_interactive_remaps_are_distinct_from_resting_surfaces(): void
    {
        $css = $this->css();

        $this->assertStringContainsString('--ash: #232329;', $css);
        $this->assertStringContainsString('--ash-hover: #2d2d34;', $css);
        $this->assertStringContainsString(
            '.dark\\:hover\\:bg-\\[\\#26416b\\]:hover { background-color: var(--ash-hover);',
            $css
        );
        $this->assertStringContainsString(
            '.hover\\:bg-blue-50:hover   { background-color: var(--ash-hover);',
            $css
        );

        // The unlayered palette rules must explicitly hand control back to
        // text, border and ring hover variants after repainting their rest state.
        $this->assertStringContainsString('.dark\\:hover\\:text-blue-400:hover { color: var(--color-blue-400); }', $css);
        $this->assertStringContainsString('.hover\\:border-zinc-400:hover,', $css);
        $this->assertStringContainsString('.hover\\:ring-zinc-300:hover { --tw-ring-color: #4a4a4f; }', $css);
    }

    public function test_responsive_header_logo_turns_white_in_dark_mode(): void
    {
        $nav = file_get_contents(resource_path('views/components/nav/main-nav.blade.php'));
        $css = $this->css();

        // Desktop logo.webp is supplied by <source>; the <img src> remains the
        // mobile PWA icon, so a src selector alone cannot match the displayed
        // desktop wordmark.
        $this->assertStringContainsString('class="site-logo h-9 w-auto', $nav);
        $this->assertStringContainsString('.dark .site-logo,', $css);
        $this->assertStringContainsString('filter: brightness(0) invert(1) !important;', $css);
    }

    /**
     * In dark the sticky nav and home cards use the card tone above the page.
     *
     * The nav used to be transparent while it scrolled with the page. It is
     * sticky now, so transparency lets page content show through. The ash tone
     * gives the pinned bar an opaque surface and its controls hover to a lighter
     * white tint above it.
     *
     * The cards did move. They were transparent too, with a ring to stand them
     * off the page, and the redesign dropped every card border. A borderless card
     * that is also the page colour is not a card at all: the four category tiles
     * and the panel below them dissolved into one unbroken black field. They take
     * var(--ash) now, the single card tone the whole dark palette already uses,
     * which is what gives an edge back without drawing one. That they name the
     * token rather than a hex is the point - a retuned palette moves them with it.
     */
    public function test_dark_nav_and_cards_use_the_card_tone(): void
    {
        $cards = file_get_contents(resource_path('views/components/home/category-cards.blade.php'));
        $home = file_get_contents(resource_path('views/welcome.blade.php'));
        $css = $this->css();

        $this->assertStringContainsString('dark:bg-[var(--ash)]', $cards);
        $this->assertStringContainsString('dark:bg-[var(--ash)]', $home);

        // Still never black. --ash is the card tone; black would be the ground
        // again, which is the surface these two are meant to sit above.
        $this->assertStringNotContainsString('dark:bg-black', $cards);
        $this->assertStringNotContainsString('dark:bg-black', $home);

        preg_match('/\.dark \.nav-surface\s*\{(.*?)\}/s', $css, $navSurface);
        $this->assertNotEmpty($navSurface, 'The dark nav surface rule is missing.');
        $this->assertStringContainsString('background-color: var(--ash);', $navSurface[1]);
        $this->assertStringContainsString('background-image: var(--grain);', $navSurface[1]);

        // Light is untouched: the bar is deliberately white there, not the page
        // tone, so it reads as a bar rather than dissolving into the page.
        $this->assertStringContainsString('.nav-surface { background-color: #ffffff; }', $css);
    }
}
