<?php

namespace Database\Factories;

use App\Domain\Fulfillment\Enums\EsimStatus;
use App\Domain\Fulfillment\Enums\FulfillmentStatus;
use App\Models\Category;
use App\Models\Esim;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Subcategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Esim>
 */
class EsimFactory extends Factory
{
    protected $model = Esim::class;

    public function definition(): array
    {
        return [
            'order_item_id' => fn () => $this->esimOrderItem()->id,
            'order_id' => fn (array $attributes) => OrderItem::find($attributes['order_item_id'])?->order_id,
            'user_id' => fn (array $attributes) => Order::find($attributes['order_id'])?->user_id,
            'provider' => 'airalo',
            'iccid' => '8944'.fake()->unique()->numerify('################'),
            'package_name' => 'Discover+ 5 GB, 30 days',
            'package_code' => 'airalo_discover-plus-5gb',
            'status' => EsimStatus::Inactive,
            'provider_status' => 'NOT_ACTIVE',
            'data_total_mb' => 5120,
            'data_remaining_mb' => 5120,
            'purchased_at' => now(),
        ];
    }

    /**
     * A delivered eSIM order item with every required catalogue column set.
     * Saved without events so the observer does not record a second eSIM.
     */
    private function esimOrderItem(): OrderItem
    {
        $category = Category::firstOrCreate(['slug' => 'esims'], ['name' => 'eSIMs', 'type' => 'digital', 'is_active' => true, 'sort_order' => 0]);
        $product = Product::factory()->create(['category_id' => $category->id, 'provider_name' => 'airalo']);

        return OrderItem::withoutEvents(fn () => OrderItem::factory()->create([
            'product_id' => $product->id,
            'product_variant_id' => ProductVariant::factory()->create(['product_id' => $product->id])->id,
            'category_id' => $category->id,
            'subcategory_id' => Subcategory::factory()->create(['category_id' => $category->id])->id,
            'provider_name' => 'airalo',
            'provider_offer_id' => 'airalo_discover-plus-5gb',
            'fulfillment_status' => FulfillmentStatus::Fulfilled,
            'delivered_at' => now(),
        ]));
    }

    public function active(int $remainingMb = 4096): static
    {
        return $this->state(fn () => [
            'status' => EsimStatus::Active,
            'provider_status' => 'ACTIVE',
            'data_remaining_mb' => $remainingMb,
            'activated_at' => now(),
        ]);
    }

    public function refunded(): static
    {
        return $this->state(fn () => [
            'status' => EsimStatus::Refunded,
            'refunded_at' => now(),
        ]);
    }
}
