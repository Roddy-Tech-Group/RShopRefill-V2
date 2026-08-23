<?php

namespace Tests\Feature\Payment;

use App\Domain\Order\Services\GatewayRefundService;
use App\Domain\Order\Services\OrderService;
use App\Domain\Payment\Enums\PaymentStatus;
use App\Domain\Payment\Providers\WalletPaymentProvider;
use App\Domain\Shared\Enums\Currency;
use App\Domain\Wallet\Services\WalletService;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PaymentAttempt;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Subcategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The wallet refund web, rebuilt around one shared ledger:
 *
 *  - refunds are idempotent PER REFERENCE, not per attempt, so the second
 *    failed item on a multi-item wallet order is refunded instead of
 *    swallowed;
 *  - replaying a reference moves no more money;
 *  - failed items are refunded their DISCOUNT-AWARE share of what was
 *    captured, never their raw list price;
 *  - every refund lands in the order's tag-idempotent refund_records map, so
 *    refunded_total can never drift from what actually moved.
 */
class WalletRefundIntegrityTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: Order, 1: PaymentAttempt, 2: User}
     */
    private function walletPaidOrder(float $total): array
    {
        $user = User::factory()->create();

        $order = Order::create([
            'user_id' => $user->id,
            'order_number' => 'RSR-'.Str::upper(Str::random(8)),
            'payment_method' => 'wallet',
            'payment_status' => PaymentStatus::Paid,
            'display_currency' => 'USD',
            'total_amount' => $total,
            'subtotal_amount' => $total,
        ]);

        $attempt = PaymentAttempt::create([
            'order_id' => $order->id,
            'user_id' => $user->id,
            'gateway' => 'wallet',
            'idempotency_key' => (string) Str::uuid(),
            'currency' => 'USD',
            'amount' => $total,
            'payment_status' => PaymentStatus::Paid,
            'gateway_reference' => 'WL-'.Str::random(6),
            'confirmed_at' => now(),
        ]);

        return [$order, $attempt, $user];
    }

    /**
     * @param  array<int, float>  $itemSubtotals
     * @return array{0: Order, 1: Collection<int, OrderItem>}
     */
    private function orderWithItems(array $itemSubtotals, float $captured): array
    {
        [$order] = $this->walletPaidOrder($captured);
        $order->update(['subtotal_amount' => array_sum($itemSubtotals)]);

        $category = Category::factory()->create(['slug' => 'gift-cards']);
        $subcategory = Subcategory::factory()->create(['category_id' => $category->id]);
        $product = Product::factory()->create(['category_id' => $category->id]);
        $variant = ProductVariant::factory()->create(['product_id' => $product->id]);

        $items = collect($itemSubtotals)->map(fn (float $subtotal) => OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'category_id' => $category->id,
            'subcategory_id' => $subcategory->id,
            'provider_name' => 'zendit',
            'quantity' => 1,
            'display_currency' => 'USD',
            'display_amount' => $subtotal,
            'provider_cost_usd' => 1.0,
            'markup_amount' => 0,
            'subtotal_amount' => $subtotal,
            'product_snapshot' => ['name' => 'Test Gift Card'],
            'variant_snapshot' => ['face_value' => 10, 'currency' => 'USD'],
            'fulfillment_status' => 'failed',
            'failed_at' => now(),
        ]));

        return [$order, $items];
    }

    public function test_two_failed_items_on_one_wallet_order_are_both_refunded(): void
    {
        [$order, $attempt, $user] = $this->walletPaidOrder(100.0);
        $wallet = app(WalletService::class)->getOrCreateWallet($user, Currency::USD);
        $provider = app(WalletPaymentProvider::class);

        // One refund per failed item, each under its own reference. The old
        // per-attempt key made the second of these throw and roll back.
        $this->assertTrue($provider->refundPayment($attempt, 50.0, 'item-1'));
        $this->assertTrue($provider->refundPayment($attempt, 50.0, 'item-2'));

        $this->assertSame(100.0, (float) $wallet->fresh()->balance);
    }

    public function test_replaying_a_refund_reference_moves_no_more_money(): void
    {
        [$order, $attempt, $user] = $this->walletPaidOrder(100.0);
        $wallet = app(WalletService::class)->getOrCreateWallet($user, Currency::USD);
        $provider = app(WalletPaymentProvider::class);

        $this->assertTrue($provider->refundPayment($attempt, 50.0, 'item-1'));
        // A retry replaying the same reference reports success and credits nothing.
        $this->assertTrue($provider->refundPayment($attempt, 50.0, 'item-1'));

        $this->assertSame(50.0, (float) $wallet->fresh()->balance);
    }

    public function test_captured_share_apportions_the_discounted_total_not_list_price(): void
    {
        // Two 50-list items on an order that captured 80 after an RCOIN
        // discount: each item's share is 40, and the shares telescope back to
        // exactly what was captured.
        [$order, $items] = $this->orderWithItems([50.0, 50.0], 80.0);
        $service = app(GatewayRefundService::class);

        $shares = $items->map(
            fn (OrderItem $item) => $service->capturedShareForItem($order, $item, (float) $order->total_amount),
        );

        $this->assertSame([40.0, 40.0], $shares->all());
        $this->assertSame(80.0, round($shares->sum(), 2));
    }

    public function test_refund_records_are_tag_idempotent_and_summed(): void
    {
        [$order] = $this->walletPaidOrder(100.0);
        $service = app(OrderService::class);

        $this->assertSame(30.0, $service->recordRefundedAmount($order, 30.0, 'item-a'));
        // Replaying a tag records nothing new.
        $this->assertSame(30.0, $service->recordRefundedAmount($order, 30.0, 'item-a'));
        $this->assertSame(50.0, $service->recordRefundedAmount($order, 20.0, 'final'));

        $this->assertSame(50.0, (float) $order->fresh()->metadata['refunded_total']);
    }
}
