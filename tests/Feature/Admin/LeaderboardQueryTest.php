<?php

namespace Tests\Feature\Admin;

use App\Domain\Admin\Queries\LeaderboardQuery;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Subcategory;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class LeaderboardQueryTest extends TestCase
{
    use RefreshDatabase;

    private function makeOrder(User $user, float $totalUsd, ?Carbon $at = null, string $categorySlug = 'gift-cards'): Order
    {
        $at ??= now();

        $order = Order::create([
            'user_id' => $user->id,
            'order_number' => 'RSR-LB-'.Str::random(8),
            'settlement_currency' => 'USD',
            'display_currency' => 'USD',
            'subtotal_amount' => $totalUsd,
            'markup_amount' => 0,
            'total_amount' => $totalUsd,
            'payment_method' => 'wallet',
            'payment_status' => 'paid',
            'fulfillment_status' => 'fulfilled',
            'order_status' => 'completed',
            'placed_at' => $at,
            'completed_at' => $at,
            'metadata' => ['exchange_rate' => 1.0, 'settlement_total_usd' => $totalUsd],
        ]);

        // created_at is not mass-assignable — back-date it directly so
        // period/dormancy filters (which read orders.created_at) see $at.
        DB::table('orders')->where('id', $order->id)->update(['created_at' => $at, 'updated_at' => $at]);

        $category = Category::firstOrCreate(
            ['slug' => $categorySlug],
            ['name' => ucwords(str_replace('-', ' ', $categorySlug)), 'type' => 'digital', 'is_active' => true, 'sort_order' => 0],
        );
        $subcategory = Subcategory::factory()->create(['category_id' => $category->id]);
        $product = Product::factory()->create(['category_id' => $category->id, 'provider_name' => 'zendit']);
        $variant = ProductVariant::factory()->create(['product_id' => $product->id]);

        OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'category_id' => $category->id,
            'subcategory_id' => $subcategory->id,
            'provider_name' => 'zendit',
            'quantity' => 1,
            'display_currency' => 'USD',
            'display_amount' => $totalUsd,
            'provider_cost_usd' => round($totalUsd * 0.7, 2),
            'markup_amount' => 0,
            'subtotal_amount' => $totalUsd,
            'fulfillment_status' => 'fulfilled',
        ]);

        return $order;
    }

    private function boards(): array
    {
        return app(LeaderboardQuery::class)->boards(null);
    }

    public function test_top_customers_ranks_by_order_count_and_excludes_guests(): void
    {
        $alice = User::factory()->create(['name' => 'Alice Many-Orders']);
        $bob = User::factory()->create(['name' => 'Bob One-Big']);
        $guest = User::factory()->create(['name' => 'Guest Vehicle', 'is_guest' => true]);

        $this->makeOrder($alice, 10.00);
        $this->makeOrder($alice, 10.00);
        $this->makeOrder($alice, 10.00);
        $this->makeOrder($bob, 50.00);
        $this->makeOrder($guest, 99.00);
        $this->makeOrder($guest, 99.00);
        $this->makeOrder($guest, 99.00);
        $this->makeOrder($guest, 99.00);

        $board = $this->boards()['top_customers'];

        $this->assertSame($alice->id, $board[0]['user']['id']);
        $this->assertSame(3, $board[0]['orders']);
        $this->assertSame($bob->id, $board[1]['user']['id']);
        $this->assertNotContains($guest->id, array_column(array_column($board, 'user'), 'id'));
    }

    public function test_top_customers_reports_usd_spend_alongside_order_count(): void
    {
        $alice = User::factory()->create();

        $this->makeOrder($alice, 10.00);
        $this->makeOrder($alice, 15.00);

        $board = $this->boards()['top_customers'];

        $this->assertSame($alice->id, $board[0]['user']['id']);
        $this->assertEqualsWithDelta(25.00, $board[0]['spend_usd'], 0.01);
    }

    public function test_returning_users_requires_at_least_two_orders(): void
    {
        $repeat = User::factory()->create();
        $oneOff = User::factory()->create();

        $this->makeOrder($repeat, 10.00);
        $this->makeOrder($repeat, 10.00);
        $this->makeOrder($oneOff, 10.00);

        $ids = array_column(array_column($this->boards()['returning_users'], 'user'), 'id');

        $this->assertContains($repeat->id, $ids);
        $this->assertNotContains($oneOff->id, $ids);
    }

    public function test_dormant_users_are_past_buyers_with_no_recent_orders(): void
    {
        $dormant = User::factory()->create();
        $active = User::factory()->create();

        $this->makeOrder($dormant, 40.00, now()->subDays(90));
        $this->makeOrder($active, 10.00, now()->subDays(90));
        $this->makeOrder($active, 10.00);

        $ids = array_column(array_column($this->boards()['dormant_users'], 'user'), 'id');

        $this->assertContains($dormant->id, $ids);
        $this->assertNotContains($active->id, $ids);
    }

    public function test_most_failed_transactions_counts_failed_payment_attempts(): void
    {
        $struggling = User::factory()->create();
        $fine = User::factory()->create();

        $strugglingOrder = $this->makeOrder($struggling, 10.00);
        $fineOrder = $this->makeOrder($fine, 10.00);

        foreach ([[$struggling, $strugglingOrder, 3], [$fine, $fineOrder, 1]] as [$user, $order, $count]) {
            for ($i = 0; $i < $count; $i++) {
                DB::table('payment_attempts')->insert([
                    'id' => (string) Str::uuid(),
                    'order_id' => $order->id,
                    'user_id' => $user->id,
                    'gateway' => 'flutterwave',
                    'idempotency_key' => 'lb-fail-'.$user->id.'-'.$i,
                    'currency' => 'USD',
                    'amount' => 10.00,
                    'payment_status' => 'failed',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        $board = $this->boards()['most_failed_transactions'];

        $this->assertSame($struggling->id, $board[0]['user']['id']);
        $this->assertSame(3, $board[0]['failures']);
    }

    public function test_highest_wallet_balance_converts_and_ranks(): void
    {
        $rich = User::factory()->create();
        $modest = User::factory()->create();

        Wallet::factory()->create(['user_id' => $rich->id, 'currency' => 'USD', 'balance' => 200.00]);
        Wallet::factory()->create(['user_id' => $modest->id, 'currency' => 'USD', 'balance' => 50.00]);

        $board = $this->boards()['highest_wallet_balance'];

        $this->assertSame($rich->id, $board[0]['user']['id']);
        $this->assertEqualsWithDelta(200.00, $board[0]['balance_usd'], 0.01);
    }

    public function test_most_referrals_ranks_referrers(): void
    {
        $referrer = User::factory()->create();
        $referred1 = User::factory()->create();
        $referred2 = User::factory()->create();

        DB::table('referrals')->insert([
            ['referrer_id' => $referrer->id, 'referred_user_id' => $referred1->id, 'status' => 'active', 'total_rewards_generated' => 0, 'total_orders_completed' => 2, 'created_at' => now(), 'updated_at' => now()],
            ['referrer_id' => $referrer->id, 'referred_user_id' => $referred2->id, 'status' => 'active', 'total_rewards_generated' => 0, 'total_orders_completed' => 1, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $board = $this->boards()['most_referrals'];

        $this->assertSame($referrer->id, $board[0]['user']['id']);
        $this->assertSame(2, $board[0]['referrals']);
        $this->assertSame(3, $board[0]['referred_orders']);
    }

    public function test_category_boards_count_units_for_their_slugs_only(): void
    {
        $cardBuyer = User::factory()->create();
        $esimBuyer = User::factory()->create();

        $this->makeOrder($cardBuyer, 25.00, null, 'gift-cards');
        $this->makeOrder($esimBuyer, 15.00, null, 'esims');

        $boards = $this->boards();

        $giftIds = array_column(array_column($boards['most_gift_cards'], 'user'), 'id');
        $esimIds = array_column(array_column($boards['most_esims'], 'user'), 'id');

        $this->assertContains($cardBuyer->id, $giftIds);
        $this->assertNotContains($cardBuyer->id, $esimIds);
        $this->assertContains($esimBuyer->id, $esimIds);
    }

    public function test_period_filter_limits_activity_boards(): void
    {
        $old = User::factory()->create();
        $recent = User::factory()->create();

        $this->makeOrder($old, 100.00, now()->subDays(45));
        $this->makeOrder($recent, 20.00, now()->subDays(2));

        $board = app(LeaderboardQuery::class)->boards(now()->subDays(30))['top_customers'];
        $ids = array_column(array_column($board, 'user'), 'id');

        $this->assertContains($recent->id, $ids);
        $this->assertNotContains($old->id, $ids);
    }
}
