<?php

namespace Tests\Feature\Admin;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * The admin rail restores its collapsed state before the first paint.
 *
 * Both layouts persist the collapse to localStorage through their Alpine
 * store, but only the customer dashboard read it back early. The admin layout
 * therefore painted the full sidebar and folded it once Alpine booted, so every
 * reload flashed the rail open and snapped it shut. The fix mirrors the
 * dashboard: a tiny inline script in the document head, before any markup.
 */
class AdminSidebarPrePaintTest extends TestCase
{
    private function adminLayout(): string
    {
        return File::get(resource_path('views/components/layouts/admin.blade.php'));
    }

    public function test_the_admin_layout_restores_the_collapsed_rail_before_paint(): void
    {
        $layout = $this->adminLayout();

        $this->assertStringContainsString("localStorage.getItem('admin.sidebar.collapsed')", $layout);
        $this->assertStringContainsString("classList.add('admin-sidebar-collapsed')", $layout);
    }

    /** Before the sidebar markup, or it cannot prevent the flash. */
    public function test_the_restore_runs_before_the_sidebar_is_rendered(): void
    {
        $layout = $this->adminLayout();

        $restore = strpos($layout, "localStorage.getItem('admin.sidebar.collapsed')");
        $sidebar = strpos($layout, '<flux:sidebar');

        $this->assertNotFalse($restore, 'the pre-paint restore is missing');
        $this->assertNotFalse($sidebar);
        $this->assertLessThan($sidebar, $restore, 'the restore must come before the sidebar markup');
    }

    /**
     * The inline script and the Alpine store must read the SAME key, or the
     * rail restores from one value and is immediately re-synced from another.
     */
    public function test_the_restore_and_the_alpine_store_share_one_key(): void
    {
        $js = File::get(resource_path('js/app.js'));

        $this->assertStringContainsString("localStorage.getItem('admin.sidebar.collapsed')", $js);
        $this->assertStringContainsString("localStorage.setItem('admin.sidebar.collapsed'", $js);
    }

    /** The behaviour it mirrors must still exist on the customer dashboard. */
    public function test_the_customer_dashboard_still_does_the_same_thing(): void
    {
        $dashboard = File::get(resource_path('views/components/layouts/dashboard.blade.php'));

        $this->assertStringContainsString("localStorage.getItem('dashboard.sidebar.collapsed')", $dashboard);
        $this->assertStringContainsString("classList.add('dashboard-sidebar-collapsed')", $dashboard);
    }

    /** The install dock stays off the admin chrome. */
    public function test_the_admin_layout_carries_no_install_dock(): void
    {
        $this->assertStringNotContainsString('<x-ui.install-app', $this->adminLayout());
    }
}
