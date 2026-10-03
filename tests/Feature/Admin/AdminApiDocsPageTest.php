<?php

namespace Tests\Feature\Admin;

use App\Domain\Admin\Enums\AdminRole;
use App\Models\Admin;
use Database\Seeders\AdminSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The mobile API's contract is browsable by admins and by nobody else.
 */
class AdminApiDocsPageTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): Admin
    {
        $this->seed(AdminSeeder::class);

        return Admin::firstOrCreate(
            ['email' => 'api-docs-admin@example.test'],
            ['name' => 'API Docs Admin', 'password' => 'password', 'role' => AdminRole::SuperAdmin, 'is_active' => true],
        );
    }

    public function test_the_contract_is_not_public(): void
    {
        // AdminAuth hides the panel from guests with a 404 rather than a redirect.
        $this->get(route('admin.management.api-docs'))->assertNotFound();
        $this->get(route('admin.management.api-docs.spec'))->assertNotFound();
    }

    public function test_an_admin_can_read_the_page_and_download_the_spec(): void
    {
        $this->actingAs($this->admin(), 'admin');

        $this->get(route('admin.management.api-docs'))
            ->assertOk()
            ->assertSee('RShopRefill Mobile API')
            ->assertSee('redoc', false)
            // The guide renders from the same README the repository carries.
            ->assertSee('follow', false);

        $this->get(route('admin.management.api-docs.spec'))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/yaml; charset=utf-8')
            ->assertSee('openapi: 3.1.0', false)
            ->assertSee('/payments/{session}/pay', false);
    }
}
