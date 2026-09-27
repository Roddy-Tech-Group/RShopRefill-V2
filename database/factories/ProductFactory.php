<?php

namespace Database\Factories;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            // products.category_id is NOT NULL; a factory product gets a
            // category of its own unless the test hands it one.
            'category_id' => Category::factory(),
            'provider_name' => 'test',
            'provider_reference' => $this->faker->uuid,
            'brand_key' => 'TestBrand',
            'country_code' => 'US',
            'currency_code' => 'USD',
            'name' => 'Test Product',
            'slug' => $this->faker->slug,
            'description' => 'Test Description',
            'is_active' => true,
            'is_out_of_stock' => false,
        ];
    }

    /**
     * Mark the product out of stock (visible but non-purchasable).
     */
    public function outOfStock(): static
    {
        return $this->state(fn (array $attributes) => ['is_out_of_stock' => true]);
    }
}
