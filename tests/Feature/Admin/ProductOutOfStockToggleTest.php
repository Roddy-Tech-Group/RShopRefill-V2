<?php

namespace Tests\Feature\Admin;

use App\Domain\Admin\Enums\AdminRole;
use App\Models\Admin;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The product drawer's "Out of stock" switch flips products.is_out_of_stock via the
 * out-of-stock endpoint. Unlike "Live on website" (is_active), out-of-stock keeps the
 * product visible but greyed and non-purchasable.
 */
class ProductOutOfStockToggleTest extends TestCase
{
    use RefreshDatabase;

    private function asAdmin(): self
    {
        $admin = Admin::firstOrCreate(
            ['email' => 'stock-admin@example.test'],
            ['name' => 'Stock Admin', 'password' => 'password', 'role' => AdminRole::SuperAdmin, 'is_active' => true],
        );
        $this->actingAs($admin, 'admin');

        return $this;
    }

    private function makeProduct(): Product
    {
        $category = Category::factory()->create();

        return Product::factory()->create(['category_id' => $category->id]);
    }

    public function test_admin_can_mark_a_product_out_of_stock_and_back(): void
    {
        $this->asAdmin();
        $product = $this->makeProduct();

        $this->patchJson("/admin/api/catalog/products/{$product->id}/out-of-stock", ['is_out_of_stock' => true])
            ->assertOk()
            ->assertJson(['is_out_of_stock' => true]);
        $this->assertTrue($product->refresh()->is_out_of_stock);

        $this->patchJson("/admin/api/catalog/products/{$product->id}/out-of-stock", ['is_out_of_stock' => false])
            ->assertOk()
            ->assertJson(['is_out_of_stock' => false]);
        $this->assertFalse($product->refresh()->is_out_of_stock);
    }

    public function test_the_flag_is_required(): void
    {
        $this->asAdmin();
        $product = $this->makeProduct();

        $this->patchJson("/admin/api/catalog/products/{$product->id}/out-of-stock", [])
            ->assertStatus(422);
    }

    public function test_a_guest_cannot_mark_a_product_out_of_stock(): void
    {
        $product = $this->makeProduct();

        $this->patch("/admin/api/catalog/products/{$product->id}/out-of-stock", ['is_out_of_stock' => true])
            ->assertNotFound();

        $this->assertFalse($product->refresh()->is_out_of_stock);
    }
}
