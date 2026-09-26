<?php

namespace Tests\Feature\Shop;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Pull to refresh in the installed app.
 *
 * Standalone mode turns off the browser's own pull-to-refresh, so the app
 * brings its own. Two things separate it from a reload button.
 *
 * It says when it is armed. A pull has no click to commit it, so the spinner
 * has to answer while the finger is still down: grey and small does nothing,
 * brand blue and a size up will fire on release.
 *
 * And it keeps the page on screen. A hard reload drops the document the moment
 * it commits, leaving the customer looking at a white screen with the
 * platform's own grey spinner until the server answers. Fetching and swapping
 * instead leaves our spinner up over the content the whole way.
 */
class PullToRefreshTest extends TestCase
{
    private function ptrSource(): string
    {
        return File::get(resource_path('views/components/pull-to-refresh.blade.php'));
    }

    public function test_the_spinner_arms_itself_at_the_threshold(): void
    {
        $js = $this->ptrSource();

        // The class follows the pull, both ways, so backing off disarms it.
        $this->assertStringContainsString("e.classList.toggle('rshop-ptr-ready', nextPx >= THRESHOLD);", $js);
        $this->assertStringContainsString("e.classList.remove('rshop-ptr-ready', 'rshop-ptr-refreshing');", $js);

        // Armed reads as colour AND size: colour alone is easy to miss under a
        // thumb, and the two states must not be told apart by hue only.
        $this->assertMatchesRegularExpression(
            '/#rshop-ptr\.rshop-ptr-ready \.rshop-ptr-circle,\s*#rshop-ptr\.rshop-ptr-refreshing \.rshop-ptr-circle \{\s*color: #2563eb;\s*transform: scale\(1\.12\);/',
            $js
        );
        // And it is grey until then, so the armed state is a change.
        $this->assertMatchesRegularExpression('/\.rshop-ptr-circle \{[^}]*color: #94a3b8;/s', $js);
    }

    public function test_the_refresh_keeps_the_page_on_screen(): void
    {
        $js = $this->ptrSource();

        $this->assertStringContainsString('window.Livewire.navigate(window.location.href);', $js);
        $this->assertStringContainsString('setTimeout(refresh, 450);', $js);
        $this->assertStringNotContainsString('setTimeout(() => window.location.reload(), 450);', $js);
    }

    /**
     * A refresh that served a cached copy would be a refresh that refreshed
     * nothing, and that is worse than the white screen. Both ways out fall
     * back to the hard reload.
     */
    public function test_it_falls_back_to_a_hard_reload_when_a_swap_cannot_be_trusted(): void
    {
        $js = $this->ptrSource();

        // Livewire not up yet.
        $this->assertStringContainsString("if (! window.Livewire || typeof window.Livewire.navigate !== 'function') {", $js);
        // Livewire would answer from its back/forward cache.
        $this->assertMatchesRegularExpression('/if \(ev\.detail && ev\.detail\.cached\) \{\s*ev\.preventDefault\(\);\s*hard\(\);/', $js);
        // And the navigate call itself throwing.
        $this->assertMatchesRegularExpression('/\} catch \(_\) \{\s*hard\(\);/', $js);

        $this->assertSame(3, substr_count($js, 'hard();'), 'every path out should reload');
    }

    /** The spinner never outstays the refresh, whatever the swap does. */
    public function test_the_spinner_always_leaves(): void
    {
        $js = $this->ptrSource();

        $this->assertStringContainsString("document.addEventListener('livewire:navigated', done, { once: true });", $js);
        $this->assertStringContainsString('setTimeout(done, 8000);', $js);
        $this->assertStringContainsString('const done = () => { refreshing = false; snapBack(); };', $js);
    }

    /** Still the installed app only: a browser already does this itself. */
    public function test_it_stays_out_of_the_way_of_a_browser(): void
    {
        $js = $this->ptrSource();

        $this->assertStringContainsString('if (! standalone) { return; }', $js);
        $this->assertStringContainsString("window.matchMedia('(display-mode: standalone)').matches", $js);
    }

    /** Reduced motion keeps the colour cue and drops the movement. */
    public function test_reduced_motion_keeps_the_cue_without_the_bounce(): void
    {
        $js = $this->ptrSource();

        $this->assertMatchesRegularExpression(
            '/@media \(prefers-reduced-motion: reduce\) \{[^}]*\}[\s\S]*?#rshop-ptr\.rshop-ptr-refreshing \.rshop-ptr-circle \{ transform: none; \}/',
            $js
        );
    }
}
