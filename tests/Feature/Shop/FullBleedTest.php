<?php

namespace Tests\Feature\Shop;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * .full-bleed breaks a carousel track out of the content column so it spans the
 * viewport - which is what lets those rows scroll edge to edge.
 *
 * It used to be a plain 100vw and this file used to insist on that. 100vw is
 * the wrong measure: it counts the vertical scrollbar, so on every page long
 * enough to scroll the tracks ran a scrollbar's width past the page's usable
 * area. The rule is now written against --page-w, a real pixel width app.js
 * publishes from a fixed-position probe (whose containing block is the viewport
 * itself, so it measures the usable area and cannot be widened by the very
 * overflow it is there to prevent). 100vw survives only as the var() fallback,
 * which is the old behaviour for the moment before the bundle runs - a track a
 * scrollbar too wide, rather than a broken one.
 *
 * What has NOT changed is why the pure-CSS shortcut is banned. An attempt to
 * correct for the scrollbar via `body { --sbw: calc(100vw - 100%) }` broke the
 * rule outright: a custom property is substituted as tokens, so its `100%`
 * resolved against the element USING it rather than against body. Inside the
 * rule that became 100vw minus the carousel's own container width, `width`
 * collapsed to roughly the content column, and the tracks stopped bleeding -
 * and stopped scrolling edge to edge. The second test below still guards it.
 */
class FullBleedTest extends TestCase
{
    use RefreshDatabase;

    private function css(): string
    {
        return File::get(resource_path('css/app.css'));
    }

    public function test_the_track_spans_the_viewport(): void
    {
        $css = $this->css();

        preg_match('/
\.full-bleed \{(.*?)
\}/s', $css, $m);
        $this->assertNotEmpty($m, 'The .full-bleed rule is missing.');

        // Both declarations measure the page with --page-w now, and both keep
        // 100vw as the fallback only. The margin is half the measured width
        // taken off half the container: the container is itself centred in
        // body, so the two halves cancel and the track lands flush with the
        // usable area on both sides, wherever the browser chose to put the
        // scrollbar gutter.
        $this->assertStringContainsString('width: var(--page-w, 100vw);', $m[1]);
        $this->assertStringContainsString('margin-inline: calc(50% - (var(--page-w, 100vw) / 2));', $m[1]);

        // 100vw may only ever appear as that fallback. A bare `width: 100vw`
        // coming back is the exact regression this rule was rewritten to end.
        $this->assertStringNotContainsString('width: 100vw;', $m[1]);
    }

    /**
     * The guard against the regression above: no percentage may reach this rule
     * through a custom property, because it will not mean what it reads as.
     */
    public function test_the_rule_resolves_no_percentage_through_a_variable(): void
    {
        $css = $this->css();

        preg_match('/
\.full-bleed \{(.*?)
\}/s', $css, $m);
        $this->assertStringNotContainsString('var(--sbw)', $m[1]);

        // CSS comments stripped: the note above the rule explains the broken
        // declaration by quoting it, and prose must not be able to fail this.
        $declarations = preg_replace('#/\*.*?\*/#s', '', $css);
        $this->assertStringNotContainsString('--sbw:', $declarations);
    }

    public function test_home_carousels_use_the_utility(): void
    {
        foreach (['brand-row', 'hero-cards'] as $component) {
            $blade = File::get(resource_path('views/components/home/'.$component.'.blade.php'));
            $markup = preg_replace('/\{\{--.*?--\}\}/s', '', $blade);

            $this->assertStringContainsString('full-bleed', $markup, $component.' should use the utility.');
            $this->assertStringNotContainsString('50%-50vw', $markup, $component.' still bleeds by hand.');
        }
    }

    /**
     * The utility reaches the rendered page, not just the templates.
     *
     * Asserted through the hero cards, which the migrations seed. The gift-card
     * brand rows use the same track but need catalogue data, and this database
     * starts empty - so their absence here proves nothing either way.
     */
    public function test_the_utility_reaches_the_rendered_page(): void
    {
        $html = $this->withoutVite()->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('full-bleed', $html);

        // And the pair it replaced is gone from the markup for good.
        $this->assertStringNotContainsString('w-screen', $html);
        $this->assertStringNotContainsString('class="mx-[calc(50%-50vw)]', $html);
    }
}
