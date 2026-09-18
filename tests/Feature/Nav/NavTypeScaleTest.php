<?php

namespace Tests\Feature\Nav;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * The nav's type spec: Roobert, 16/16, #002926.
 *
 * Every control on the two nav rows shares one face, so the rows read as one
 * bar rather than as several widgets that happen to sit together. The size was
 * 13px on the utility row and 14px on the category rail before this - close
 * enough to look like a mistake, far enough apart to see.
 *
 * Source-level assertions: this class has no RefreshDatabase, so it must not
 * hit routes - the storefront nav queries categories.
 */
class NavTypeScaleTest extends TestCase
{
    private function navSource(string $name): string
    {
        $source = File::get(resource_path('views/components/nav/'.$name.'.blade.php'));

        // Comments quote class strings, so they would answer these assertions
        // without any of the markup actually carrying the class.
        return (string) preg_replace('/\{\{--.*?--\}\}/s', '', $source);
    }

    public function test_the_face_declares_the_supplied_spec(): void
    {
        $css = File::get(resource_path('css/app.css'));

        $rule = substr($css, (int) strpos($css, '.nav-text {'));
        $rule = substr($rule, 0, (int) strpos($rule, '}'));

        // 399, the site's base weight: DM Sans is variable, so it holds the
        // exact cut rather than snapping to the nearest static one. The nav
        // takes the same weight as body copy - it is chrome, and anything
        // heavier made the bar the loudest thing on the page.
        $this->assertStringContainsString('font-weight: 399;', $rule);
        $this->assertStringContainsString('font-size: 16px;', $rule);
        $this->assertStringContainsString('line-height: 16px;', $rule);

        // The site face, not a nav-only one: Roobert was never licensed, and
        // the whole storefront moved to DM Sans rather than keep a stack whose
        // first family does not exist.
        $this->assertStringContainsString("font-family: 'DM Sans', sans-serif;", $rule);
        $this->assertStringNotContainsString('Roobert', $rule);

        // Colour must stay out of it: the rule is unlayered, so a colour here
        // would beat every dark: variant and the active pill's inverted ink.
        $this->assertStringNotContainsString('color:', $rule);
    }

    public function test_every_named_nav_control_carries_the_face(): void
    {
        // language-switcher is deliberately absent: it renders inside the
        // locale modal, not on the nav rows, so it wears the modal's type.
        foreach ([
            'utility-links' => 1,       // Help. The region pill is its own file
            'region-pill' => 1,         // ...here, shared by the row and the sheet
            'invite-link' => 1,
            'account-cart' => 2,        // Login, Cart
            'main-nav' => 1,            // the shared category-tab class
        ] as $view => $expected) {
            $this->assertSame(
                $expected,
                substr_count($this->navSource($view), 'nav-text'),
                $view.' should carry the nav face on '.$expected.' control(s).'
            );
        }
    }

    /** The old sizes are gone, not merely overridden by an unlayered rule. */
    public function test_the_superseded_sizes_are_removed(): void
    {
        foreach (['utility-links', 'invite-link', 'account-cart'] as $view) {
            $this->assertStringNotContainsString('text-[13px]', $this->navSource($view));
        }

        $nav = $this->navSource('main-nav');
        $this->assertStringNotContainsString('text-sm font-medium transition-colors', $nav);
    }

    /**
     * The supplied spec named #002926 for the nav ink. It is not used: at 16px
     * on a white bar it reads as green rather than as near-black, which is not
     * what the value was meant to convey. The row uses the neutral zinc ink the
     * rest of the chrome already uses.
     */
    public function test_the_nav_does_not_use_the_green_spec_ink(): void
    {
        foreach (['utility-links', 'invite-link', 'account-cart', 'main-nav', 'language-switcher'] as $view) {
            $this->assertStringNotContainsString('#002926', $this->navSource($view));
        }
    }

    /**
     * Dark mode. Several controls carried a light fill with no dark
     * counterpart, so hovering in dark mode painted a near-white pill under
     * near-black text - the Help link and both theme-picker mounts especially.
     *
     * Anything that sets a light ink or a light hover must answer for dark too.
     */
    public function test_every_nav_control_answers_both_themes(): void
    {
        foreach (['utility-links', 'invite-link', 'account-cart', 'main-nav'] as $view) {
            $source = $this->navSource($view);

            preg_match_all('/class="([^"]*)"/', $source, $matches);

            foreach ($matches[1] as $classes) {
                if (preg_match('/(^|\s)text-zinc-(900|800|700)/', $classes)) {
                    $this->assertStringContainsString(
                        'dark:text-', $classes,
                        $view.' sets a light ink with no dark counterpart: '.$classes
                    );
                }

                if (preg_match('/(^|\s)hover:bg-(\[#|blue-100)/', $classes)) {
                    $this->assertStringContainsString(
                        'dark:hover:bg-', $classes,
                        $view.' hovers to a light fill in dark mode: '.$classes
                    );
                }
            }
        }
    }
}
