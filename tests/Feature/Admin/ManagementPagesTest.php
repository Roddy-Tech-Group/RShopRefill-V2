<?php

namespace Tests\Feature\Admin;

use App\Domain\Admin\Enums\AdminRole;
use App\Models\Admin;
use Database\Seeders\AdminSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ManagementPagesTest extends TestCase
{
    use RefreshDatabase;

    private function asSuperAdmin(): self
    {
        $this->seed(AdminSeeder::class);
        $admin = Admin::firstOrCreate(
            ['email' => 'management-super@example.test'],
            ['name' => 'Management Super', 'password' => 'password', 'role' => AdminRole::SuperAdmin, 'is_active' => true],
        );
        $this->actingAs($admin, 'admin');

        return $this;
    }

    private function asScopedAdmin(): self
    {
        $this->seed(AdminSeeder::class);
        $admin = Admin::firstOrCreate(
            ['email' => 'management-scoped@example.test'],
            ['name' => 'Management Scoped', 'password' => 'password', 'role' => AdminRole::Admin, 'is_active' => true],
        );
        $this->actingAs($admin, 'admin');

        return $this;
    }

    public function test_monitoring_page_renders_for_super_admin(): void
    {
        $this->withoutVite()->asSuperAdmin();

        $this->get(route('admin.management.monitoring'))
            ->assertOk()
            ->assertSee('Money integrity')
            ->assertSee('Ledger integrity')
            ->assertSee('Stuck paid orders')
            ->assertSee('Disk')
            ->assertSee('Balance:')
            ->assertSee('Worst queue wait')
            ->assertSee('Sweeper pickups')
            ->assertSee('zendit')
            ->assertSee('airalo');
    }

    public function test_finances_page_renders_for_super_admin(): void
    {
        $this->withoutVite()->asSuperAdmin();

        $this->get(route('admin.management.finances'))
            ->assertOk()
            ->assertSee('Gross profit')
            ->assertSee('Supplier cost')
            ->assertSee('By product category')
            // Core partners are zero-filled line items even with no data at all.
            ->assertSee('Zendit')
            ->assertSee('Airalo')
            ->assertSee('Since launch');
    }

    public function test_leaderboards_page_renders_for_super_admin(): void
    {
        $this->withoutVite()->asSuperAdmin();

        $this->get(route('admin.management.leaderboards'))
            ->assertOk()
            ->assertSee('Top Customers')
            ->assertSee('Most Failed Transactions')
            ->assertSee('Highest Wallet Balance')
            ->assertSee('Most Referrals');
    }

    public function test_runbooks_index_lists_the_library(): void
    {
        $this->withoutVite()->asSuperAdmin();

        $this->get(route('admin.management.runbooks'))
            ->assertOk()
            ->assertSee('Payment provider failure')
            ->assertSee('Database restore')
            ->assertSee('Security breach');
    }

    public function test_a_single_runbook_renders_its_markdown(): void
    {
        $this->withoutVite()->asSuperAdmin();

        $this->get(route('admin.management.runbooks', 'fx-failure'))
            ->assertOk()
            ->assertSee('Wrong rates are worse than stale rates')
            ->assertSee('rates:fetch');
    }

    public function test_an_unknown_runbook_slug_404s(): void
    {
        $this->withoutVite()->asSuperAdmin();

        $this->get(route('admin.management.runbooks', 'not-a-runbook'))->assertNotFound();
    }

    public function test_the_risk_handbook_is_listed_in_the_library(): void
    {
        $this->withoutVite()->asSuperAdmin();

        $this->get(route('admin.management.runbooks'))
            ->assertOk()
            ->assertSee('Fraud &amp; Risk Engine Handbook', false)
            ->assertSee(route('admin.management.runbooks.doc', 'risk-and-fraud-engine'), false);
    }

    public function test_the_risk_handbook_doc_serves_the_html_as_authored(): void
    {
        $this->withoutVite()->asSuperAdmin();

        $this->get(route('admin.management.runbooks.doc', 'risk-and-fraud-engine'))
            ->assertOk()
            ->assertHeader('Content-Type', 'text/html; charset=utf-8')
            ->assertSee('Fraud &amp; Risk Engine', false);
    }

    public function test_visiting_the_handbook_through_the_library_route_redirects_to_the_doc(): void
    {
        $this->withoutVite()->asSuperAdmin();

        $this->get(route('admin.management.runbooks', 'risk-and-fraud-engine'))
            ->assertRedirect(route('admin.management.runbooks.doc', 'risk-and-fraud-engine'));
    }

    public function test_an_unknown_doc_slug_404s(): void
    {
        $this->withoutVite()->asSuperAdmin();

        $this->get(route('admin.management.runbooks.doc', 'not-a-doc'))->assertNotFound();
    }

    public function test_management_pages_require_admin_auth(): void
    {
        $this->get(route('admin.management.monitoring'))->assertNotFound();
        $this->get(route('admin.management.finances'))->assertNotFound();
        $this->get(route('admin.management.leaderboards'))->assertNotFound();
        $this->get(route('admin.management.runbooks'))->assertNotFound();
        $this->get(route('admin.management.runbooks.doc', 'risk-and-fraud-engine'))->assertNotFound();
    }

    public function test_management_pages_are_denied_to_admins_without_the_management_section(): void
    {
        $this->withoutVite()->asScopedAdmin();

        // The Admin role's default permission set deliberately excludes the
        // CTO-level `management` section.
        $this->get(route('admin.management.monitoring'))->assertNotFound();
        $this->get(route('admin.management.finances'))->assertNotFound();
        $this->get(route('admin.management.runbooks.doc', 'risk-and-fraud-engine'))->assertNotFound();
        $this->get(route('admin.payment-insights'))->assertNotFound();
    }

    public function test_a_scoped_admin_granted_management_can_access_the_pages(): void
    {
        $this->withoutVite()->seed(AdminSeeder::class);
        $admin = Admin::firstOrCreate(
            ['email' => 'management-granted@example.test'],
            [
                'name' => 'Management Granted',
                'password' => 'password',
                'role' => AdminRole::Admin,
                'is_active' => true,
                'permissions' => ['management'],
            ],
        );
        $this->actingAs($admin, 'admin');

        $this->get(route('admin.management.leaderboards'))->assertOk();
        $this->get(route('admin.payment-insights'))->assertOk();
    }
}
