<?php

namespace Tests\Feature\Admin;

use App\Domain\Admin\Enums\AdminRole;
use App\Models\Admin;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The product drawer's "Live on website" switch flips products.is_active via the
 * toggle-active endpoint. Off pulls the whole product from the storefront, which
 * filters every listing on is_active.
 */
class ProductActiveToggleTest extends TestCase
{
    use RefreshDatabase;

    private function asAdmin(): self
    {
        $admin = Admin::firstOrCreate(
            ['email' => 'active-admin@example.test'],
            ['name' => 'Active Admin', 'password' => 'password', 'role' => AdminRole::SuperAdmin, 'is_active' => true],
        );
        $this->actingAs($admin, 'admin');

        return $this;
    }

    private function makeProduct(bool $isActive = true): Product
    {
        $category = Category::factory()->create();

        return Product::factory()->create(['category_id' => $category->id, 'is_active' => $isActive]);
    }

    public function test_admin_can_turn_a_product_off_and_back_on(): void
    {
        $this->asAdmin();
        $product = $this->makeProduct(isActive: true);

        $this->patchJson("/admin/api/catalog/products/{$product->id}/toggle-active")
            ->assertOk()
            ->assertJson(['is_active' => false]);
        $this->assertFalse($product->refresh()->is_active);

        $this->patchJson("/admin/api/catalog/products/{$product->id}/toggle-active")
            ->assertOk()
            ->assertJson(['is_active' => true]);
        $this->assertTrue($product->refresh()->is_active);
    }

    public function test_a_guest_cannot_toggle_a_product(): void
    {
        $product = $this->makeProduct(isActive: true);

        // The admin guard redirects a guest to the login screen rather than
        // letting the toggle through.
        $this->patch("/admin/api/catalog/products/{$product->id}/toggle-active")
            ->assertRedirect();

        $this->assertTrue($product->refresh()->is_active);
    }
}
