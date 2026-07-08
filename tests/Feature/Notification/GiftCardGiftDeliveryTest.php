<?php

namespace Tests\Feature\Notification;

use App\Domain\Notification\Providers\MailProviderInterface;
use App\Domain\Order\Events\FulfillmentSucceeded;
use App\Models\Category;
use App\Models\Notification;
use App\Models\NotificationPreference;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Subcategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Gifting: checkout's "Delivery email or send as a gift" field. When the
 * stored delivery_email differs from the buyer's account email, the
 * fulfilled email (which carries the code) goes to the recipient's inbox,
 * while the buyer keeps the in-app notification and the order + codes on
 * their dashboard. A gift email must also bypass the BUYER's notification
 * preferences - the inbox belongs to someone else.
 */
class GiftCardGiftDeliveryTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<array<string, mixed>> */
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->sent = [];
        $test = $this;

        $this->app->instance(MailProviderInterface::class, new class($test) implements MailProviderInterface
        {
            public function __construct(private GiftCardGiftDeliveryTest $test) {}

            public function send(string $to, string $subject, string $htmlBody, array $headers = [], array $attachments = []): array
            {
                $this->test->capture(compact('to', 'subject', 'htmlBody'));

                return ['id' => 'spy', 'status' => 'success'];
            }
        });
    }

    /** @param array<string, mixed> $args */
    public function capture(array $args): void
    {
        $this->sent[] = $args;
    }

    private function makeFulfilledItem(User $buyer, ?string $deliveryEmail): OrderItem
    {
        $order = Order::create([
            'user_id' => $buyer->id,
            'order_number' => 'RSR-'.uniqid(),
            'settlement_currency' => 'USD',
            'display_currency' => 'USD',
            'subtotal_amount' => 10.00,
            'markup_amount' => 2.00,
            'total_amount' => 12.00,
            'payment_method' => 'wallet',
            'payment_status' => 'paid',
            'fulfillment_status' => 'fulfilled',
            'order_status' => 'completed',
            'placed_at' => now(),
            'metadata' => array_filter([
                'exchange_rate' => 1.0,
                'delivery_email' => $deliveryEmail,
            ]),
        ]);

        $category = Category::factory()->create();
        $subcategory = Subcategory::factory()->create(['category_id' => $category->id]);
        $product = Product::factory()->create(['category_id' => $category->id]);
        $variant = ProductVariant::factory()->create(['product_id' => $product->id]);

        return OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'category_id' => $category->id,
            'subcategory_id' => $subcategory->id,
            'provider_name' => 'test',
            'quantity' => 1,
            'display_currency' => 'USD',
            'display_amount' => 10.00,
            'provider_cost_usd' => 8.00,
            'markup_amount' => 2.00,
            'subtotal_amount' => 10.00,
            'product_snapshot' => ['name' => 'Everything Apple gift card'],
            'fulfillment_status' => 'fulfilled',
        ]);
    }

    public function test_gift_orders_email_the_code_to_the_recipient_not_the_buyer(): void
    {
        $buyer = User::factory()->create(['email' => 'buyer@example.com']);
        $item = $this->makeFulfilledItem($buyer, 'friend@example.com');

        event(new FulfillmentSucceeded($item));

        $this->assertCount(1, $this->sent, 'Exactly one fulfilled email should be sent.');
        $this->assertSame('friend@example.com', $this->sent[0]['to']);
        // The recipient is greeted as a gift receiver, with the buyer named as sender.
        $this->assertStringContainsString('sent you this as a gift', $this->sent[0]['htmlBody']);

        // The buyer still gets the in-app notification (dashboard trail).
        $this->assertTrue(
            Notification::where('user_id', $buyer->id)->where('title', 'Your Refill Voucher is Ready!')->exists(),
            'Buyer should keep the in-app fulfilled notification.'
        );
    }

    public function test_non_gift_orders_email_the_buyer_as_before(): void
    {
        $buyer = User::factory()->create(['email' => 'buyer@example.com']);
        $item = $this->makeFulfilledItem($buyer, 'buyer@example.com');

        event(new FulfillmentSucceeded($item));

        $this->assertCount(1, $this->sent);
        $this->assertSame('buyer@example.com', $this->sent[0]['to']);
        $this->assertStringNotContainsString('sent you this as a gift', $this->sent[0]['htmlBody']);
    }

    public function test_gift_email_bypasses_the_buyers_notification_preferences(): void
    {
        $buyer = User::factory()->create(['email' => 'buyer@example.com']);
        NotificationPreference::create([
            'user_id' => $buyer->id,
            'email_enabled' => false,
            'push_enabled' => false,
            'marketing_enabled' => false,
            'order_notifications' => false,
            'wallet_notifications' => false,
            'security_notifications' => false,
        ]);

        $item = $this->makeFulfilledItem($buyer, 'friend@example.com');

        event(new FulfillmentSucceeded($item));

        $this->assertCount(1, $this->sent, 'The gift recipient must be emailed even when the buyer muted order emails.');
        $this->assertSame('friend@example.com', $this->sent[0]['to']);
    }
}
