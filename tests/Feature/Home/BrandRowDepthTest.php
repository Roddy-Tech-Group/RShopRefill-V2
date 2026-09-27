<?php

namespace Tests\Feature\Home;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Category rows run several cards deep and page sideways.
 *
 * A single scrolling line showed four brands at a time out of twenty, so the
 * tail of every row went unseen. The track is a column-flowing grid now: it
 * fills top-to-bottom then starts a new column, so the cards stay in reading
 * order while a screenful holds several times as many.
 *
 * The depth differs by breakpoint on purpose. A phone card is 160px against
 * the desktop's 280px, so three short rows read as a block there where three
 * tall ones on a desktop would push everything under them off the fold.
 */
class BrandRowDepthTest extends TestCase
{
    private function render(string $attrs): string
    {
        $html = Blade::render('<x-home.brand-row title="T" '.$attrs.'><li>a</li></x-home.brand-row>');

        preg_match('/<ul[^>]*class="carousel-list[^"]*"/', $html, $m);

        return $m[0] ?? '';
    }

    public function test_a_deep_row_is_a_column_flowing_grid(): void
    {
        $track = $this->render(':carousel="true" :rows="2" :mobile-rows="3"');

        $this->assertStringContainsString('grid-flow-col', $track);
        $this->assertStringContainsString('grid-rows-[repeat(3,auto)]', $track);
        $this->assertStringContainsString('sm:grid-rows-[repeat(2,auto)]', $track);
    }

    /** The default is untouched, so rows that never asked for depth keep one line. */
    public function test_the_default_row_is_a_single_line(): void
    {
        $track = $this->render(':carousel="true"');

        $this->assertStringContainsString('grid-rows-[repeat(1,auto)]', $track);
        $this->assertStringContainsString('sm:grid-rows-[repeat(1,auto)]', $track);
    }

    /**
     * Out-of-range depths clamp rather than emitting a class Tailwind never
     * generated - an ungenerated grid-rows class silently collapses the track
     * to one row, which looks like the prop was ignored.
     */
    public function test_the_depth_clamps_to_what_is_generated(): void
    {
        $this->assertStringContainsString('grid-rows-[repeat(4,auto)]', $this->render(':carousel="true" :mobile-rows="9"'));
        $this->assertStringContainsString('grid-rows-[repeat(1,auto)]', $this->render(':carousel="true" :mobile-rows="0"'));
    }

    /**
     * perView divides the TRACK, not the viewport.
     *
     * "Three per line" cannot be a fixed card width inside a column whose
     * width the viewport does not tell you: the dashboard's row lives in an
     * 8-of-12 grid column, where the 280px card that shows four across the
     * storefront shows barely two.
     */
    public function test_per_view_measures_the_track(): void
    {
        $html = Blade::render('<x-home.brand-row title="T" :carousel="true" :per-view="3"><li>a</li></x-home.brand-row>');

        $this->assertStringContainsString('const perView = 3;', $html);
        $this->assertStringContainsString('t.clientWidth - padX', $html);

        // Off by default, so every other row keeps its fixed widths.
        $plain = Blade::render('<x-home.brand-row title="T" :carousel="true"><li>a</li></x-home.brand-row>');
        $this->assertStringContainsString('const perView = 0;', $plain);
    }

    /** Phones keep the fixed card: three across 390px is smaller than the art. */
    public function test_per_view_does_not_apply_below_sm(): void
    {
        $html = Blade::render('<x-home.brand-row title="T" :carousel="true" :per-view="3"><li>a</li></x-home.brand-row>');

        $this->assertStringContainsString('perView > 0 && window.innerWidth >= 640', $html);
    }

    /** Every storefront category row gets the treatment, not just Popular. */
    public function test_the_storefront_rows_default_to_the_deep_track(): void
    {
        $welcome = File::get(resource_path('views/welcome.blade.php'));

        $this->assertStringContainsString(":rows=\"\$row['rows'] ?? 2\"", $welcome);
        $this->assertStringContainsString(":mobile-rows=\"\$row['mobileRows'] ?? 3\"", $welcome);
    }

    /**
     * The dashboard's Popular rows were a wrapping 5-up grid - a different
     * control from the storefront's. They are the same row now, and both must
     * pass :bleed="false": the full-bleed track spans 100vw and would overrun
     * the column they sit in.
     */
    public function test_the_dashboard_row_matches_and_stays_in_its_column(): void
    {
        $overview = File::get(resource_path('views/livewire/dashboard/overview.blade.php'));

        // One brand row on the overview now, Popular Gift Cards. It keeps the
        // home rows' depth and stays inside its 8-of-12 column.
        $this->assertSame(1, substr_count($overview, '<x-home.brand-row'));
        $this->assertSame(1, substr_count($overview, ':mobile-rows="3"'));
        $this->assertStringContainsString(':per-view="3"', $overview);
        $this->assertSame(1, substr_count($overview, ':bleed="false"'));
        $this->assertSame(1, substr_count($overview, ':carousel="true"'));
    }
}
