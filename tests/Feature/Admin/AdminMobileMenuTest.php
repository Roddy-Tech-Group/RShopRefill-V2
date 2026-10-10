<?php

namespace Tests\Feature\Admin;

use App\Domain\Admin\Enums\AdminRole;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The admin mobile menu is the same left-slide drawer the customer dashboard
 * has, carrying the desktop sidebar's rows in its order and groups, filtered
 * by the same per-admin permission check, so nothing is unreachable on a
 * phone and nothing leaks to admins who cannot open it.
 */
class AdminMobileMenuTest extends TestCase
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

    public function test_super_admin_mobile_menu_carries_every_sidebar_destination(): void
    {
        $this->actingAs($this->admin(AdminRole::SuperAdmin), 'admin');

        $html = $this->withoutVite()->get(route('admin.dashboard'))->assertOk()->getContent();
        $drawer = $this->drawer($html);

        foreach ([
            'Operations', 'Manage eSIMs', 'User Management', 'Leaderboards', 'Risk',
            'Management', 'Monitoring', 'Payment Insights', 'Gateway settings', 'Finances', 'Runbooks',
            'Push Campaigns', 'Pricing Rules',
            'Hero Sections', 'Blog Posts', 'Press Articles', 'Reviews', 'FAQs',
            'Rcoin Rewards', 'Rcoin Analytics', 'Rcoin Withdrawals',
            'API &amp; Integrations', 'Audit Log',
        ] as $label) {
            $this->assertStringContainsString($label, $drawer, "Mobile drawer is missing the {$label} row.");
        }
    }

    public function test_mobile_menu_respects_the_admins_permissions(): void
    {
        // Moderator preset: content + support tickets + newsletter only.
        $this->actingAs($this->admin(AdminRole::Moderator), 'admin');

        $html = $this->withoutVite()->get(route('admin.support-tickets'))->assertOk()->getContent();

        $drawer = $this->drawer($html);
        $this->assertStringContainsString('Blog Posts', $drawer);
        $this->assertStringContainsString('Support Tickets', $drawer);

        // Sections outside the preset must not appear as rows.
        $this->assertStringNotContainsString('Transactions', $drawer);
        $this->assertStringNotContainsString('API &amp; Integrations', $drawer);
        $this->assertStringNotContainsString('Gateway settings', $drawer);
        // Content is theirs; Rcoin is a separate grant they do not hold.
        $this->assertStringNotContainsString('Rcoin', $drawer);
    }

    /** The drawer is the dashboard's: same panel, same open event, no bottom-sheet grid left. */
    public function test_the_admin_drawer_is_the_dashboards_drawer(): void
    {
        $this->actingAs($this->admin(AdminRole::SuperAdmin), 'admin');

        $html = $this->withoutVite()->get(route('admin.management.gateways'))->assertOk()->getContent();
        $drawer = $this->drawer($html);

        $this->assertStringContainsString('class="dash-drawer fixed inset-y-0 left-0 z-[70] flex w-[min(69vw,270px)] flex-col"', $drawer);
        $this->assertStringContainsString('x-on:open-mobile-menu.window="$nextTick(() => menuOpen = true)"', $html);
        $this->assertStringNotContainsString('Admin menu</h2>', $html);
        $this->assertStringNotContainsString('grid grid-cols-4 gap-3', $drawer);

        // The current page's group starts open and its row is the current one.
        $this->assertStringContainsString('x-data="{ groupOpen: true }"', $drawer);
        $this->assertMatchesRegularExpression('/dash-drawer-subrow-active"\s+aria-current="page"\s*>\s*<span class="truncate">Gateway settings/', $drawer);

        // The stylesheet paints the admin drawer the same as the dashboard's.
        $css = file_get_contents(resource_path('css/app.css'));
        $this->assertStringContainsString(':is(body.dashboard, body.admin) .dash-drawer {', $css);
    }

    private function drawer(string $html): string
    {
        $start = strpos($html, 'class="dash-drawer fixed');
        $this->assertNotFalse($start, 'the mobile drawer is missing');
        $end = strpos($html, '</aside>', $start);

        return substr($html, $start, $end - $start);
    }
}
