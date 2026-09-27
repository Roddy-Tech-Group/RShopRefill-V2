<?php

namespace Tests\Feature\Admin;

use App\Domain\Admin\Enums\AdminRole;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Admin reads in the same ink as the storefront.
 *
 * app.css carries a light-mode ink scale that replaces Tailwind's stock zinc
 * greys with the brand navy: zinc-950/900/800 become #16223c, zinc-700/600
 * become #243049, zinc-500 becomes #3d4a63. It was scoped to `body.storefront`
 * only, so admin was the one surface still painting flat grey text while the
 * rest of the product used navy.
 *
 * The scale hangs off a body class, which is why the admin layout carries
 * `admin` the way the storefront carries `storefront`.
 */
class AdminInkScaleTest extends TestCase
{
    use RefreshDatabase;

    private function css(): string
    {
        return File::get(resource_path('css/app.css'));
    }

    /** The hook the scale needs. Remove it and every rule below stops matching. */
    public function test_the_admin_body_carries_its_marker_class(): void
    {
        $layout = File::get(resource_path('views/components/layouts/admin.blade.php'));

        $this->assertMatchesRegularExpression(
            '/<body[^>]*class="admin /',
            $layout,
            'the admin body needs the `admin` class for the ink scale to apply'
        );
    }

    /** Headings and body copy take the same navy the storefront uses. */
    public function test_admin_shares_the_storefront_ink_values(): void
    {
        $css = $this->css();

        foreach ([
            ['.text-zinc-900', '#16223c'],
            ['.text-zinc-600', '#243049'],
            ['.text-zinc-500', '#3d4a63'],
        ] as [$class, $hex]) {
            $this->assertMatchesRegularExpression(
                '/html:not\(\.dark\) body\.admin '.preg_quote($class, '/').'[^{]*\{[^}]*'.preg_quote($hex, '/').'/s',
                $css,
                "admin {$class} should resolve to {$hex}, the same as the storefront"
            );
        }
    }

    /**
     * Light only. The scale is a light-mode remap; dark mode has its own
     * treatment and must not be caught by these selectors.
     */
    public function test_the_scale_is_scoped_to_light_mode(): void
    {
        $css = $this->css();

        preg_match_all('/^[^\n]*body\.admin \.text-zinc-[0-9]+[^\n]*$/m', $css, $matches);

        $this->assertNotEmpty($matches[0]);

        foreach ($matches[0] as $line) {
            $this->assertStringContainsString(
                'html:not(.dark)',
                $line,
                'every admin ink selector must be light-mode scoped'
            );
        }
    }

    /** The page still renders with the marker class in place. */
    public function test_the_admin_dashboard_renders_with_the_marker_class(): void
    {
        $admin = Admin::create([
            'name' => 'Ink Tester',
            'email' => 'ink@example.test',
            'password' => 'password',
            'role' => AdminRole::SuperAdmin,
            'is_active' => true,
        ]);

        $page = $this->actingAs($admin, 'admin')
            ->withoutVite()
            ->get('/admin/dashboard')
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('class="admin ', $page);
    }
}
