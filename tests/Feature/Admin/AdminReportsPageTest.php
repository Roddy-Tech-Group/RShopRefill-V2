<?php

namespace Tests\Feature\Admin;

use App\Domain\Admin\Enums\AdminRole;
use App\Models\Admin;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Subcategory;
use App\Models\User;
use Database\Seeders\AdminSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminReportsPageTest extends TestCase
{
    use RefreshDatabase;

    private function asAdmin(): self
    {
        $this->seed(AdminSeeder::class);
        $admin = Admin::firstOrCreate(
            ['email' => 'test-reports@example.test'],
            ['name' => 'Reports Admin', 'password' => 'password', 'role' => AdminRole::SuperAdmin, 'is_active' => true],
        );
        $this->actingAs($admin, 'admin');

        return $this;
    }

    public function test_reports_page_renders_for_admin(): void
    {
        $this->withoutVite()->asAdmin();

        $this->get(route('admin.reports'))
            ->assertOk()
            ->assertSee('Sales Overview by Date')
            ->assertSee('Transactions')
            ->assertSee('Total sales')
            ->assertSee('Profit Margin');
    }

    public function test_reports_page_requires_admin_auth(): void
    {
        $this->get(route('admin.reports'))->assertNotFound();
    }

    public function test_csv_export_returns_a_csv_with_header_row(): void
    {
        $this->asAdmin();

        $response = $this->get(route('admin.reports.export', ['preset' => 'today']));

        $response->assertOk();
        $this->assertStringContainsString('text/csv', $response->headers->get('Content-Type'));
        $body = $response->streamedContent();
        $this->assertStringContainsString('Date,Transactions,"Cost (USD)","Total Sales (USD)","Profit (USD)","Profit Margin (%)","Avg per Tx (USD)"', $body);
    }

    public function test_csv_export_includes_completed_orders_in_the_window(): void
    {
        $this->asAdmin();
        $user = User::factory()->create();

        $order = Order::create([
            'user_id' => $user->id,
            'order_number' => 'RSR-TEST-1',
            'cart_id' => null,
            'settlement_currency' => 'USD',
            'display_currency' => 'USD',
            'subtotal_amount' => 12.34,
            'markup_amount' => 1.66,
            'total_amount' => 14.00,
            'payment_method' => 'wallet',
            'payment_status' => 'paid',
            'fulfillment_status' => 'fulfilled',
            'order_status' => 'completed',
            'placed_at' => now(),
            'completed_at' => now(),
            'metadata' => ['exchange_rate' => 1.0, 'settlement_total_usd' => 14.00, 'settlement_subtotal_usd' => 12.34],
        ]);

        $category = Category::factory()->create();
        $product = Product::factory()->create([
            'category_id' => $category->id,
        ]);
        $variant = ProductVariant::factory()->create([
            'product_id' => $product->id,
        ]);

        $subcategory = Subcategory::factory()->create([
            'category_id' => $category->id,
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'category_id' => $category->id,
            'subcategory_id' => $subcategory->id,
            'provider_name' => 'test',
            'quantity' => 1,
            'display_currency' => 'USD',
            'display_amount' => 14.00,
            'provider_cost_usd' => 10.00,
            'markup_amount' => 1.66,
            'subtotal_amount' => 14.00,
            'fulfillment_status' => 'fulfilled',
        ]);

        $response = $this->get(route('admin.reports.export', ['preset' => 'today']));

        $response->assertOk();
        $body = $response->streamedContent();
        // 14.00 sales, 10.00 cost, 4.00 profit for today's bucket
        $this->assertMatchesRegularExpression('/,1,10\.00,14\.00,4\.00,/', $body);
    }
}
