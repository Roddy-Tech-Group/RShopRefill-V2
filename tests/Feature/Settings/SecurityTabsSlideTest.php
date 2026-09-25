<?php

namespace Tests\Feature\Settings;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Security Center's tab bar (Password, Transaction PIN, Preferences &
 * Devices) moves one white tile between the tabs instead of repainting the
 * chosen tab: the same slide as the theme control, armed after the first
 * paint so the opening tab does not animate in.
 */
class SecurityTabsSlideTest extends TestCase
{
    use RefreshDatabase;

    public function test_one_tile_slides_between_the_three_tabs(): void
    {
        $html = $this->withoutVite()
            ->actingAs(User::factory()->create())
            ->get(route('dashboard.security'))
            ->assertOk()
            ->getContent();

        $start = strpos($html, 'class="security-tab-thumb');
        $this->assertNotFalse($start, 'the tab thumb is missing');
        $bar = substr($html, $start, strpos($html, '</div>', $start) - $start);

        $this->assertStringContainsString('w-[calc((100%-0.5rem)/3)] rounded-[10px] bg-white shadow-sm duration-300', $bar);
        $this->assertStringContainsString("['password','pin','preferences'].indexOf(tab) + ' * (100% + 0.25rem)))'", $bar);
        $this->assertStringContainsString(":class=\"slide && 'transition-transform'\"", $bar);
        $this->assertStringContainsString('motion-reduce:transition-none', $bar);

        // Three tabs above the tile, in order, short enough for one line each
        // at phone width, changing colour only.
        $this->assertSame(3, substr_count($bar, 'class="security-tab relative z-10'));
        foreach (['Transaction PIN', 'Preferences &amp; Devices'] as $long) {
            $this->assertStringNotContainsString($long, $bar, 'a tab still carries the long name');
        }
        $last = -1;
        foreach (['Password', 'PIN', 'Devices'] as $label) {
            $at = strpos($bar, $label);
            $this->assertNotFalse($at, $label.' is missing');
            $this->assertGreaterThan($last, $at);
            $last = $at;
        }
        $this->assertStringNotContainsString("'bg-white text-black shadow-sm", $bar, 'the chosen tab no longer paints its own tile');
    }
}
