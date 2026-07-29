<?php

namespace Tests\Feature\Payment;

use App\Domain\Order\Services\GatewayRefundService;
use App\Domain\Payment\Enums\PaymentStatus;
use App\Domain\Shared\Enums\Currency;
use App\Domain\Shared\Enums\TransactionCategory;
use App\Domain\Wallet\Services\WalletService;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PaymentAttempt;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Subcategory;
use App\Models\User;
use App\Models\WalletTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * When an order paid by a NON-wallet gateway (card / mobile money / crypto)
 * fails to fulfill, the customer's money must be returned to their wallet -
 * the wallet-first refund policy. Previously only wallet-funded orders were
 * reversed, so a MoMo customer was charged with no product and no refund.
 */
class GatewayRefundOnFailureTest extends TestCase
{
    use RefreshDatabase;

    private function failedMomoOrder(float $charged, string $currency = 'XAF', ?float $itemSubtotal = null): OrderItem
    {
        $user = User::factory()->create();

        $order = Order::create([
            'user_id' => $user->id,
            'order_number' => 'RSR-'.Str::upper(Str::random(8)),
            'payment_method' => 'flutterwave',
            'payment_status' => PaymentStatus::Paid,
            'order_status' => 'failed',
            'display_currency' => $currency,
            'total_amount' => $charged,
            'subtotal_amount' => $charged,
        ]);

        PaymentAttempt::create([
            'order_id' => $order->id,
            'user_id' => $user->id,
            'gateway' => 'flutterwave',
            'idempotency_key' => (string) Str::uuid(),
            'currency' => $currency,
            'amount' => $charged,
            'payment_status' => PaymentStatus::Paid,
            'gateway_reference' => 'FLW-'.Str::random(6),
            'confirmed_at' => now(),
        ]);

        $category = Category::factory()->create(['slug' => 'gift-cards']);
        $subcategory = Subcategory::factory()->create(['category_id' => $category->id]);
        $product = Product::factory()->create(['category_id' => $category->id]);
        $variant = ProductVariant::factory()->create(['product_id' => $product->id]);

        return OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'category_id' => $category->id,
            'subcategory_id' => $subcategory->id,
            'provider_name' => 'zendit',
            'quantity' => 1,
            'display_currency' => $currency,
            'display_amount' => $charged,
            'provider_cost_usd' => 9.0,
            'markup_amount' => 0,
            'subtotal_amount' => $itemSubtotal ?? $charged,
            'product_snapshot' => ['name' => 'Test Gift Card'],
            'variant_snapshot' => ['face_value' => 10, 'currency' => 'USD'],
            'fulfillment_status' => 'failed',
            'failed_at' => now(),
        ]);
    }

    /**
     * A gateway-paid order whose captured amount is LESS than the sum of its
     * item list prices - i.e. an order settled with an RCOIN or coupon
     * discount. Item subtotals stay at list price (that is what checkout
     * snapshots for an order-level RCOIN discount); only the order total and
     * the payment attempt carry the discounted figure.
     *
     * @param  array<int, float>  $itemSubtotals
     * @return array{0: Order, 1: Collection<int, OrderItem>}
     */
    private function discountedGatewayOrder(array $itemSubtotals, float $captured, string $currency = 'USD'): array
    {
        $user = User::factory()->create();
        $listTotal = array_sum($itemSubtotals);

        $order = Order::create([
            'user_id' => $user->id,
            'order_number' => 'RSR-'.Str::upper(Str::random(8)),
            'payment_method' => 'flutterwave',
            'payment_status' => PaymentStatus::Paid,
            'order_status' => 'failed',
            'display_currency' => $currency,
            'total_amount' => $captured,
            'subtotal_amount' => $listTotal,
            'metadata' => [
                'settlement_total_usd' => $captured,
                'rcoin_discount_usd' => round($listTotal - $captured, 2),
            ],
        ]);

        PaymentAttempt::create([
            'order_id' => $order->id,
            'user_id' => $user->id,
            'gateway' => 'flutterwave',
            'idempotency_key' => (string) Str::uuid(),
            'currency' => $currency,
            'amount' => $captured,
            'payment_status' => PaymentStatus::Paid,
            'gateway_reference' => 'FLW-'.Str::random(6),
            'confirmed_at' => now(),
        ]);

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
            'display_currency' => $currency,
            'display_amount' => $subtotal,
            'provider_cost_usd' => 1.0,
            'markup_amount' => 0,
            'subtotal_amount' => $subtotal,
            'product_snapshot' => ['name' => 'Test Gift Card'],
            'variant_snapshot' => ['face_value' => $subtotal, 'currency' => 'USD'],
            'fulfillment_status' => 'failed',
            'failed_at' => now(),
        ]));

        return [$order, $items];
    }

    public function test_discounted_order_refunds_what_was_captured_not_the_list_price(): void
    {
        // $10 card settled for $7 after a $3 RCOIN discount.
        [$order, $items] = $this->discountedGatewayOrder([10.00], 7.00);

        app(GatewayRefundService::class)->refundFailedItemToWallet($items->first());

        $wallet = app(WalletService::class)->getOrCreateWallet($order->user, Currency::USD);
        $this->assertEqualsWithDelta(7.00, (float) $wallet->fresh()->balance, 0.001);

        $tx = WalletTransaction::where('user_id', $order->user_id)
            ->where('transaction_category', TransactionCategory::Refund)
            ->first();
        $this->assertNotNull($tx);
        $this->assertEqualsWithDelta(7.00, (float) $tx->amount, 0.001);
    }

    public function test_fully_refunded_discounted_order_returns_exactly_what_was_captured(): void
    {
        // Three $10 items settled for $20 - the per-item share ($6.6666…) is
        // the rounding case where naive per-item rounding refunds $20.01.
        [$order, $items] = $this->discountedGatewayOrder([10.00, 10.00, 10.00], 20.00);

        $service = app(GatewayRefundService::class);
        foreach ($items as $item) {
            $service->refundFailedItemToWallet($item);
        }

        $wallet = app(WalletService::class)->getOrCreateWallet($order->user, Currency::USD);
        $this->assertSame(3, WalletTransaction::where('user_id', $order->user_id)->count());

        $refunded = (float) WalletTransaction::where('user_id', $order->user_id)
            ->where('transaction_category', TransactionCategory::Refund)
            ->sum('amount');

        // To the cent - never a penny more than the gateway captured.
        $this->assertSame('20.00', number_format($refunded, 2, '.', ''));
        $this->assertSame('20.00', number_format((float) $wallet->fresh()->balance, 2, '.', ''));
    }

    public function test_partially_failed_discounted_order_refunds_only_the_failed_share(): void
    {
        // $10 + $30 of items settled for $32 after an $8 discount. Only the
        // cheaper item failed, so its share is 32 × (10/40) = $8.
        [$order, $items] = $this->discountedGatewayOrder([10.00, 30.00], 32.00);

        $failed = $items->first();
        $items->last()->update(['fulfillment_status' => 'fulfilled', 'failed_at' => null]);

        app(GatewayRefundService::class)->refundFailedItemToWallet($failed);

        $wallet = app(WalletService::class)->getOrCreateWallet($order->user, Currency::USD);
        $this->assertEqualsWithDelta(8.00, (float) $wallet->fresh()->balance, 0.001);
    }

    public function test_failed_momo_order_is_refunded_to_the_customer_wallet(): void
    {
        $item = $this->failedMomoOrder(6510.00, 'XAF'); // ~$10 in XAF

        app(GatewayRefundService::class)->refundFailedItemToWallet($item);

        $wallet = app(WalletService::class)->getOrCreateWallet($item->order->user, Currency::XAF);
        $this->assertEqualsWithDelta(6510.00, (float) $wallet->fresh()->balance, 0.01);

        $tx = WalletTransaction::where('user_id', $item->order->user_id)
            ->where('transaction_category', TransactionCategory::Refund)
            ->first();
        $this->assertNotNull($tx);
        $this->assertEqualsWithDelta(6510.00, (float) $tx->amount, 0.01);
    }

    public function test_refund_is_idempotent_across_retries(): void
    {
        $item = $this->failedMomoOrder(6510.00, 'XAF');

        $service = app(GatewayRefundService::class);
        $service->refundFailedItemToWallet($item);
        $service->refundFailedItemToWallet($item); // retry / second failure path
        $service->refundFailedItemToWallet($item);

        $wallet = app(WalletService::class)->getOrCreateWallet($item->order->user, Currency::XAF);
        // Credited exactly once despite three calls.
        $this->assertEqualsWithDelta(6510.00, (float) $wallet->fresh()->balance, 0.01);
        $this->assertSame(1, WalletTransaction::where('user_id', $item->order->user_id)->count());
    }

    public function test_wallet_paid_orders_are_left_to_the_wallet_reversal_path(): void
    {
        $item = $this->failedMomoOrder(10.00, 'USD');
        // Flip the attempt to a wallet payment - the gateway refund service
        // must NOT act (WalletPaymentProvider handles those).
        $item->order->paymentAttempts()->update(['gateway' => 'wallet']);

        app(GatewayRefundService::class)->refundFailedItemToWallet($item->fresh());

        $this->assertSame(0, WalletTransaction::where('user_id', $item->order->user_id)->count());
    }
}
