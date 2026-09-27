<?php

namespace Tests\Feature\Settings;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The theme control's solid tile slides between Light, Dark and Auto instead
 * of jumping: one thumb, moved by the chosen option's index, with the options
 * themselves only changing colour. The slide is armed after the first paint
 * so the saved choice does not animate in from the left on every visit.
 */
class ThemeControlSlidesTest extends TestCase
{
    use RefreshDatabase;

    private function page(): string
    {
        return $this->withoutVite()
            ->actingAs(User::factory()->create())
            ->get(route('dashboard.appearance'))
            ->assertOk()
            ->getContent();
    }

    public function test_one_thumb_slides_to_the_chosen_option(): void
    {
        $html = $this->page();

        $this->assertStringContainsString('class="theme-thumb pointer-events-none absolute inset-y-1 left-1 w-[calc((100%-0.5rem)/3)] rounded-[12px] duration-300', $html);
        // Index times a column plus a gap, over the three options in order.
        $this->assertStringContainsString("['light','dark','system'].indexOf(theme) + ' * (100% + 0.25rem)))'", $html);
        $this->assertStringContainsString(":class=\"slide && 'transition-transform'\"", $html);
        $this->assertStringContainsString('x-init="$nextTick(() => slide = true)"', $html);
        $this->assertStringContainsString('motion-reduce:transition-none', $html);
        $this->assertSame(1, substr_count($html, 'class="theme-thumb'), 'exactly one thumb');
    }

    public function test_the_options_keep_their_state_and_sit_above_the_thumb(): void
    {
        $html = $this->page();

        // The control alone: from its thumb to the end of its radio group.
        $start = strpos($html, 'class="theme-thumb');
        $control = substr($html, $start, strpos($html, '</div>', $start) - $start);

        $this->assertSame(3, substr_count($control, 'role="radio"'));
        $this->assertSame(3, substr_count($control, 'class="theme-option relative z-10'));
        // The tile colour moved to the thumb: the checked option paints text only.
        $this->assertStringContainsString('.theme-option[aria-checked="true"] { color: #ffffff; }', $html);
        $this->assertStringNotContainsString('.theme-option[aria-checked="true"] { background-color', $html);
        $this->assertStringContainsString('.theme-thumb { background-color: #0c1337; }', $html);
        $this->assertStringContainsString('html.dark .theme-thumb { background-color: #fdfdfd; }', $html);
    }
}
