<?php

namespace Tests\Feature\Nav;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * The reload loader stands in for the page, so it has to be the page's colour.
 *
 * In dark it painted #0c1a36, then #000000 once .pure-dark applied - while
 * every dark page renders var(--ground), #060607. So a reload flashed pure
 * black and handed over to a page that was not black, on every load.
 */
class AppLoaderGroundTest extends TestCase
{
    private function loader(): string
    {
        return File::get(resource_path('views/components/ui/app-loader.blade.php'));
    }

    public function test_the_dark_loader_paints_the_page_ground(): void
    {
        $loader = $this->loader();

        $this->assertStringContainsString('background: var(--ground, #060607);', $loader);

        // The colours it used to paint instead.
        $this->assertStringNotContainsString('background: #0c1a36;', $loader);
        $this->assertStringNotContainsString('background: #000000;', $loader);
    }

    /**
     * The fallback literal has to equal the token, because the loader's whole
     * job is the moment before app.css has landed - that is when the fallback
     * is what a visitor actually sees.
     */
    public function test_the_fallback_matches_the_token_it_falls_back_from(): void
    {
        $css = File::get(resource_path('css/app.css'));

        $this->assertMatchesRegularExpression('/--ground:\s*#060607;/', $css, 'the loader falls back to #060607');
    }

    /**
     * One dark rule. The theme engine applies .pure-dark alongside .dark
     * unconditionally, so a second rule for it could only restate the first -
     * and restating it is how the two drifted apart in the first place.
     */
    public function test_there_is_a_single_dark_rule(): void
    {
        $loader = $this->loader();

        // The trailing brace matters: `.dark .app-loader__track` and
        // `__progress` are separate rules that share the prefix.
        $this->assertSame(1, substr_count($loader, '.dark .app-loader {'));
        $this->assertStringNotContainsString('.dark.pure-dark .app-loader', $loader);
    }
}
