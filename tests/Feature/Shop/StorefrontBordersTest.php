<?php

namespace Tests\Feature\Shop;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * One outline colour across the customer-facing site: #dee3e1.
 *
 * It is a CSS remap, not a rewrite of the templates. The zinc border/ring
 * tokens appear roughly 400 times across the storefront views, so editing them
 * by hand would be a large diff with no way to prove nothing was missed - and
 * the next colour change would mean doing it all again.
 */
class StorefrontBordersTest extends TestCase
{
    use RefreshDatabase;

    private function css(): string
    {
        return File::get(resource_path('css/app.css'));
    }

    /** The remap needs a scope hook, and only the storefront body carries it. */
    public function test_only_the_storefront_body_is_in_scope(): void
    {
        $storefront = File::get(resource_path('views/components/layouts/app/header.blade.php'));
        $this->assertStringContainsString('<body class="storefront flex min-h-screen', $storefront);

        // Checked on the <body> tag itself: both layouts mention the word
        // "storefront" elsewhere in prose, which is not the scope hook.
        foreach (['dashboard', 'admin'] as $layout) {
            $blade = File::get(resource_path('views/components/layouts/'.$layout.'.blade.php'));

            preg_match('/<body[^>]*>/', $blade, $m);
            $this->assertNotEmpty($m, $layout.' has no <body> tag.');
            $this->assertStringNotContainsString('storefront', $m[0], $layout.' must stay out of scope.');
        }

        $html = $this->withoutVite()->get('/')->assertOk()->getContent();
        $this->assertStringContainsString('<body class="storefront ', $html);
    }

    public function test_every_border_and_ring_token_is_remapped(): void
    {
        $css = $this->css();

        foreach ([100, 200, 300, 400] as $step) {
            $this->assertStringContainsString(
                'html:not(.dark) body.storefront .border-zinc-'.$step,
                $css,
                'border-zinc-'.$step.' is not remapped.'
            );
            $this->assertStringContainsString(
                'html:not(.dark) body.storefront .ring-zinc-'.$step,
                $css,
                'ring-zinc-'.$step.' is not remapped.'
            );
        }

        $this->assertStringContainsString('border-color: #dee3e1; }', $css);
        $this->assertStringContainsString('--tw-ring-color: #dee3e1; }', $css);
    }

    /**
     * Every token the storefront views actually use has to be covered, or a
     * stray zinc edge is left sitting next to the remapped ones.
     */
    public function test_no_storefront_token_is_left_behind(): void
    {
        $css = $this->css();
        $used = [];

        foreach ([
            'welcome.blade.php',
            'shop',
            'components/home',
            'components/nav',
            'components/footer.blade.php',
            'components/shop',
        ] as $path) {
            $full = resource_path('views/'.$path);
            $files = is_dir($full) ? File::allFiles($full) : [new \SplFileInfo($full)];

            foreach ($files as $file) {
                preg_match_all(
                    '/(?:hover:)?(?:ring|border|divide)-zinc-(?:100|200|300|400|500|600)/',
                    File::get($file->getPathname()),
                    $m
                );
                $used = array_merge($used, $m[0]);
            }
        }

        foreach (array_unique($used) as $token) {
            $escaped = str_replace(':', '\:', $token);
            $this->assertStringContainsString(
                $escaped,
                $css,
                $token.' is used in the storefront but has no remap.'
            );
        }
    }

    /**
     * The footer slab is itself #dee3e1 in light, so the shared outline would
     * disappear on it. Its hairlines step one shade down instead.
     */
    public function test_the_footer_keeps_a_visible_hairline_on_its_own_slab(): void
    {
        $css = $this->css();

        $this->assertStringContainsString(
            'html:not(.dark) body.storefront footer.footer-ground .border-zinc-200 { border-color: #c9d0cd; }',
            $css
        );
        $this->assertStringContainsString('background: #dee3e1;', $css);
    }

    /** Dark keeps its own low-alpha hairlines - #dee3e1 there is a bright line. */
    public function test_the_remap_is_light_only(): void
    {
        // Comments quote selectors in prose - the hero-glow note a few hundred
        // lines down quotes this very remap - so strip them first. This scans
        // the stylesheet's rules, not its notes.
        $rules = preg_replace('#/\*.*?\*/#s', '', $this->css());

        foreach (explode("\n", $rules) as $line) {
            if (str_contains($line, 'body.storefront .') || str_contains($line, 'body.storefront footer')) {
                $this->assertStringStartsWith(
                    'html:not(.dark)',
                    trim($line),
                    'Storefront border remap leaked out of light mode: '.trim($line)
                );
            }
        }
    }
}
