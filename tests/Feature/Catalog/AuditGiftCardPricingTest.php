<?php

namespace Tests\Feature\Catalog;

use App\Models\Category;
use App\Models\CurrencyRate;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuditGiftCardPricingTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_flags_a_cost_in_local_card_and_passes_a_healthy_one(): void
    {
        CurrencyRate::create(['code' => 'AED', 'name' => 'UAE Dirham', 'type' => 'fiat', 'rate_per_usd' => 3.6725, 'is_active' => true]);
        $category = Category::factory()->create(['slug' => 'gift-cards']);

        // Non-USD card whose cost is still stored in the card currency (~= face) -> flagged.
        $bad = Product::factory()->create(['category_id' => $category->id, 'provider_name' => 'zendit', 'name' => 'Un-converted AED']);
        ProductVariant::factory()->for($bad)->create(['currency' => 'AED', 'face_value' => 100, 'cost_price' => 97.89, 'is_variable' => false]);

        // Healthy USD card -> not flagged.
        $good = Product::factory()->create(['category_id' => $category->id, 'provider_name' => 'zendit', 'name' => 'Healthy USD']);
        ProductVariant::factory()->for($good)->create(['currency' => 'USD', 'face_value' => 10, 'cost_price' => 9.50, 'is_variable' => false]);

        $this->artisan('catalog:audit-gift-card-pricing')
            ->assertSuccessful()
            ->expectsOutputToContain('1 COST-IN-LOCAL');
    }

    public function test_a_converted_non_usd_card_is_not_flagged(): void
    {
        CurrencyRate::create(['code' => 'AED', 'name' => 'UAE Dirham', 'type' => 'fiat', 'rate_per_usd' => 3.6725, 'is_active' => true]);
        $category = Category::factory()->create(['slug' => 'gift-cards']);

        // Same card after the cost fix: cost_price in USD (~face/rate).
        $ok = Product::factory()->create(['category_id' => $category->id, 'provider_name' => 'zendit', 'name' => 'Fixed AED']);
        ProductVariant::factory()->for($ok)->create(['currency' => 'AED', 'face_value' => 100, 'cost_price' => 26.66, 'is_variable' => false]);

        $this->artisan('catalog:audit-gift-card-pricing')
            ->assertSuccessful()
            ->expectsOutputToContain('0 COST-IN-LOCAL');
    }
}
