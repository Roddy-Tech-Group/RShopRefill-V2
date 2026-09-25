<?php

namespace Tests\Feature\Nav;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * The navigation progress bar is the site's one loading signal for a page
 * change.
 *
 * It was off for a while, when a full-screen branded loader also covered every
 * hard reload. That loader is gone because it hid pages the server had already
 * rendered. The thin bar stays, because it never covers content: a fast
 * navigation costs nothing and a slow one still says it is working.
 *
 * Pinned in a test because it is a config default: a future `php artisan
 * config:publish` or a package upgrade could quietly flip it.
 */
class NavigationProgressBarTest extends TestCase
{
    // One test here renders a page to prove no layout puts a slide back.
    use RefreshDatabase;

    public function test_the_navigation_progress_bar_is_enabled(): void
    {
        $this->assertTrue(
            config('livewire.navigate.show_progress_bar'),
            'Page changes should show the thin progress bar.'
        );
    }

    /** The bar keeps a configured colour. */
    public function test_the_bar_colour_is_still_configured(): void
    {
        $this->assertNotEmpty(config('livewire.navigate.progress_bar_color'));
    }

    public function test_navigation_does_not_wait_for_a_decorative_page_entrance_animation(): void
    {
        $this->assertStringNotContainsString('page-enter', File::get(resource_path('js/app.js')));
        $this->assertStringNotContainsString('.page-enter', File::get(resource_path('css/app.css')));

        // The same slide had a second home: a bare `main { animation: ... }` in
        // the shared head partial, which put it on every layout on the site.
        // Removing it from the CSS and the JS alone left it running.
        $head = File::get(resource_path('views/partials/head.blade.php'));
        $this->assertStringNotContainsString('pageSlideIn', $head);
    }

    /**
     * A page appears where it belongs, not 32px below it.
     *
     * Asserted on a rendered page rather than the source, so a slide
     * reintroduced from any layout, partial or component is caught.
     */
    public function test_a_page_arrives_in_place(): void
    {
        $html = $this->withoutVite()->get('/')->assertOk()->getContent();

        $this->assertStringNotContainsString('pageSlideIn', $html);

        // A rule whose selector is exactly `main` is the shape that put the
        // slide on every layout - as opposed to the opt-in
        // `body[data-page-transition=...] main`, which a layout has to ask for.
        // Collected rather than matched in one pattern so that the one thing a
        // bare `main` rule may still say, `animation: none` under reduced
        // motion, is named here instead of hidden in a lookahead.
        preg_match_all('/(?:^|[};])\s*main\s*\{([^}]*)\}/m', $html, $rules);

        foreach ($rules[1] as $body) {
            if (preg_match('/animation:\s*([^;]+)/', $body, $animation)) {
                $this->assertSame(
                    'none',
                    trim($animation[1]),
                    'Something is animating every <main> again: '.trim($animation[1])
                );
            }
        }
    }

    /** A layout can still ask for a transition; admin does. */
    public function test_a_layout_can_still_opt_into_one(): void
    {
        $head = File::get(resource_path('views/partials/head.blade.php'));

        $this->assertStringContainsString('microFadeShift', $head);
        $this->assertStringContainsString('body[data-page-transition="micro-fade-shift"] main', $head);
        $this->assertStringContainsString(
            'data-page-transition="micro-fade-shift"',
            File::get(resource_path('views/components/layouts/admin.blade.php'))
        );
    }
}
