<?php

namespace Tests\Feature\Shop;

use Tests\TestCase;

/**
 * The listing skeleton mirrors the grid it stands in for.
 *
 * Two mobile regressions live here. The column-width variable was written to
 * the content list only, so the skeleton grid kept its CSS fallback - 260px
 * cards on a 390px phone, reflowing to the measured ~171px on reveal. And
 * settle() counted lazy images sitting in the display:none content list;
 * they never intersect, never fire load, and every section sat skeletal
 * until the 2s ceiling however fast the network was.
 */
class CategorySectionSkeletonTest extends TestCase
{
    private function section(): string
    {
        return file_get_contents(resource_path('views/components/shop/category-section.blade.php'));
    }

    public function test_the_column_width_lands_on_the_track_both_grids_share(): void
    {
        $section = $this->section();

        $this->assertStringContainsString("track.style.setProperty('--col-w'", $section);
        $this->assertStringNotContainsString("list.style.setProperty('--col-w'", $section);
    }

    public function test_the_reveal_waits_on_eager_images_only(): void
    {
        $section = $this->section();

        $this->assertStringContainsString("querySelectorAll('img[loading=eager]')", $section);
        $this->assertStringNotContainsString("querySelectorAll('img')", $section);
    }

    /** Skeleton and content share the fallback, and phones get a two-column one. */
    public function test_both_grids_carry_the_phone_width_fallback(): void
    {
        $this->assertSame(2, substr_count(
            $this->section(),
            'auto-cols-[var(--col-w,calc(50vw_-_24px))] sm:auto-cols-[var(--col-w,260px)]'
        ));
    }
}
