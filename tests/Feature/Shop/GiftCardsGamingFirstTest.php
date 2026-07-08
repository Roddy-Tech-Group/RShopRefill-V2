<?php

namespace Tests\Feature\Shop;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Subcategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The gift cards listing's default (popularity) sort leads with Gaming &
 * Entertainment brands - the curated leaders (Free Fire, PUBG, EA Play,
 * Mobile Legends, Discord Nitro) first in that exact order, then the rest of
 * gaming, then every other subcategory (even admin-flagged popular brands).
 */
class GiftCardsGamingFirstTest extends TestCase
{
    use RefreshDatabase;

    private function brand(Category $category, Subcategory $subcategory, string $brandKey, bool $popular = false): Product
    {
        $product = Product::factory()->create([
            'category_id' => $category->id,
            'subcategory_id' => $subcategory->id,
            'brand_key' => $brandKey,
            'country_code' => 'US',
            'is_popular' => $popular,
            'is_active' => true,
        ]);

        ProductVariant::factory()->create(['product_id' => $product->id]);

        return $product;
    }

    public function test_gaming_leads_the_listing_with_the_curated_order_first(): void
    {
        $giftCards = Category::factory()->create(['name' => 'Gift Cards', 'slug' => 'gift-cards']);
        $gaming = Subcategory::factory()->create(['category_id' => $giftCards->id, 'name' => 'Gaming & Entertainment', 'slug' => 'gaming-entertainment']);
        $shopping = Subcategory::factory()->create(['category_id' => $giftCards->id, 'name' => 'Shopping', 'slug' => 'shopping']);

        // Popular non-gaming brand - would normally lead the page.
        $this->brand($giftCards, $shopping, 'AlphaShoppingBrand', popular: true);
        // Unflagged generic gaming brand.
        $this->brand($giftCards, $gaming, 'ZuluGamingBrand');
        // Curated leaders, created in reverse to prove ordering is not insertion order.
        $this->brand($giftCards, $gaming, 'DiscordNitro');
        $this->brand($giftCards, $gaming, 'FreeFire');

        $this->withoutVite()->get(route('shop.gift-cards'))
            ->assertOk()
            ->assertSeeInOrder(['Free Fire', 'Discord Nitro', 'Zulu Gaming Brand', 'Alpha Shopping Brand']);
    }
}
