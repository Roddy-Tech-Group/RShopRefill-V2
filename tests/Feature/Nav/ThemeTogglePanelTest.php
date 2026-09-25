<?php

namespace Tests\Feature\Nav;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * The dropdown variant's panel is a menu people read, not a dense icon strip,
 * so its rows are sm-sized with 20px marks rather than the original xs/16px.
 */
class ThemeTogglePanelTest extends TestCase
{
    use RefreshDatabase;

    private function toggle(): string
    {
        return File::get(resource_path('views/components/theme-toggle.blade.php'));
    }

    public function test_the_panel_rows_are_full_size(): void
    {
        $blade = $this->toggle();

        $this->assertStringContainsString(
            'gap-2.5 whitespace-nowrap rounded-[7px] py-2 pl-2.5 pr-5 text-left text-sm font-medium',
            $blade
        );

        // The cramped original must be gone, not merely overridden.
        $this->assertStringNotContainsString('rounded-[5px] py-1.5 pl-2 pr-4 text-left text-xs', $blade);
    }

    /**
     * The two variants size independently now. The dropdown panel keeps 20px
     * marks: it is a menu people read, and it opens over the page with room to
     * spare. The segmented strip went back to 16px because it does not open -
     * it stands permanently in the footer beside a "Theme" label, where a
     * full-size control read as the loudest thing in the row.
     */
    public function test_the_panel_marks_stay_full_size(): void
    {
        $blade = $this->toggle();

        $this->assertSame(1, substr_count($blade, '<x-dynamic-component :component="$opt[\'icon\']" class="h-5 w-5" />'));
        $this->assertSame(1, substr_count($blade, '<x-dynamic-component :component="$opt[\'icon\']" class="h-4 w-4" />'));
    }

    /** The segmented strip is the compact one: 28px cells, 16px marks. */
    public function test_the_segmented_variant_is_compact(): void
    {
        $blade = $this->toggle();

        $this->assertStringContainsString('flex h-7 w-7 items-center justify-center rounded-full', $blade);
        $this->assertStringNotContainsString('flex h-9 w-9 items-center justify-center rounded-[9px]', $blade);

        // A pill, not a rounded rectangle: at this size the corner radius was
        // most of the cell anyway, so the shape reads cleaner fully round.
        // Its outline is the locale chip's hairline ring (ring-zinc-200, which
        // app.css remaps to #dee3e1 on the storefront), not the old blue
        // border, so the two controls in the footer row read as one family.
        $this->assertStringContainsString('items-center gap-0.5 rounded-full p-0.5 ring-1 ring-zinc-200', $blade);
        $this->assertStringNotContainsString('border-blue-400', $blade);
    }

    public function test_the_panel_still_renders_all_three_choices(): void
    {
        $html = $this->withoutVite()->get('/')->assertOk()->getContent();

        foreach (['Light', 'Dark', 'Auto'] as $label) {
            $this->assertStringContainsString($label, $html);
        }
    }
}
