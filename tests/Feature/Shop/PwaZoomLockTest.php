<?php

namespace Tests\Feature\Shop;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Installed to a home screen, the app does not zoom.
 *
 * Pinching a native app scales nothing, and here it only ever stranded the
 * customer on a half-scrolled page with the chrome adrift: every top- and
 * bottom-anchored surface is positioned against a viewport the zoom has just
 * moved out from under it.
 *
 * A browser tab is deliberately left alone. Taking pinch-zoom away there
 * removes the one way a customer who needs larger text can get it, and the
 * browser's own controls keep the fixed chrome honest. So the rule is written
 * at runtime off display-mode rather than baked into the meta tag.
 */
class PwaZoomLockTest extends TestCase
{
    use RefreshDatabase;

    private function headPartial(): string
    {
        return File::get(resource_path('views/partials/head.blade.php'));
    }

    /** The tag the server ships stays zoomable: it is what a browser tab gets. */
    public function test_the_served_viewport_tag_still_allows_zoom(): void
    {
        $html = $this->withoutVite()->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover" />', $html);

        // Neither lock is baked into the markup, or every browser tab would
        // carry it too.
        preg_match('/<meta name="viewport"[^>]*>/', $html, $tag);
        $this->assertStringNotContainsString('user-scalable=no', $tag[0]);
        $this->assertStringNotContainsString('maximum-scale', $tag[0]);
    }

    /** Standalone, the same tag is rewritten before the first paint. */
    public function test_a_standalone_launch_rewrites_the_viewport_and_flags_itself(): void
    {
        $head = $this->headPartial();

        // Every way a launch can report itself as installed, including iOS,
        // which answers on navigator.standalone rather than display-mode.
        $this->assertStringContainsString('window.navigator.standalone === true', $head);
        foreach (['standalone', 'fullscreen', 'minimal-ui'] as $mode) {
            $this->assertStringContainsString("window.matchMedia('(display-mode: {$mode})').matches", $head);
        }

        $this->assertStringContainsString("viewport.setAttribute('content', 'width=device-width, initial-scale=1.0, viewport-fit=cover, maximum-scale=1.0, user-scalable=no');", $head);
        $this->assertStringContainsString("document.documentElement.classList.add('is-standalone');", $head);

        // A browser tab leaves before any of that runs.
        $this->assertMatchesRegularExpression('/if \(! standalone\) \{\s*return;\s*\}/', $head);

        // Inline and in the head, so the app is never briefly zoomable on the
        // way in. The viewport tag it edits must already have been parsed.
        $this->assertLessThan(
            strpos($head, 'viewport.setAttribute('),
            strpos($head, '<meta name="viewport"'),
            'the script must come after the tag it rewrites'
        );
    }

    /**
     * The meta tag alone is not enough. iOS reads a pinch as a gesture whatever
     * it says, and a double-tap scales the page past both.
     */
    public function test_the_pinch_and_the_double_tap_are_both_covered(): void
    {
        $head = $this->headPartial();

        foreach (['gesturestart', 'gesturechange', 'gestureend'] as $gesture) {
            $this->assertStringContainsString("'{$gesture}'", $head, "iOS {$gesture} is not prevented");
        }
        // preventDefault on a passive listener does nothing, so it must opt out.
        $this->assertStringContainsString('{ passive: false }', $head);

        // And the double-tap, which is a touch-action concern, scoped to the
        // class the script sets so a browser tab keeps both gestures.
        $css = File::get(resource_path('css/app.css'));
        $this->assertMatchesRegularExpression(
            '/html\.is-standalone,\s*html\.is-standalone body \{\s*touch-action: manipulation;\s*\}/',
            $css,
            'the double-tap zoom is not disabled for an installed launch'
        );
        $this->assertStringNotContainsString("\nhtml {\n    touch-action: manipulation;", $css, 'touch-action must not apply to browser tabs');
    }
}
