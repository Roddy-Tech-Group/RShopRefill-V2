<?php

namespace Tests\Feature\Nav;

use Tests\TestCase;

/**
 * No SPA navigation progress bar anywhere on the site.
 *
 * Livewire draws a blue bar at the top of the window on every wire:navigate
 * link. Most of those resolve in less time than it takes to read a flash of
 * blue, so it read as the page stuttering rather than as feedback.
 *
 * Pinned in a test because it is a config default: a future `php artisan
 * config:publish` or a package upgrade would quietly bring it back.
 */
class NavigationProgressBarTest extends TestCase
{
    public function test_the_navigation_progress_bar_is_disabled(): void
    {
        $this->assertFalse(
            config('livewire.navigate.show_progress_bar'),
            'The SPA navigation progress bar should stay off site-wide.'
        );
    }

    /** The colour stays configured, so re-enabling is a one-word change. */
    public function test_the_bar_colour_is_still_configured(): void
    {
        $this->assertNotEmpty(config('livewire.navigate.progress_bar_color'));
    }
}
