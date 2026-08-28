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

    public function test_the_panel_marks_scale_with_the_rows(): void
    {
        // Both variants use 20px marks now: the dropdown rows for legibility,
        // the segmented strip because 16px on a 28px cell was a small target.
        $blade = $this->toggle();

        $this->assertSame(2, substr_count($blade, '<x-dynamic-component :component="$opt[\'icon\']" class="h-5 w-5" />'));
        $this->assertStringNotContainsString('class="h-4 w-4" />', $blade);
    }

    /** The segmented strip was scaled up too - 28px cells were a small target. */
    public function test_the_segmented_variant_is_full_size(): void
    {
        $blade = $this->toggle();

        $this->assertStringContainsString('flex h-9 w-9 items-center justify-center rounded-[9px]', $blade);
        $this->assertStringNotContainsString('flex h-7 w-7 items-center justify-center rounded-[7px]', $blade);

        // Its shell grows with the cells, or the cells crowd the border.
        $this->assertStringContainsString('items-center gap-1 rounded-[14px] border border-blue-400 p-1', $blade);
    }

    public function test_the_panel_still_renders_all_three_choices(): void
    {
        $html = $this->withoutVite()->get('/')->assertOk()->getContent();

        foreach (['Light', 'Dark', 'Auto'] as $label) {
            $this->assertStringContainsString($label, $html);
        }
    }
}
