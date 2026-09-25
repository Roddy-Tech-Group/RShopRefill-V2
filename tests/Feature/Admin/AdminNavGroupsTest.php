<?php

namespace Tests\Feature\Admin;

use Tests\TestCase;

/**
 * The admin sidebar's expandable nav groups (User Management, Management,
 * Content) derive their open state from the live URL and re-sync on every
 * wire:navigate, so a group can't stay stuck open after moving to another
 * page (which made several dropdowns appear to auto-open at once).
 *
 * Products is no longer a group: the catalogue page carries its own category
 * picker, so the sidebar links to it once, as "Catalogue".
 */
class AdminNavGroupsTest extends TestCase
{
    public function test_each_nav_group_resyncs_open_state_on_navigation(): void
    {
        $blade = file_get_contents(resource_path('views/components/layouts/admin.blade.php'));

        // All three groups re-sync on navigation. Must use x-on:livewire:navigated
        // (NOT @livewire:navigated, which Blade parses as the @livewire directive).
        $this->assertSame(3, substr_count($blade, 'x-on:livewire:navigated.window="sync()"'));
        $this->assertStringNotContainsString('@livewire:navigated', $blade);

        // Open state is derived from the live URL per group.
        $this->assertStringContainsString("window.location.pathname.includes('/admin/content')", $blade);
        // Management opens for its own pages AND the Payment Insights page it hosts.
        $this->assertStringContainsString("['/admin/management', '/admin/payment-insights']", $blade);
        // User Management opens for the customers list and every users.* page.
        $this->assertStringContainsString("['/admin/customers', '/admin/users']", $blade);

        // The old fragile per-group state that could stay stuck open is gone.
        $this->assertStringNotContainsString('x-show="expanded"', $blade);
        $this->assertStringNotContainsString('expanded = ! expanded', $blade);
    }

    /** The catalogue is one link, not a group repeating the page's own category picker. */
    public function test_the_catalogue_is_a_single_link(): void
    {
        $blade = file_get_contents(resource_path('views/components/layouts/admin.blade.php'));

        $this->assertStringContainsString('data-tip="Catalogue"', $blade);
        $this->assertStringContainsString("['label' => 'Catalogue',", $blade);
        $this->assertStringNotContainsString('All Products', $blade);
        $this->assertStringNotContainsString("window.location.pathname.includes('/admin/products')", $blade);
    }
}
