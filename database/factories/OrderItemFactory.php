<?php

namespace Database\Factories;

use App\Domain\Fulfillment\Enums\FulfillmentStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Subcategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrderItem>
 */
class OrderItemFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'order_id' => Order::factory(),
            'product_id' => Product::factory(),
            // order_items.product_variant_id is NOT NULL: a line is always a
            // variant of its product, so the factory makes one on that product.
            'product_variant_id' => fn (array $attributes) => ProductVariant::factory()->create(['product_id' => $attributes['product_id']])->id,
            // The line's category is its product's; the subcategory (also NOT
            // NULL) is minted under that category.
            'category_id' => fn (array $attributes) => Product::query()->whereKey($attributes['product_id'])->value('category_id'),
            'subcategory_id' => fn (array $attributes) => Subcategory::factory()->create([
                'category_id' => Product::query()->whereKey($attributes['product_id'])->value('category_id'),
            ])->id,
            'provider_name' => 'test_provider',
            'quantity' => 1,
            'display_currency' => 'USD',
            'display_amount' => 10.00,
            'provider_cost_usd' => 8.00,
            'markup_amount' => 2.00,
            'subtotal_amount' => 10.00,
            'fulfillment_status' => FulfillmentStatus::NotStarted,
        ];
    }
}
