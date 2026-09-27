<?php

namespace Tests\Feature\Dashboard;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Below lg the dashboard is one panel under the bar, and it is one surface all
 * the way down whatever the page holds.
 *
 * The panel is given the viewport's height minus the bar, and it scrolls
 * inside its own rounded frame. The content area inside it only claimed a
 * height from lg up, so on a short page - Appearance, My eSIMs with nothing
 * bought yet - it stopped where its content did and the rest of the panel
 * read as a second colour below the card. A page is not supposed to change
 * tone halfway down because it ran out of things to say.
 */
class MobilePanelFillsTest extends TestCase
{
    use RefreshDatabase;

    private function layout(): string
    {
        return File::get(resource_path('views/components/layouts/dashboard.blade.php'));
    }

    public function test_the_content_area_fills_the_panel_at_every_width(): void
    {
        preg_match('/<div data-page-content[^>]*class="([^"]*)"/', $this->layout(), $m);
        $this->assertNotEmpty($m, 'the page content area is missing');

        // Unqualified, so it holds on a phone as well as from lg up. It used to
        // be lg:min-h-full, which is the whole bug: the panel has a definite
        // height below lg too, so there was a height to fill and nothing
        // filling it.
        $this->assertMatchesRegularExpression('/(^|\s)min-h-full(\s|$)/', $m[1], 'the content area does not fill the panel');
        $this->assertStringNotContainsString('lg:min-h-full', $m[1], 'filling the panel is not a desktop-only concern');
    }

    /** The height it fills is the panel's, and the panel is the viewport's. */
    public function test_the_panel_is_as_tall_as_the_viewport_under_the_bar(): void
    {
        $css = File::get(resource_path('css/app.css'));

        preg_match('/\.dash-mobile-panel \{(.*?)\}/s', $css, $panel);
        $this->assertNotEmpty($panel, 'the panel rule is missing');
        $this->assertStringContainsString('height: calc(100dvh - 3.75rem - env(safe-area-inset-top));', $panel[1]);

        // And the spacer above it reserves exactly what the panel subtracts, so
        // the two add up to the viewport rather than overshooting it.
        $this->assertStringContainsString(
            'style="height: calc(env(safe-area-inset-top) + 3.75rem);"',
            $this->layout(),
            'the spacer no longer matches what the panel subtracts'
        );
    }

    /**
     * A page with almost nothing on it is the case that showed the seam, so it
     * is the case worth rendering.
     */
    public function test_a_short_page_still_renders_one_content_area(): void
    {
        $html = $this->withoutVite()
            ->actingAs(User::factory()->create(['kyc_status' => 'unsubmitted']))
            ->get(route('dashboard.appearance'))
            ->assertOk()
            ->getContent();

        // The element, not the string: the layout also names it in a handful
        // of inline stylesheet selectors.
        $this->assertSame(1, preg_match_all('/<div data-page-content/', $html), 'there should be exactly one content area');
        $this->assertTrue(str_contains($html, 'flex min-h-full flex-col'), 'the rendered content area does not fill');
    }
}
