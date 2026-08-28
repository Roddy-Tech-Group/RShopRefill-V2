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
        $this->assertStringContainsString('--ash: #1a1a1e;', $css);

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
        // #ffffff and lets the cards take #e8ecea, so the element-type trap
        // cannot bite it. The guard still has to hold for anything that goes
        // back to the shared class, though - hence the second assertion.
        $home = file_get_contents(resource_path('views/welcome.blade.php'));

        $this->assertStringContainsString(
            '<section class="relative z-10 bg-[#ffffff] dark:bg-[#0c1a36]">',
            $home
        );

        // The outer wrapper too. It is a <section> for exactly this reason: on
        // a div the same dark:bg-[#0c1a36] falls to the CARD tone (#1a1a1e) and
        // the hero band reads a shade lighter than the page under it.
        $this->assertStringContainsString(
            '<section class="relative bg-[#ffffff] dark:bg-[#0c1a36]">',
            $home
        );
        $this->assertStringNotContainsString(
            '<div class="relative bg-[#ffffff] dark:bg-[#0c1a36]">',
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
        // bg-[#ffffff] is an opt-out of the dark flip, on purpose: brand
        // artwork with dark linework needs a light plate or the mark vanishes.
        $rules = preg_replace("#/\*.*?\*/#s", '', $this->css());

        $this->assertStringNotContainsString("bg-\[\#ffffff\]", $rules);
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

    /**
     * In dark the home cards and the nav are the PAGE, not surfaces lifted off
     * it. Both are transparent rather than a matching colour: the page ground
     * carries a grain layer, so an identical background-color still reads as a
     * flat patch on a textured page - and re-declaring the grain per element
     * would seam, since each starts the pattern at its own origin.
     */
    public function test_dark_cards_and_nav_are_the_page_not_a_surface(): void
    {
        $cards = file_get_contents(resource_path('views/components/home/category-cards.blade.php'));
        $home = file_get_contents(resource_path('views/welcome.blade.php'));
        $css = $this->css();

        $this->assertStringContainsString('dark:bg-transparent', $cards);
        $this->assertStringContainsString('dark:bg-transparent', $home);
        $this->assertStringNotContainsString('dark:bg-black', $cards);
        $this->assertStringNotContainsString('dark:bg-black', $home);

        $this->assertStringContainsString('.dark .nav-surface { background-color: transparent; }', $css);
        $this->assertStringNotContainsString('.dark .nav-surface { background-color: var(--ground); }', $css);

        // Light is untouched: the bar is deliberately white there, not the page
        // tone, so it reads as a bar rather than dissolving into the page.
        $this->assertStringContainsString('.nav-surface { background-color: #ffffff; }', $css);
    }
}
