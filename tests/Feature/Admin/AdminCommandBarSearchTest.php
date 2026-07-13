<?php

namespace Tests\Feature\Admin;

use App\Domain\Admin\Enums\AdminRole;
use App\Models\Admin;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The admin header command bar promises "products, orders, customers". Its
 * suggest endpoint must return typed rows so a customer hit links straight to
 * that customer's page (it used to only search products).
 */
class AdminCommandBarSearchTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): Admin
    {
        return Admin::create([
            'name' => 'Test Admin',
            'email' => 'admin@example.test',
            'password' => 'password',
            'role' => AdminRole::SuperAdmin,
            'is_active' => true,
        ]);
    }

    public function test_search_finds_customers_by_name_and_email(): void
    {
        $admin = $this->admin();
        User::factory()->create(['name' => 'Jane Searchable', 'email' => 'jane@example.test']);

        $this->actingAs($admin, 'admin')
            ->getJson(route('admin.search-suggest', ['q' => 'Searchable']))
            ->assertOk()
            ->assertJsonFragment(['type' => 'customer', 'name' => 'Jane Searchable']);

        $this->actingAs($admin, 'admin')
            ->getJson(route('admin.search-suggest', ['q' => 'jane@example']))
            ->assertOk()
            ->assertJsonFragment(['email' => 'jane@example.test']);
    }

    public function test_customer_hit_links_to_the_customer_page(): void
    {
        $user = User::factory()->create(['name' => 'Linkable Person']);

        $this->actingAs($this->admin(), 'admin')
            ->getJson(route('admin.search-suggest', ['q' => 'Linkable']))
            ->assertOk()
            ->assertJsonFragment(['url' => route('admin.customer', $user)]);
    }

    public function test_search_still_finds_products(): void
    {
        $category = Category::create(['name' => 'eSIMs', 'slug' => 'esims', 'type' => 'digital']);
        $product = Product::create([
            'category_id' => $category->id,
            'provider_name' => 'airalo',
            'country_code' => 'US',
            'currency_code' => 'USD',
            'name' => 'Findable Product eSIM',
            'slug' => 'findable-product-esim',
            'is_active' => true,
        ]);
        ProductVariant::factory()->for($product)->create([
            'currency' => 'USD',
            'face_value' => 5,
            'retail_price' => 5,
            'cost_price' => 3,
            'is_available' => true,
            'is_variable' => false,
        ]);

        $this->actingAs($this->admin(), 'admin')
            ->getJson(route('admin.search-suggest', ['q' => 'Findable']))
            ->assertOk()
            ->assertJsonFragment(['type' => 'product', 'name' => 'Findable Product eSIM']);
    }

    public function test_short_query_returns_empty(): void
    {
        User::factory()->create(['name' => 'Jane']);

        $this->actingAs($this->admin(), 'admin')
            ->getJson(route('admin.search-suggest', ['q' => 'j']))
            ->assertOk()
            ->assertExactJson([]);
    }

    public function test_endpoint_requires_admin_authentication(): void
    {
        $this->get(route('admin.search-suggest', ['q' => 'test']))
            ->assertNotFound();
    }
}
