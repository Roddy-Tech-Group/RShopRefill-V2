<?php

namespace Tests\Feature;

use App\Domain\Fulfillment\Enums\FulfillmentStatus;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Payment\Enums\PaymentStatus;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Review;
use App\Models\Subcategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Customers can submit a review after an order. It is stored unpublished and
 * flagged as a customer submission, so it only reaches the storefront once an
 * admin publishes it.
 */
class CustomerReviewSubmissionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A completed order for the given user containing one item per brand key.
     *
     * @param  list<string>  $brandKeys
     */
    private function completedOrderFor(User $user, array $brandKeys = ['Acme'], OrderStatus $status = OrderStatus::Completed): Order
    {
        $order = Order::create([
            'user_id' => $user->id,
            'order_number' => 'ORD-'.strtoupper(fake()->bothify('????##')),
            'settlement_currency' => 'USD',
            'display_currency' => 'USD',
            'subtotal_amount' => 10,
            'markup_amount' => 0,
            'total_amount' => 10,
            'payment_method' => 'wallet',
            'payment_status' => PaymentStatus::Paid,
            'fulfillment_status' => FulfillmentStatus::Fulfilled,
            'order_status' => $status,
        ]);

        $category = Category::factory()->create();
        $subcategory = Subcategory::factory()->create(['category_id' => $category->id]);

        foreach ($brandKeys as $brandKey) {
            $product = Product::factory()->create([
                'category_id' => $category->id,
                'subcategory_id' => $subcategory->id,
                'brand_key' => $brandKey,
            ]);
            $variant = ProductVariant::factory()->for($product)->create();

            OrderItem::create([
                'order_id' => $order->id,
                'product_id' => $product->id,
                'product_variant_id' => $variant->id,
                'category_id' => $category->id,
                'subcategory_id' => $subcategory->id,
                'provider_name' => 'test_provider',
                'quantity' => 1,
                'display_currency' => 'USD',
                'display_amount' => 10,
                'provider_cost_usd' => 8,
                'markup_amount' => 2,
                'subtotal_amount' => 10,
                'fulfillment_status' => FulfillmentStatus::Fulfilled,
            ]);
        }

        return $order;
    }

    public function test_a_logged_in_customer_can_submit_a_pending_review(): void
    {
        $user = User::factory()->create(['name' => 'Divine Ofeh']);

        $this->actingAs($user)
            ->postJson('/reviews', [
                'author_name' => 'Divine Ofeh',
                'body' => 'Fast delivery and great prices, very happy.',
                'rating' => 5,
            ])
            ->assertOk()
            ->assertJson(['ok' => true]);

        $review = Review::first();
        $this->assertNotNull($review);
        $this->assertFalse($review->is_published);
        $this->assertTrue($review->is_customer_submitted);
        $this->assertSame($user->id, $review->user_id);
        // The source column is a label naming who collected the review, and for
        // our own submissions that label is the brand's own name. The brand is
        // now written "RshopRefill", singular: it is what APP_NAME carries, it
        // matches the rshoprefill.com domain the site has always been served
        // from, and it is the only own-website option the admin review form
        // offers, which validates source against in:Trustpilot,Google,RshopRefill.
        // This assertion still expected the older plural "RshopRefills" and was
        // left behind when the name was corrected across the application. Only
        // the spelling moved: Review::scopeOwnCollected decides what reaches the
        // storefront by asking whether the source is one of the external
        // platforms, so no filtering depends on this exact string. What the
        // assertion really pins is that a customer submission is stamped with
        // our own brand and never inherits a platform's name.
        $this->assertSame('RshopRefill', $review->source);
        $this->assertSame('DO', $review->initials);
        $this->assertSame(5, (int) $review->rating);
    }

    public function test_pending_reviews_are_hidden_from_the_storefront_until_published(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->postJson('/reviews', [
            'author_name' => 'Jane Doe',
            'body' => 'Worked perfectly, recommend it.',
            'rating' => 4,
        ])->assertOk();

        $this->assertSame(0, Review::published()->count());
        $this->assertSame(1, Review::pending()->count());

        // Admin approves -> now public.
        Review::first()->update(['is_published' => true]);
        $this->assertSame(1, Review::published()->count());
    }

    public function test_resubmitting_updates_the_same_pending_review(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/reviews', ['author_name' => 'Sam', 'body' => 'First version of my review.', 'rating' => 3])->assertOk();
        $this->actingAs($user)->postJson('/reviews', ['author_name' => 'Sam', 'body' => 'Updated, even better than I thought.', 'rating' => 5])->assertOk();

        $this->assertSame(1, Review::where('user_id', $user->id)->count());
        $this->assertSame(5, (int) Review::where('user_id', $user->id)->first()->rating);
    }

    public function test_a_guest_can_submit_a_review_with_a_name(): void
    {
        $this->postJson('/reviews', [
            'author_name' => 'Anon Guest',
            'body' => 'Great service even without an account.',
            'rating' => 5,
        ])->assertOk()->assertJson(['ok' => true]);

        $review = Review::first();
        $this->assertNotNull($review);
        $this->assertNull($review->user_id);
        $this->assertNull($review->order_id);
        $this->assertSame('Anon Guest', $review->author_name);
        $this->assertFalse($review->is_published);
        $this->assertTrue($review->is_customer_submitted);
    }

    public function test_a_guest_must_supply_a_name(): void
    {
        $this->postJson('/reviews', ['body' => 'No name supplied here at all.', 'rating' => 5])
            ->assertStatus(422);
    }

    public function test_a_logged_in_review_uses_the_account_name_not_the_submitted_one(): void
    {
        $user = User::factory()->create(['name' => 'Real Account Name']);

        $this->actingAs($user)->postJson('/reviews', [
            'author_name' => 'Spoofed Name',
            'body' => 'Trying to post under a different name.',
            'rating' => 5,
        ])->assertOk();

        $this->assertSame('Real Account Name', Review::first()->author_name);
    }

    public function test_validation_rejects_bad_input(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)
            ->postJson('/reviews', ['author_name' => '', 'body' => 'x', 'rating' => 9])
            ->assertStatus(422);
    }

    public function test_a_review_left_from_an_order_is_linked_to_that_order(): void
    {
        $user = User::factory()->create();
        $order = $this->completedOrderFor($user);

        $this->actingAs($user)->postJson('/reviews', [
            'author_name' => 'Buyer One',
            'body' => 'Card arrived instantly, great service.',
            'rating' => 5,
            'order_number' => $order->order_number,
        ])->assertOk();

        $this->assertSame($order->id, Review::first()->order_id);
    }

    public function test_an_order_review_rolls_up_under_every_gift_card_in_the_order(): void
    {
        $user = User::factory()->create();
        $order = $this->completedOrderFor($user, ['Amazon', 'Steam']);

        $this->actingAs($user)->postJson('/reviews', [
            'author_name' => 'Buyer Two',
            'body' => 'Bought two cards, both delivered fast.',
            'rating' => 5,
            'order_number' => $order->order_number,
        ])->assertOk();

        // The single review counts for each brand in the order.
        $this->assertTrue(Review::forBrand('Amazon')->exists());
        $this->assertTrue(Review::forBrand('Steam')->exists());
        $this->assertFalse(Review::forBrand('Walmart')->exists());
        $this->assertSame(1, Review::count());
    }

    public function test_a_general_review_has_no_order(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/reviews', [
            'author_name' => 'Browser',
            'body' => 'Great site overall, easy to use.',
            'rating' => 4,
        ])->assertOk();

        $this->assertNull(Review::first()->order_id);
    }

    public function test_a_product_review_and_a_general_review_coexist_for_the_same_user(): void
    {
        $user = User::factory()->create();
        $order = $this->completedOrderFor($user);

        $this->actingAs($user)->postJson('/reviews', [
            'author_name' => 'Buyer', 'body' => 'Product review body here.', 'rating' => 5, 'order_number' => $order->order_number,
        ])->assertOk();
        $this->actingAs($user)->postJson('/reviews', [
            'author_name' => 'Buyer', 'body' => 'General review body here.', 'rating' => 4,
        ])->assertOk();

        // The order review and the general review are separate rows.
        $this->assertSame(2, Review::where('user_id', $user->id)->count());
    }

    public function test_cannot_review_an_order_that_is_not_yours(): void
    {
        $owner = User::factory()->create();
        $order = $this->completedOrderFor($owner);
        $stranger = User::factory()->create();

        $this->actingAs($stranger)->postJson('/reviews', [
            'author_name' => 'Stranger',
            'body' => 'Trying to review someone else order.',
            'rating' => 1,
            'order_number' => $order->order_number,
        ])->assertStatus(422);

        $this->assertSame(0, Review::count());
    }

    public function test_cannot_review_an_order_that_is_not_completed(): void
    {
        $user = User::factory()->create();
        $order = $this->completedOrderFor($user, ['Acme'], OrderStatus::Pending);

        $this->actingAs($user)->postJson('/reviews', [
            'author_name' => 'Buyer',
            'body' => 'Order has not been delivered yet.',
            'rating' => 5,
            'order_number' => $order->order_number,
        ])->assertStatus(422);

        $this->assertSame(0, Review::count());
    }

    public function test_reviews_page_renders_published_reviews_in_carousels(): void
    {
        $this->withoutVite();

        $base = [
            'initials' => 'WJ',
            'body' => 'Bought a global eSIM and it worked the moment I landed.',
            'rating' => 5.0,
            'reviewed_at' => now()->toDateString(),
            'is_published' => true,
            'is_customer_submitted' => false,
        ];

        Review::create($base + ['author_name' => 'Wanderer Jane', 'source' => 'RshopRefills']);
        // Same review, attributed to a platform that displays its own reviews on
        // its own surface. Published, curated, and still never rendered here.
        Review::create($base + ['author_name' => 'Platform Jane', 'initials' => 'PJ', 'source' => 'Trustpilot']);

        $this->get(route('shop.reviews'))
            ->assertOk()
            ->assertSee('Recent reviews')
            ->assertSee('Wanderer Jane')
            ->assertDontSee('Platform Jane');
    }
}
