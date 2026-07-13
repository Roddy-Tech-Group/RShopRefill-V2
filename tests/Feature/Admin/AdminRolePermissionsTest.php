<?php

namespace Tests\Feature\Admin;

use App\Domain\Admin\Enums\AdminRole;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Admin section permissions. The role is a preset; the per-admin `permissions`
 * set is authoritative. Super Admin always has everything; a Moderator is
 * scoped to its granted sections; route access is enforced by AdminAuth and
 * mirrored in the sidebar.
 */
class AdminRolePermissionsTest extends TestCase
{
    use RefreshDatabase;

    private function admin(AdminRole $role, ?array $permissions = null): Admin
    {
        return Admin::create([
            'name' => ucfirst($role->value),
            'email' => $role->value.'-'.uniqid().'@example.test',
            'password' => 'password',
            'role' => $role,
            'permissions' => $permissions,
            'is_active' => true,
        ]);
    }

    public function test_super_admin_can_access_every_section(): void
    {
        $admin = $this->admin(AdminRole::SuperAdmin);

        foreach (array_keys(Admin::SECTIONS) as $section) {
            $this->assertTrue($admin->canAccessSection($section), "super admin denied {$section}");
        }
        $this->assertTrue($admin->canAccessAdminRoute('admin.api-settings'));
        $this->assertTrue($admin->canAccessAdminRoute('admin.admins'));
    }

    public function test_moderator_default_preset_is_content_tickets_newsletter(): void
    {
        $admin = $this->admin(AdminRole::Moderator); // null permissions -> role preset

        $this->assertTrue($admin->canAccessAdminRoute('admin.content.blog'));
        $this->assertTrue($admin->canAccessAdminRoute('admin.support-tickets'));
        $this->assertTrue($admin->canAccessAdminRoute('admin.newsletter'));

        // Denied everywhere else.
        $this->assertFalse($admin->canAccessAdminRoute('admin.products'));
        $this->assertFalse($admin->canAccessAdminRoute('admin.pricing-rules'));
        $this->assertFalse($admin->canAccessAdminRoute('admin.admins'));
        $this->assertFalse($admin->canAccessAdminRoute('admin.api-settings'));
    }

    public function test_dashboard_and_account_are_always_allowed(): void
    {
        $admin = $this->admin(AdminRole::Moderator);

        $this->assertTrue($admin->canAccessAdminRoute('admin.dashboard'));
        $this->assertTrue($admin->canAccessAdminRoute('admin.account'));
        $this->assertTrue($admin->canAccessAdminRoute('admin.account-activity'));
    }

    public function test_custom_permissions_override_the_role_preset(): void
    {
        // A Moderator explicitly granted Orders + Customers.
        $admin = $this->admin(AdminRole::Moderator, ['orders', 'customers']);

        $this->assertTrue($admin->canAccessAdminRoute('admin.orders'));
        $this->assertTrue($admin->canAccessAdminRoute('admin.customers'));
        $this->assertTrue($admin->canAccessAdminRoute('admin.api.commerce.orders'));
        // No longer has the preset sections, since the saved set is authoritative.
        $this->assertFalse($admin->canAccessAdminRoute('admin.newsletter'));
    }

    public function test_admin_role_preset_excludes_admins_and_integrations(): void
    {
        $admin = $this->admin(AdminRole::Admin);

        $this->assertTrue($admin->canAccessAdminRoute('admin.orders'));
        $this->assertTrue($admin->canAccessAdminRoute('admin.pricing-rules'));
        $this->assertFalse($admin->canAccessAdminRoute('admin.admins'));
        $this->assertFalse($admin->canAccessAdminRoute('admin.api-settings'));
    }

    public function test_middleware_blocks_a_moderator_from_a_denied_page(): void
    {
        $this->withoutVite();
        $moderator = $this->admin(AdminRole::Moderator);

        $this->actingAs($moderator, 'admin')
            ->get(route('admin.pricing-rules'))
            ->assertNotFound();

        // An allowed page renders fine.
        $this->actingAs($moderator, 'admin')
            ->get(route('admin.support-tickets'))
            ->assertOk();
    }

    public function test_api_route_returns_404_for_denied_role(): void
    {
        $moderator = $this->admin(AdminRole::Moderator);

        $this->actingAs($moderator, 'admin')
            ->getJson(route('admin.api.commerce.orders'))
            ->assertNotFound();
    }

    public function test_admins_page_is_super_admin_only(): void
    {
        $this->withoutVite();

        // A non-super admin granted the 'admins' section still cannot open the
        // management page - it is hard-gated to Super Admin in the component.
        $admin = $this->admin(AdminRole::Admin, ['admins']);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.admins'))
            ->assertNotFound();
    }

    public function test_unknown_route_defaults_to_super_admin_only(): void
    {
        $moderator = $this->admin(AdminRole::Moderator);
        $superAdmin = $this->admin(AdminRole::SuperAdmin);

        $this->assertFalse($moderator->canAccessAdminRoute('admin.some-future-page'));
        $this->assertTrue($superAdmin->canAccessAdminRoute('admin.some-future-page'));
    }
}
