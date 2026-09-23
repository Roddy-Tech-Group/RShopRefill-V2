<?php

namespace Tests\Feature\Fulfillment;

use App\Domain\Catalog\Services\RejectedOfferDelister;
use App\Domain\Fulfillment\Enums\FulfillmentStatus;
use App\Domain\Fulfillment\Providers\ZenditFulfillmentProvider;
use App\Domain\Shared\Services\OperationalAlerter;
use App\Enums\AlertSeverity;
use App\Models\Category;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Subcategory;
use App\Support\TaggedCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * On 2026-09-17 customers paid for APPLE_US_10_EGIFT_WHS after Zendit had
 * dropped it, and every purchase came back "offer not found". The first
 * rejection now takes the offer off sale, so the next customer never pays
 * for it; the refund itself still runs through the normal failed-item path.
 */
class ZenditRejectedOfferTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, array{severity: AlertSeverity, key: string}> */
    private array $alerts = [];

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.zendit.api_key' => 'live_test', 'services.zendit.base_url' => 'https://api.zendit.io/v1']);

        $alerts = &$this->alerts;

        $this->app->instance(OperationalAlerter::class, new class($alerts) extends OperationalAlerter
        {
            public function __construct(private array &$sent) {}

            public function send(
                AlertSeverity $severity,
                string $key,
                string $title,
                string $message,
                array $context = [],
                ?string $runbook = null,
            ): bool {
                $this->sent[] = compact('severity', 'key');

                return true;
            }
        });
    }

    public function test_an_offer_zendit_rejects_as_not_found_is_hidden_straight_away(): void
    {
        $item = $this->giftCardItem('APPLE_US_10_EGIFT_WHS');

        Http::fake(['api.zendit.io/*' => Http::response([
            'code' => 400,
            'message' => 'INVALID_VALUE: params.offerID: offer not found',
        ], 400)]);

        $result = (new ZenditFulfillmentProvider)->fulfill($item);

        $this->assertSame(FulfillmentStatus::Failed, $result['status']);

        $variant = ProductVariant::where('provider_offer_id', 'APPLE_US_10_EGIFT_WHS')->sole();
        $this->assertFalse($variant->is_available);
        $this->assertNotNull($variant->delisted_at);

        $this->assertCount(1, $this->alerts);
        $this->assertSame(AlertSeverity::Sev2, $this->alerts[0]['severity']);
        $this->assertSame('catalog:offer-not-found:APPLE_US_10_EGIFT_WHS', $this->alerts[0]['key']);
    }

    public function test_a_dead_offer_does_not_count_toward_tripping_the_zendit_circuit_breaker(): void
    {
        $item = $this->giftCardItem('APPLE_US_10_EGIFT_WHS');

        Http::fake(['api.zendit.io/*' => Http::response(['message' => 'INVALID_VALUE: params.offerID: offer not found'], 400)]);

        (new ZenditFulfillmentProvider)->fulfill($item);

        $this->assertSame(0, (int) TaggedCache::for(['circuit_breaker'])->get('circuit_breaker:zendit_api:failures', 0));
    }

    public function test_any_other_rejection_leaves_the_offer_on_sale(): void
    {
        $item = $this->giftCardItem('AMAZON_US_25_EGIFT');

        Http::fake(['api.zendit.io/*' => Http::response(['message' => 'INVALID_VALUE: value is out of range'], 400)]);

        (new ZenditFulfillmentProvider)->fulfill($item);

        $this->assertTrue(ProductVariant::where('provider_offer_id', 'AMAZON_US_25_EGIFT')->sole()->is_available);
        $this->assertSame([], $this->alerts);
        $this->assertSame(1, (int) TaggedCache::for(['circuit_breaker'])->get('circuit_breaker:zendit_api:failures', 0));
    }

    public function test_only_client_errors_that_name_a_missing_offer_count(): void
    {
        $this->assertTrue(RejectedOfferDelister::isOfferNotFound(400, '{"message":"INVALID_VALUE: params.offerID: offer not found"}'));
        $this->assertTrue(RejectedOfferDelister::isOfferNotFound(404, 'Offer Not Found'));
        $this->assertFalse(RejectedOfferDelister::isOfferNotFound(500, 'offer not found'));
        $this->assertFalse(RejectedOfferDelister::isOfferNotFound(400, '404 page not found'));
    }

    private function giftCardItem(string $offerId): OrderItem
    {
        $category = Category::factory()->create(['slug' => 'gift-cards']);
        $subcategory = Subcategory::factory()->create(['category_id' => $category->id]);
        $product = Product::factory()->create(['provider_name' => 'zendit', 'category_id' => $category->id]);

        $variant = ProductVariant::factory()->create([
            'product_id' => $product->id,
            'provider_offer_id' => $offerId,
        ]);

        return OrderItem::factory()->create([
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'category_id' => $category->id,
            'subcategory_id' => $subcategory->id,
            'provider_name' => 'zendit',
            'provider_offer_id' => $offerId,
            'product_snapshot' => ['name' => 'Apple (US)'],
        ]);
    }
}
