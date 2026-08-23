<?php

namespace Tests\Feature\Admin;

use App\Domain\Admin\Enums\AdminRole;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The admin mobile menu (bottom-sheet card grid) mirrors the desktop sidebar:
 * every sidebar destination has a tile, filtered by the same per-admin
 * permission check, so nothing is unreachable on a phone and nothing leaks to
 * admins who cannot open it.
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

        foreach ([
            'Trades Queue', 'Trade Brands', 'Trade Rates',
            'Campaigns', 'Pricing Rules',
            'Hero Sections', 'Hero Cards', 'Blog Posts', 'Press', 'Reviews', 'FAQs',
            'Rcoin Rewards', 'Rcoin Analytics', 'Rcoin Payouts',
            'API Settings', 'Audit Log',
        ] as $label) {
            $this->assertStringContainsString($label, $html, "Mobile menu is missing the {$label} tile.");
        }
    }

    public function test_mobile_menu_respects_the_admins_permissions(): void
    {
        // Moderator preset: content + support tickets + newsletter only.
        $this->actingAs($this->admin(AdminRole::Moderator), 'admin');

        $html = $this->withoutVite()->get(route('admin.support-tickets'))->assertOk()->getContent();

        $this->assertStringContainsString('Blog Posts', $html);
        $this->assertStringContainsString('Support', $html);

        // Sections outside the preset must not appear as tiles.
        $this->assertStringNotContainsString('Trades Queue', $html);
        $this->assertStringNotContainsString('API Settings', $html);
    }
}
