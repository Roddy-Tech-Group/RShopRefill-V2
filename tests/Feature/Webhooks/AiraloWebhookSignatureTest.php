<?php

namespace Tests\Feature\Webhooks;

use App\Models\Category;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Subcategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The Airalo webhook mutates customer-facing state (notifications and email) on
 * an unauthenticated internet-facing route, so its authentication is a security
 * control, not a formality.
 *
 * These tests pin the behaviour that was previously implemented ad hoc inside the
 * controller and is now shared with every other provider webhook.
 */
class AiraloWebhookSignatureTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'airalo-test-client-secret';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.airalo.webhook_secret' => self::SECRET,
            'services.airalo.client_secret' => 'unused-fallback',
        ]);
    }

    public function test_it_rejects_a_request_with_no_signature(): void
    {
        $this->postJson('/api/webhooks/airalo', ['iccid' => '8912345678901234567'])
            ->assertStatus(401)
            ->assertJson(['error' => 'Invalid signature']);
    }

    public function test_it_rejects_a_tampered_payload(): void
    {
        $payload = ['iccid' => '8912345678901234567', 'event_type' => 'DATA_LOW'];
        $signature = hash_hmac('sha256', json_encode($payload), self::SECRET);

        // Same signature, different body — the classic forgery attempt.
        $tampered = ['iccid' => '9999999999999999999', 'event_type' => 'DATA_LOW'];

        $this->call(
            'POST',
            '/api/webhooks/airalo',
            [],
            [],
            [],
            $this->headers($signature),
            json_encode($tampered)
        )->assertStatus(401);
    }

    public function test_it_rejects_a_signature_signed_with_the_wrong_secret(): void
    {
        $payload = ['iccid' => '8912345678901234567'];

        $this->postSigned($payload, hash_hmac('sha256', json_encode($payload), 'attacker-secret'))
            ->assertStatus(401);
    }

    public function test_it_accepts_a_correctly_signed_request(): void
    {
        $payload = ['iccid' => '8912345678901234567', 'event_type' => 'DATA_LOW'];

        // No matching order item, so the handler no-ops — but authentication
        // passed, which is what this test asserts.
        $this->postSigned($payload)
            ->assertOk()
            ->assertJson(['status' => 'noop']);
    }

    public function test_it_accepts_the_sha256_prefixed_header_form(): void
    {
        $payload = ['iccid' => '8912345678901234567'];
        $signature = 'sha256='.hash_hmac('sha256', json_encode($payload), self::SECRET);

        $this->postSigned($payload, $signature)->assertOk();
    }

    public function test_it_accepts_the_alternate_signature_header(): void
    {
        $payload = ['iccid' => '8912345678901234567'];
        $signature = hash_hmac('sha256', json_encode($payload), self::SECRET);

        $this->call('POST', '/api/webhooks/airalo', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_SIGNATURE' => $signature,
        ], json_encode($payload))->assertOk();
    }

    public function test_it_falls_back_to_the_client_secret_when_no_webhook_secret_is_set(): void
    {
        config([
            'services.airalo.webhook_secret' => null,
            'services.airalo.client_secret' => 'the-client-secret',
        ]);

        $payload = ['iccid' => '8912345678901234567'];

        $this->postSigned($payload, hash_hmac('sha256', json_encode($payload), 'the-client-secret'))
            ->assertOk();
    }

    public function test_a_replayed_request_is_not_processed_twice(): void
    {
        $payload = ['iccid' => '8912345678901234567', 'event_type' => 'DATA_LOW'];

        $this->postSigned($payload)->assertOk();

        // A captured, still-validly-signed request replayed by an attacker is
        // recognised and short-circuited rather than re-handled.
        $this->postSigned($payload)
            ->assertOk()
            ->assertJson(['message' => 'Already processed']);
    }

    public function test_distinct_events_for_the_same_iccid_are_still_processed(): void
    {
        // Deduping on the ICCID alone would swallow the 90 % warning after the
        // 75 % one. Both must get through.
        $this->postSigned(['iccid' => '8912345678901234567', 'usage_percentage' => 75])->assertOk();

        $this->postSigned(['iccid' => '8912345678901234567', 'usage_percentage' => 90])
            ->assertOk()
            ->assertJsonMissing(['message' => 'Already processed']);
    }

    public function test_unsigned_requests_are_refused_when_no_secret_is_configured_outside_local(): void
    {
        config([
            'services.airalo.webhook_secret' => null,
            'services.airalo.client_secret' => null,
        ]);

        // Staging serves real provider traffic; an unconfigured secret there must
        // fail closed rather than wave every request through.
        app()->detectEnvironment(fn () => 'staging');

        $this->postJson('/api/webhooks/airalo', ['iccid' => '8912345678901234567'])
            ->assertStatus(401);
    }

    public function test_a_signed_webhook_notifies_the_customer_who_owns_the_iccid(): void
    {
        // The catalog factories do not populate every non-nullable column, so the
        // product and variant are built explicitly rather than left to defaults.
        $category = Category::factory()->create();
        $subcategory = Subcategory::factory()->create(['category_id' => $category->id]);
        $product = Product::factory()->create(['category_id' => $category->id]);
        $variant = ProductVariant::factory()->create(['product_id' => $product->id]);

        $orderItem = OrderItem::factory()->create([
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'category_id' => $category->id,
            'subcategory_id' => $subcategory->id,
            'fulfillment_payload' => ['iccid' => '8912345678901234567'],
        ]);

        $payload = [
            'iccid' => '8912345678901234567',
            'event_type' => 'DATA_LOW',
            'usage_percentage' => 90,
            'remaining_data' => '0.5 GB',
        ];

        $this->postSigned($payload)
            ->assertOk()
            ->assertJson(['status' => 'ok']);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $orderItem->order->user_id,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function postSigned(array $payload, ?string $signature = null): TestResponse
    {
        $body = json_encode($payload);
        $signature ??= hash_hmac('sha256', (string) $body, self::SECRET);

        return $this->call('POST', '/api/webhooks/airalo', [], [], [], $this->headers($signature), $body);
    }

    /**
     * @return array<string, string>
     */
    private function headers(string $signature): array
    {
        return [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_AIRALO_SIGNATURE' => $signature,
        ];
    }
}
