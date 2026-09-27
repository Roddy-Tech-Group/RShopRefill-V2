<?php

namespace Tests\Feature\Shop;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * A bleeding carousel scrolls edge to edge wherever the page it sits on is the
 * full width.
 *
 * The dashboard used to refuse it outright, because from lg the sidebar rail
 * holds a column of its own and a page-wide track would run out past the panel
 * it lives in. Below lg there is no rail, the panel IS the page, and the eSIM
 * package rows sat inset for a reason that only applies to a screen they were
 * not on.
 *
 * So the decision moved from the server to the breakpoint. The stylesheet hands
 * the width back above lg inside the dashboard, and the row's own bleeding()
 * makes the matching call for the padding it writes, re-running on resize, so
 * the two can never disagree.
 */
class CarouselBleedTest extends TestCase
{
    private function row(): string
    {
        return File::get(resource_path('views/components/home/brand-row.blade.php'));
    }

    public function test_the_row_decides_at_the_breakpoint_not_on_the_server(): void
    {
        $row = $this->row();

        $this->assertMatchesRegularExpression(
            "/bleeding\(\) \{\s*return this\.bleed\s*&& ! \(document\.body\.classList\.contains\('dashboard'\) && window\.innerWidth >= 1024\);/",
            $row,
            'the row does not work out whether it may bleed'
        );

        // And the padding follows that call, not the raw flag.
        $this->assertStringContainsString('const padLeft = this.bleeding()', $row);
        // The old form ended the line on the raw flag. Matched that way because
        // "this.bleeding()" starts with "this.bleed", so a plain substring
        // check would fail against the very code it is meant to accept.
        $this->assertDoesNotMatchRegularExpression('/const padLeft = this\.bleed\s*$/m', $row);
    }

    /** It re-measures on resize, which is what lets the decision change. */
    public function test_it_re_measures_when_the_width_changes(): void
    {
        $this->assertStringContainsString('@resize.window.debounce.200ms="setup()"', $this->row());
    }

    /** The stylesheet makes the same call, so width and padding agree. */
    public function test_the_stylesheet_hands_the_width_back_above_lg_in_the_dashboard(): void
    {
        $css = File::get(resource_path('css/app.css'));

        $this->assertMatchesRegularExpression(
            '/@media \(min-width: 1024px\) \{\s*body\.dashboard \.full-bleed \{\s*width: 100%;\s*margin-inline: 0;\s*\}\s*\}/',
            $css,
            'the dashboard still lets a page-wide track past its panel'
        );

        // The storefront is untouched: the override is scoped to the dashboard.
        $this->assertMatchesRegularExpression('/\.full-bleed \{[^}]*width: var\(--page-w, 100vw\);/s', $css);
    }

    /** The eSIM rows ask to bleed now, rather than being refused up front. */
    public function test_the_esim_rows_ask_to_bleed(): void
    {
        $esim = File::get(resource_path('views/shop/esim.blade.php'));

        $this->assertSame(2, substr_count($esim, ':bleed="true"'), 'both eSIM carousels should ask to bleed');
        $this->assertStringNotContainsString(':bleed="! $inDash"', $esim, 'the server should not be deciding this any more');
    }
}
