<?php

namespace Tests\Feature\Admin;

use App\Domain\Admin\Enums\AdminRole;
use App\Domain\Fulfillment\Enums\FulfillmentStatus;
use App\Domain\Order\Services\OrderService;
use App\Jobs\FulfillOrderItemJob;
use App\Models\Admin;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Subcategory;
use Database\Seeders\AdminSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

/**
 * A failed item that was already refunded must never be re-fulfilled: the
 * customer got their money back, so a successful retry would hand over goods
 * that were already paid back. The admin retry endpoint refuses those, with
 * an explanation, instead of quietly double-giving.
 */
class RetryFulfillmentGuardTest extends TestCase
{
    use RefreshDatabase;

    private function asSuperAdmin(): void
    {
        $this->seed(AdminSeeder::class);
        $admin = Admin::firstOrCreate(
            ['email' => 'retry-guard@example.test'],
            ['name' => 'Retry Guard', 'password' => 'password', 'role' => AdminRole::SuperAdmin, 'is_active' => true],
        );
        $this->actingAs($admin, 'admin');
    }

    private function makeFailedItem(): OrderItem
    {
        $category = Category::create(['name' => 'Gift Cards', 'slug' => 'gift-cards', 'type' => 'digital']);
        $subcategory = Subcategory::create(['category_id' => $category->id, 'name' => 'Cards', 'slug' => 'cards']);
        $product = Product::factory()->create(['category_id' => $category->id, 'subcategory_id' => $subcategory->id, 'provider_name' => 'zendit']);
        $variant = ProductVariant::factory()->create(['product_id' => $product->id]);
        $order = Order::factory()->create();

        return OrderItem::factory()->create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'category_id' => $category->id,
            'subcategory_id' => $subcategory->id,
            'fulfillment_status' => FulfillmentStatus::Failed,
            'failed_at' => now(),
        ]);
    }

    public function test_a_refunded_item_cannot_be_retried(): void
    {
        $this->asSuperAdmin();
        Bus::fake();

        $item = $this->makeFailedItem();
        app(OrderService::class)->recordRefundedAmount($item->order, 9.69, "item-{$item->id}");

        $this->post(route('admin.api.commerce.retry-fulfillment', $item->id))
            ->assertStatus(409)
            ->assertJsonFragment(['message' => 'This item was already refunded to the customer. Retrying would deliver goods that were paid back; the customer can re-order using their wallet credit instead.']);

        Bus::assertNotDispatched(FulfillOrderItemJob::class);
    }

    public function test_a_fully_refunded_order_blocks_retries_on_its_items(): void
    {
        $this->asSuperAdmin();
        Bus::fake();

        $item = $this->makeFailedItem();
        app(OrderService::class)->recordRefundedAmount($item->order, 19.38, 'final-'.$item->order->id);

        $this->post(route('admin.api.commerce.retry-fulfillment', $item->id))->assertStatus(409);

        Bus::assertNotDispatched(FulfillOrderItemJob::class);
    }

    public function test_an_unrefunded_failed_item_can_still_be_retried(): void
    {
        $this->asSuperAdmin();
        Bus::fake();

        $item = $this->makeFailedItem();

        $this->post(route('admin.api.commerce.retry-fulfillment', $item->id))->assertOk();

        Bus::assertDispatched(FulfillOrderItemJob::class);
    }
}
