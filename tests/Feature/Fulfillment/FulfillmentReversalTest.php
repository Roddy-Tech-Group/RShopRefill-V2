<?php

namespace Tests\Feature\Fulfillment;

use App\Domain\Fulfillment\Enums\FulfillmentStatus;
use App\Domain\Fulfillment\Interfaces\FulfillmentProviderInterface;
use App\Domain\Fulfillment\Services\FulfillmentProviderFactory;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Services\OrderService;
use App\Domain\Payment\Enums\PaymentStatus;
use App\Domain\Payment\Providers\WalletPaymentProvider;
use App\Domain\Rewards\Services\RewardEngine;
use App\Domain\Shared\Enums\Currency;
use App\Domain\Shared\Enums\TransactionCategory;
use App\Domain\Wallet\Services\WalletService;
use App\Jobs\FulfillOrderItemJob;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PaymentAttempt;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Subcategory;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Money outcomes of the fulfilment failure paths on a wallet-funded order.
 *
 * A wallet order holds the customer's cash in `locked_balance` until every item
 * resolves; the reservation is then either debited (goods delivered) or unlocked
 * (nothing delivered). Every test here asserts the wallet figures, because a
 * reversal that fires at the wrong moment either gives away vouchers for free or
 * pays a customer back twice.
 */
class FulfillmentReversalTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Wallet $wallet;

    private Category $category;

    private Subcategory $subcategory;

    private Product $product;

    private ProductVariant $variant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

        $this->wallet = Wallet::create([
            'user_id' => $this->user->id,
            'currency' => Currency::USD,
            'balance' => 100.00,
            'locked_balance' => 0.00,
            'is_active' => true,
        ]);

        $this->category = Category::factory()->create(['slug' => 'gift-cards']);
        $this->subcategory = Subcategory::factory()->create(['category_id' => $this->category->id]);
        $this->product = Product::factory()->create(['category_id' => $this->category->id]);
        $this->variant = ProductVariant::factory()->create(['product_id' => $this->product->id]);
    }

    /**
     * An order whose wallet payment is Reserved, exactly as the PIN flow leaves it:
     * funds locked, attempt Reserved, order Paid + Processing.
     *
     * $reservedAmount defaults to the order total; pass a smaller figure to model a
     * reservation that a failure path already shrank with releasePartialFunds().
     */
    private function reservedWalletOrder(float $total, ?float $reservedAmount = null): Order
    {
        $reserved = $reservedAmount ?? $total;

        $order = Order::create([
            'user_id' => $this->user->id,
            'order_number' => 'RSR-'.Str::upper(Str::random(8)),
            'settlement_currency' => 'USD',
            'display_currency' => 'USD',
            'subtotal_amount' => $total,
            'markup_amount' => 0,
            'total_amount' => $total,
            'payment_method' => 'wallet',
            'payment_status' => PaymentStatus::Paid,
            'fulfillment_status' => FulfillmentStatus::NotStarted,
            'order_status' => OrderStatus::Processing,
            'placed_at' => now(),
            'metadata' => ['exchange_rate' => 1.0, 'settlement_total_usd' => $total],
        ]);

        PaymentAttempt::create([
            'order_id' => $order->id,
            'user_id' => $this->user->id,
            'gateway' => 'wallet',
            'idempotency_key' => 'PAY-'.Str::uuid()->toString(),
            'currency' => 'USD',
            'amount' => $reserved,
            'exchange_rate_snapshot' => 1.0,
            'payment_status' => PaymentStatus::Reserved,
            'gateway_reference' => 'WL-LOCK-'.Str::random(6),
        ]);

        app(WalletService::class)->lockFunds($this->wallet, $reserved);

        return $order;
    }

    private function addItem(Order $order, float $subtotal, FulfillmentStatus $status = FulfillmentStatus::NotStarted): OrderItem
    {
        return OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $this->product->id,
            'product_variant_id' => $this->variant->id,
            'category_id' => $this->category->id,
            'subcategory_id' => $this->subcategory->id,
            'provider_name' => 'zendit',
            'provider_offer_id' => 'OFFER-1',
            'quantity' => 1,
            'display_currency' => 'USD',
            'display_amount' => $subtotal,
            'provider_cost_usd' => 9.00,
            'markup_amount' => 0,
            'subtotal_amount' => $subtotal,
            'product_snapshot' => ['name' => 'Test Gift Card', 'category' => ['slug' => 'gift-cards']],
            'variant_snapshot' => ['face_value' => $subtotal, 'currency' => 'USD'],
            'fulfillment_status' => $status,
            'delivered_at' => $status === FulfillmentStatus::Fulfilled ? now() : null,
            'failed_at' => $status === FulfillmentStatus::Failed ? now() : null,
        ]);
    }

    private function fakeProvider(FulfillmentProviderInterface $provider): void
    {
        $factory = $this->createStub(FulfillmentProviderFactory::class);
        $factory->method('getProvider')->willReturn($provider);
        $this->app->instance(FulfillmentProviderFactory::class, $factory);
    }

    /**
     * Fails only the given item; every other item is delivered normally.
     */
    private function providerFailingFor(string $failingItemId): FulfillmentProviderInterface
    {
        return new class($failingItemId) implements FulfillmentProviderInterface
        {
            public function __construct(private string $failingItemId) {}

            public function fulfill(OrderItem $item): array
            {
                if ($item->id === $this->failingItemId) {
                    return ['status' => FulfillmentStatus::Failed, 'reference' => 'ZND-FAIL', 'payload' => []];
                }

                return ['status' => FulfillmentStatus::Fulfilled, 'reference' => 'ZND-OK', 'payload' => ['pins' => ['ABC-123']]];
            }

            public function verifyStatus(OrderItem $item): array
            {
                return ['status' => FulfillmentStatus::Fulfilled, 'payload' => []];
            }

            public function refund(OrderItem $item): bool
            {
                return true;
            }

            public function normalizeResponse(array $rawPayload): array
            {
                return $rawPayload;
            }
        };
    }

    /**
     * Blows up on the first $throwTimes calls (a timeout / 500 from the supplier),
     * then delivers - the shape of a transient failure that a retry recovers.
     */
    private function providerThrowing(int $throwTimes): FulfillmentProviderInterface
    {
        return new class($throwTimes) implements FulfillmentProviderInterface
        {
            public int $calls = 0;

            public function __construct(private int $throwTimes) {}

            public function fulfill(OrderItem $item): array
            {
                $this->calls++;

                if ($this->calls <= $this->throwTimes) {
                    throw new \RuntimeException('Supplier gateway timed out');
                }

                return ['status' => FulfillmentStatus::Fulfilled, 'reference' => 'ZND-OK', 'payload' => ['pins' => ['ABC-123']]];
            }

            public function verifyStatus(OrderItem $item): array
            {
                return ['status' => FulfillmentStatus::Fulfilled, 'payload' => []];
            }

            public function refund(OrderItem $item): bool
            {
                return true;
            }

            public function normalizeResponse(array $rawPayload): array
            {
                return $rawPayload;
            }
        };
    }

    private function runJob(OrderItem $item): void
    {
        (new FulfillOrderItemJob($item))->handle(
            app(FulfillmentProviderFactory::class),
            app(OrderService::class),
            app(WalletPaymentProvider::class)
        );
    }

    private function attemptFor(Order $order): PaymentAttempt
    {
        return $order->paymentAttempts()->firstOrFail();
    }

    /** Defect (a): a not-yet-started sibling must not be read as a total failure. */
    public function test_failed_item_keeps_the_reservation_while_a_sibling_has_not_started(): void
    {
        $order = $this->reservedWalletOrder(22.00);
        $failing = $this->addItem($order, 11.00);
        $sibling = $this->addItem($order, 11.00);

        $this->fakeProvider($this->providerFailingFor($failing->id));

        $this->runJob($failing);

        // The sibling will still buy a real voucher, so the money must stay put.
        $wallet = $this->wallet->fresh();
        $this->assertEquals(22.00, (float) $wallet->locked_balance, 'The full reservation must survive a partial failure');
        $this->assertEquals(100.00, (float) $wallet->balance);
        $this->assertSame(PaymentStatus::Reserved, $this->attemptFor($order)->payment_status);
        $this->assertNotSame(OrderStatus::Failed, $order->fresh()->order_status);

        // Sibling delivers: the customer pays for what they received, and only that.
        $this->runJob($sibling);

        $this->assertSame(FulfillmentStatus::Fulfilled, $sibling->fresh()->fulfillment_status);

        $wallet = $this->wallet->fresh();
        $this->assertEquals(89.00, (float) $wallet->balance, 'Customer must be charged for the delivered item');
        $this->assertEquals(0.00, (float) $wallet->locked_balance);
    }

    /** Defect (b): a transient crash must not hand the money back before the retry. */
    public function test_transient_crash_keeps_funds_reserved_and_a_retry_still_charges(): void
    {
        $order = $this->reservedWalletOrder(11.00);
        $item = $this->addItem($order, 11.00);

        $this->fakeProvider($this->providerThrowing(1));

        try {
            $this->runJob($item);
            $this->fail('The job must rethrow so the queue can retry it.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Supplier gateway timed out', $e->getMessage());
        }

        $wallet = $this->wallet->fresh();
        $this->assertEquals(11.00, (float) $wallet->locked_balance, 'Funds must stay reserved while a retry is pending');
        $this->assertEquals(100.00, (float) $wallet->balance);
        $this->assertSame(PaymentStatus::Reserved, $this->attemptFor($order)->payment_status);

        // The retry succeeds and delivers - the customer must actually be charged.
        $this->runJob($item);

        $this->assertSame(FulfillmentStatus::Fulfilled, $item->fresh()->fulfillment_status);

        $wallet = $this->wallet->fresh();
        $this->assertEquals(89.00, (float) $wallet->balance);
        $this->assertEquals(0.00, (float) $wallet->locked_balance);
        $this->assertSame(PaymentStatus::Paid, $this->attemptFor($order)->payment_status);
    }

    /** Defect (b): the reversal belongs to failed(), and happens once. */
    public function test_funds_are_released_once_when_retries_are_exhausted(): void
    {
        $order = $this->reservedWalletOrder(11.00);
        $item = $this->addItem($order, 11.00);

        $this->fakeProvider($this->providerThrowing(99));
        $job = new FulfillOrderItemJob($item);

        try {
            $this->runJob($item);
        } catch (\RuntimeException) {
            // expected - the queue would retry
        }

        $this->assertEquals(11.00, (float) $this->wallet->fresh()->locked_balance);

        $job->failed(new \RuntimeException('Supplier gateway timed out'));

        $wallet = $this->wallet->fresh();
        $this->assertEquals(0.00, (float) $wallet->locked_balance);
        $this->assertEquals(100.00, (float) $wallet->balance);
        $this->assertSame(PaymentStatus::Failed, $this->attemptFor($order)->payment_status);

        // A second permanent-failure callback must not pay the customer again.
        $job->failed(new \RuntimeException('Supplier gateway timed out'));

        $wallet = $this->wallet->fresh();
        $this->assertEquals(0.00, (float) $wallet->locked_balance);
        $this->assertEquals(100.00, (float) $wallet->balance);
        $this->assertSame(0, WalletTransaction::where('user_id', $this->user->id)->count());
    }

    /**
     * Defect (c): the failed item's share was already unlocked by the failure path
     * (releasePartialFunds shrinks the reservation), so settling the order must not
     * refund it a second time.
     */
    public function test_partially_fulfilled_order_reverses_the_failed_item_exactly_once(): void
    {
        // 22.00 order, the failed item's 11.00 already released: 11.00 still reserved.
        $order = $this->reservedWalletOrder(22.00, 11.00);
        $this->addItem($order, 11.00, FulfillmentStatus::Failed);
        $this->addItem($order, 11.00, FulfillmentStatus::Fulfilled);

        app(OrderService::class)->transitionFulfillmentStatus($order, FulfillmentStatus::PartiallyFulfilled);

        $wallet = $this->wallet->fresh();
        $this->assertEquals(89.00, (float) $wallet->balance, 'Only the delivered item may be charged');
        $this->assertEquals(0.00, (float) $wallet->locked_balance);

        $this->assertSame(
            0,
            WalletTransaction::where('user_id', $this->user->id)
                ->where('transaction_category', TransactionCategory::Refund)
                ->count(),
            'The released share must not be refunded on top of the release'
        );

        // Re-running the settlement (two items resolving at once) changes nothing.
        app(OrderService::class)->transitionFulfillmentStatus($order->fresh(), FulfillmentStatus::PartiallyFulfilled);

        $wallet = $this->wallet->fresh();
        $this->assertEquals(89.00, (float) $wallet->balance);
        $this->assertEquals(0.00, (float) $wallet->locked_balance);
    }

    /** Defect (d): rewards must not be dispatched from inside the open transaction. */
    public function test_rewards_are_not_processed_when_the_completing_transaction_rolls_back(): void
    {
        $spy = $this->spy(RewardEngine::class);

        $order = Order::create([
            'user_id' => $this->user->id,
            'order_number' => 'RSR-'.Str::upper(Str::random(8)),
            'settlement_currency' => 'USD',
            'display_currency' => 'USD',
            'subtotal_amount' => 11.00,
            'markup_amount' => 0,
            'total_amount' => 11.00,
            'payment_method' => 'card',
            'payment_status' => PaymentStatus::Paid,
            'fulfillment_status' => FulfillmentStatus::NotStarted,
            'order_status' => OrderStatus::Processing,
            'placed_at' => now(),
            'metadata' => ['exchange_rate' => 1.0, 'settlement_total_usd' => 11.00],
        ]);
        $this->addItem($order, 11.00, FulfillmentStatus::Fulfilled);

        DB::beginTransaction();
        app(OrderService::class)->transitionFulfillmentStatus($order, FulfillmentStatus::Fulfilled);
        DB::rollBack();

        // The completion never landed, so the cashback must never have been credited.
        $this->assertSame(OrderStatus::Processing, $order->fresh()->order_status);
        $spy->shouldNotHaveReceived('processOrderRewards');

        // Control: a committed completion still pays the rewards out.
        app(OrderService::class)->transitionFulfillmentStatus($order->fresh(), FulfillmentStatus::Fulfilled);

        $this->assertSame(OrderStatus::Completed, $order->fresh()->order_status);
        $spy->shouldHaveReceived('processOrderRewards')->once();
    }
}
