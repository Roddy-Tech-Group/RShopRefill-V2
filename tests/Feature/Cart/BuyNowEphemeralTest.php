<?php

namespace Tests\Feature\Cart;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "Buy now" adds an ephemeral cart item: it rides through to checkout but is
 * purged the moment the cart is read from anywhere that isn't a checkout page,
 * so an abandoned Buy now never lingers in the persistent cart.
 */
class BuyNowEphemeralTest extends TestCase
{
    use RefreshDatabase;

    private function makeVariant(): ProductVariant
    {
        $category = Category::factory()->create();
        $product = Product::factory()->create(['category_id' => $category->id]);

        return ProductVariant::factory()->create(['product_id' => $product->id]);
    }

    public function test_buy_now_add_tags_the_item_as_ephemeral(): void
    {
        $variant = $this->makeVariant();

        $this->actingAs(User::factory()->create())
            ->postJson('/cart/items', [
                'product_variant_id' => $variant->id,
                'quantity' => 1,
                'buy_now' => true,
            ])
            ->assertOk()
            ->assertJsonPath('count', 1)
            ->assertSessionHas('cart.buy_now_items');
    }

    public function test_normal_add_is_not_tagged(): void
    {
        $variant = $this->makeVariant();

        $this->actingAs(User::factory()->create())
            ->postJson('/cart/items', [
                'product_variant_id' => $variant->id,
                'quantity' => 1,
            ])
            ->assertOk()
            ->assertSessionMissing('cart.buy_now_items');
    }

    public function test_tagged_item_is_kept_on_checkout_but_purged_elsewhere(): void
    {
        $user = User::factory()->create();
        $variant = $this->makeVariant();

        $itemId = $this->actingAs($user)
            ->postJson('/cart/items', ['product_variant_id' => $variant->id, 'quantity' => 1])
            ->assertOk()
            ->json('items.0.id');

        // On a checkout page (ctx=checkout) the tagged item survives.
        $this->actingAs($user)
            ->withSession(['cart.buy_now_items' => [$itemId]])
            ->getJson('/cart/data?ctx=checkout')
            ->assertOk()
            ->assertJsonPath('count', 1);

        // Read anywhere else, it is purged.
        $this->actingAs($user)
            ->withSession(['cart.buy_now_items' => [$itemId]])
            ->getJson('/cart/data')
            ->assertOk()
            ->assertJsonPath('count', 0);
    }

    public function test_normal_item_is_never_purged(): void
    {
        $user = User::factory()->create();
        $variant = $this->makeVariant();

        $this->actingAs($user)
            ->postJson('/cart/items', ['product_variant_id' => $variant->id, 'quantity' => 1])
            ->assertOk();

        // No buy-now tag in session, so a normal item stays even off-checkout.
        $this->actingAs($user)
            ->getJson('/cart/data')
            ->assertOk()
            ->assertJsonPath('count', 1);
    }
}
