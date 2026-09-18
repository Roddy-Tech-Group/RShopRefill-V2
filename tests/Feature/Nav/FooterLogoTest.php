<?php

namespace Tests\Feature\Nav;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The footer wears the mark, not the wordmark. The name is already at the top
 * of the page and written again in the line below the logo, so down here the R
 * on its own is enough.
 */
class FooterLogoTest extends TestCase
{
    use RefreshDatabase;

    private function footer(): string
    {
        $html = $this->withoutVite()->get('/')->assertOk()->getContent();

        $start = strpos($html, '<footer');
        $this->assertNotFalse($start, 'The footer did not render.');

        return substr($html, $start);
    }

    public function test_the_footer_shows_the_mark_alone(): void
    {
        $footer = $this->footer();

        $this->assertStringContainsString('assets/PWAicon.webp', $footer);
        $this->assertStringNotContainsString('assets/logo.webp', $footer);

        // Still the link home, and still named for a screen reader.
        $this->assertMatchesRegularExpression('/<a href="'.preg_quote(e(route('home')), '/').'"[^>]*>.*?PWAicon\.webp/s', $footer);
        $this->assertMatchesRegularExpression('/PWAicon\.webp[^>]*alt="[^"]+"/s', $footer);
    }

    /**
     * The mark is a blue R on transparent, so on the dark slab it needs the
     * same whitening the nav's own logo gets, which is keyed off this class.
     */
    public function test_the_mark_turns_white_on_the_dark_slab(): void
    {
        $footer = $this->footer();

        $this->assertStringContainsString('class="site-logo h-full w-auto', $footer);

        $css = file_get_contents(resource_path('css/app.css'));
        $this->assertStringContainsString('.dark .site-logo,', $css);
        $this->assertStringContainsString('filter: brightness(0) invert(1) !important;', $css);
    }

    /** The header keeps the wordmark: that is where the name is read. */
    public function test_the_header_still_carries_the_wordmark(): void
    {
        $nav = file_get_contents(resource_path('views/components/nav/main-nav.blade.php'));

        $this->assertStringContainsString("asset('assets/logo.webp')", $nav);
    }
}
