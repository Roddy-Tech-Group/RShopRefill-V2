<?php

namespace Tests\Feature\Fulfillment;

use App\Jobs\FulfillOrderItemJob;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Subcategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

/**
 * Identical offers on one paid order must not hit the supplier back to back:
 * a qty-2 gift card order fires two identical API calls milliseconds apart,
 * which trips duplicate/velocity protection (observed as Zendit error -29 on
 * the second of two identical Apple US cards). dispatchStaggered() spaces
 * duplicates 30s apart while leaving everything without duplicates exactly
 * as it always was.
 */
class StaggeredFulfillmentDispatchTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: Order, 1: ProductVariant} */
    private function makeOrderAndVariant(): array
    {
        $category = Category::create(['name' => 'Gift Cards', 'slug' => 'gift-cards', 'type' => 'digital']);
        $subcategory = Subcategory::create(['category_id' => $category->id, 'name' => 'Cards', 'slug' => 'cards']);
        $product = Product::factory()->create(['category_id' => $category->id, 'subcategory_id' => $subcategory->id, 'provider_name' => 'zendit']);
        $variant = ProductVariant::factory()->create(['product_id' => $product->id]);

        return [Order::factory()->create(), $variant];
    }

    private function makeItem(Order $order, ProductVariant $variant, string $offerId): OrderItem
    {
        return OrderItem::factory()->create([
            'order_id' => $order->id,
            'product_id' => $variant->product_id,
            'product_variant_id' => $variant->id,
            'category_id' => $variant->product->category_id,
            'subcategory_id' => $variant->product->subcategory_id,
            'provider_offer_id' => $offerId,
        ]);
    }

    /** @return array<string, mixed> [item id => delay] for every dispatched job */
    private function dispatchedDelays(): array
    {
        $delays = [];

        Bus::assertDispatched(FulfillOrderItemJob::class, function (FulfillOrderItemJob $job) use (&$delays) {
            $property = new \ReflectionProperty($job, 'item');
            $delays[$property->getValue($job)->id] = $job->delay;

            return true;
        });

        return $delays;
    }

    public function test_identical_offers_are_staggered_thirty_seconds_apart(): void
    {
        Bus::fake();
        [$order, $variant] = $this->makeOrderAndVariant();

        $first = $this->makeItem($order, $variant, 'APPLE_US_10_EGIFT_WHS');
        $second = $this->makeItem($order, $variant, 'APPLE_US_10_EGIFT_WHS');
        $third = $this->makeItem($order, $variant, 'APPLE_US_10_EGIFT_WHS');

        FulfillOrderItemJob::dispatchStaggered([$first, $second, $third]);

        $delays = $this->dispatchedDelays();
        $this->assertCount(3, $delays);
        $this->assertNull($delays[$first->id], 'first identical item must dispatch immediately');
        $this->assertSame(30, $delays[$second->id]);
        $this->assertSame(60, $delays[$third->id]);
    }

    public function test_different_offers_all_dispatch_immediately(): void
    {
        Bus::fake();
        [$order, $variant] = $this->makeOrderAndVariant();

        $apple = $this->makeItem($order, $variant, 'APPLE_US_10_EGIFT_WHS');
        $steam = $this->makeItem($order, $variant, 'STEAM_US_20_EGIFT_WHS');

        FulfillOrderItemJob::dispatchStaggered([$apple, $steam]);

        $delays = $this->dispatchedDelays();
        $this->assertCount(2, $delays);
        $this->assertNull($delays[$apple->id]);
        $this->assertNull($delays[$steam->id], 'a different offer is not a duplicate and must not wait');
    }

    public function test_the_stagger_is_capped_so_large_orders_finish_in_minutes(): void
    {
        Bus::fake();
        [$order, $variant] = $this->makeOrderAndVariant();

        $items = collect(range(1, 13))
            ->map(fn () => $this->makeItem($order, $variant, 'APPLE_US_10_EGIFT_WHS'));

        FulfillOrderItemJob::dispatchStaggered($items);

        $delays = $this->dispatchedDelays();
        $this->assertCount(13, $delays);
        $this->assertSame(
            FulfillOrderItemJob::SIBLING_STAGGER_CAP_SECONDS,
            max(array_map(fn ($d) => (int) $d, $delays)),
        );
    }
}
