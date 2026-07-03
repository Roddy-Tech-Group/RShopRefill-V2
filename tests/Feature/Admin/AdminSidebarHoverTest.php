<?php

namespace Tests\Feature\Admin;

use App\Domain\Admin\Enums\AdminRole;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Admin sidebar items must show a visible hover. The old hover bg (#0a1729) was
 * darker than the sidebar and the pure-dark rule remapped it to #000 (identical
 * to the black sidebar) - so hovering did nothing in dark/extra-dark. The hover
 * now lifts to the chip navy (#26416b → #1f1f1f in extra-dark).
 */
class AdminSidebarHoverTest extends TestCase
{
    use RefreshDatabase;

    public function test_sidebar_items_use_a_visible_hover_lift(): void
    {
        $admin = Admin::firstOrCreate(
            ['email' => 'sidebar-admin@example.test'],
            ['name' => 'Sidebar Admin', 'password' => 'password', 'role' => AdminRole::SuperAdmin, 'is_active' => true],
        );

        $response = $this->actingAs($admin, 'admin')->get(route('admin.dashboard'))->assertOk();

        $response->assertSee('dark:hover:bg-[#26416b]', false);
        $response->assertDontSee('dark:hover:bg-[#0a1729]', false);
    }
}
