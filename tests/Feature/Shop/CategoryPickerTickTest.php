<?php

namespace Tests\Feature\Shop;

use App\Models\Category;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The mobile category sheet marks the current row with a tick and nothing
 * else.
 *
 * It used to carry a blue pill - fill plus ring - and a bright blue label, so
 * three things said "this one" at once and the label read as a different kind
 * of text to every other label in the sheet. Rows are navy at one weight now;
 * the current one is bold, with the tick beside it.
 */
class CategoryPickerTickTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Category::factory()->create(['slug' => 'gift-cards', 'name' => 'Gift Cards']);
    }

    private function picker(): string
    {
        return file_get_contents(resource_path('views/components/shop/category-picker.blade.php'));
    }

    public function test_no_row_carries_a_fill_or_a_ring(): void
    {
        $picker = $this->picker();

        foreach (['bg-blue-50', 'ring-blue-200', 'text-blue-700', 'cat-selected', 'cat-hover'] as $gone) {
            $this->assertStringNotContainsString($gone, $picker);
        }
    }

    /**
     * Three lists share the treatment: the categories, and the subcategories
     * in both their navigating and client-filtering forms.
     */
    public function test_every_row_is_navy_and_the_current_one_is_bold(): void
    {
        $picker = $this->picker();

        $this->assertSame(3, substr_count($picker, "'font-bold text-[#0c1a36] dark:text-white'"));
        $this->assertSame(3, substr_count($picker, "'font-medium text-[#0c1a36] dark:text-zinc-200'"));
    }

    public function test_the_tick_is_what_marks_the_current_row(): void
    {
        // One per list: category, subcategory (client filter), subcategory
        // (navigating).
        $this->assertSame(3, substr_count($this->picker(), 'd="M4.5 12.75l6 6 9-13.5"'));
    }

    /**
     * The trigger pill wears the Invite a Friend chip style - light wash,
     * dark text, full radius - not the old navy fill, so the storefront
     * chips read as one family.
     */
    public function test_the_trigger_pill_wears_the_invite_chip_style(): void
    {
        $picker = $this->picker();

        // White fill + page-tone ring: the section behind the pill is the
        // #f4f6f5 wash itself, so a wash-filled pill vanished into it.
        $this->assertStringContainsString('rounded-full bg-[#ffffff]', $picker);
        $this->assertStringContainsString('ring-1 ring-[#dee3e1]', $picker);
        $this->assertStringContainsString('hover:ring-[#c9d0cd]', $picker);

        foreach (['bg-[#0c1a36] px-4', 'hover:bg-[#1d3252]', 'ring-white/15'] as $gone) {
            $this->assertStringNotContainsString($gone, $picker);
        }

        // The icon keeps its own colour on the light chip and only inverts in
        // dark - a blanket invert painted it white on white.
        $this->assertStringNotContainsString('object-contain brightness-0 invert', $picker);
    }

    public function test_the_sheet_renders_on_a_storefront_page(): void
    {
        $html = $this->get(route('shop.gift-cards'))->assertOk()->getContent();

        $this->assertStringContainsString('font-bold text-[#0c1a36] dark:text-white', $html);
        $this->assertStringNotContainsString('bg-blue-50 font-bold text-blue-700', $html);
    }
}
