<?php

namespace Tests\Feature\Shop;

use Tests\TestCase;

/**
 * The listing's paged rows clip on both axes so the grid can scroll sideways.
 * A brand tile's ring and shadow are box-shadows drawn outside its box, so
 * with the grid flush against the track's edges the first row lost its top
 * hairline, the third its shadow and the first column its left hairline: the
 * cards along the top and left of the gift-card page looked border-less. The
 * track pads for them on every side, takes the same amount back in margin so
 * nothing else on the page moves, and its column and paging arithmetic read
 * the width inside that padding, or every page would drift by it.
 */
class CategorySectionTrackTest extends TestCase
{
    private function section(): string
    {
        return file_get_contents(resource_path('views/components/shop/category-section.blade.php'));
    }

    public function test_the_track_leaves_room_for_the_tiles_ring_and_shadow(): void
    {
        preg_match('/x-ref="track"[\s\S]*?class="([^"]+)"/', $this->section(), $track);

        $this->assertNotEmpty($track, 'The scroll track did not render.');
        $this->assertStringContainsString('overflow-y-hidden', $track[1], 'The track still clips, which is what makes the room necessary.');
        foreach (['px-1', 'py-1'] as $padding) {
            $this->assertStringContainsString($padding, $track[1], "No room around the grid ({$padding}): the ring and shadow clip.");
        }

        foreach (['-mx-1', '-my-1'] as $margin) {
            $this->assertStringContainsString($margin, $track[1], "The padding must be given back in margin ({$margin}) so the page rhythm and the left edge hold.");
        }
    }

    /** Columns fill, and a page scrolls by, the width INSIDE the padding, never clientWidth alone. */
    public function test_the_arithmetic_reads_the_width_inside_the_padding(): void
    {
        $section = $this->section();

        $this->assertStringContainsString('const available = Math.min(this.innerWidth(track), window.innerWidth);', $section);
        $this->assertStringContainsString("track.scrollBy({ left: direction * this.innerWidth(track), behavior: 'smooth' });", $section);
        $this->assertStringContainsString('track.clientWidth - (parseFloat(style.paddingLeft) || 0) - (parseFloat(style.paddingRight) || 0)', $section);
        $this->assertStringNotContainsString('Math.min(track.clientWidth, window.innerWidth)', $section);
        $this->assertStringNotContainsString('direction * track.clientWidth', $section);
    }

    public function test_the_tile_still_wears_the_ring_the_room_is_for(): void
    {
        $tile = file_get_contents(resource_path('views/components/shop/brand-tile.blade.php'));

        $this->assertStringContainsString('shadow-sm ring-1 ring-zinc-200', $tile);
    }
}
