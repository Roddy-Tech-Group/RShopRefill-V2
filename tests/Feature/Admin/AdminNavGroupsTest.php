<?php

namespace Tests\Feature\Admin;

use Tests\TestCase;

/**
 * The admin sidebar's expandable nav groups (Products, Gift Card Trading,
 * Content) derive their open state from the live URL and re-sync on every
 * wire:navigate, so a group can't stay stuck open after moving to another page
 * (which made several dropdowns appear to auto-open at once).
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
        $this->assertStringContainsString("window.location.pathname.includes('/admin/products')", $blade);
        $this->assertStringContainsString("window.location.pathname.includes('/admin/gift-cards')", $blade);
        $this->assertStringContainsString("window.location.pathname.includes('/admin/content')", $blade);

        // The old fragile per-group state that could stay stuck open is gone.
        $this->assertStringNotContainsString('x-show="expanded"', $blade);
        $this->assertStringNotContainsString('expanded = ! expanded', $blade);
    }
}
