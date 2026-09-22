<?php

namespace Tests\Feature;

use App\Domain\Fulfillment\Enums\FulfillmentStatus;
use App\Domain\Fulfillment\Providers\AiraloFulfillmentProvider;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Payment\Enums\PaymentStatus;
use App\Domain\Shared\Enums\FundingStatus;
use App\Models\Category;
use App\Models\CurrencyRate;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PaymentDecline;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\RiskLabel;
use App\Models\SiteSetting;
use App\Models\Subcategory;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletFunding;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * eSIM top-up payment: any active wallet can pay, as long as its balance covers
 * the USD price converted into that wallet's currency. Recoverable states
 * (nothing covers it, package gone, provider hiccup) show a friendly message,
 * never a raw "422 Unprocessable Content" error page.
 */
class EsimTopupTest extends TestCase
{
    use RefreshDatabase;

    private function fulfilledEsimItem(User $user): OrderItem
    {
        $category = Category::firstOrCreate(['slug' => 'esims'], ['name' => 'eSIMs', 'type' => 'digital']);

        $product = Product::create([
            'category_id' => $category->id,
            'provider_name' => 'airalo',
            'brand_key' => 'TestEsim',
            'country_code' => 'US',
            'currency_code' => 'USD',
            'name' => 'Test eSIM',
            'slug' => 'test-esim',
            'is_active' => true,
        ]);

        $variant = ProductVariant::factory()->for($product)->create();
        $subcategory = Subcategory::factory()->create(['category_id' => $category->id]);

        $order = Order::create([
            'user_id' => $user->id,
            'order_number' => 'RSR-PARENT-ESIM-1',
            'settlement_currency' => 'USD',
            'display_currency' => 'USD',
            'subtotal_amount' => 0,
            'markup_amount' => 0,
            'total_amount' => 0,
            'payment_method' => 'wallet',
            'payment_status' => PaymentStatus::Paid,
            'fulfillment_status' => FulfillmentStatus::Fulfilled,
            'order_status' => OrderStatus::Completed,
        ]);

        return OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'category_id' => $category->id,
            'subcategory_id' => $subcategory->id,
            'provider_name' => 'airalo',
            'quantity' => 1,
            'display_currency' => 'USD',
            'display_amount' => 5.00,
            'provider_cost_usd' => 5.00,
            'markup_amount' => 0,
            'subtotal_amount' => 5.00,
            'fulfillment_status' => FulfillmentStatus::Fulfilled,
            'fulfillment_payload' => ['iccid' => '8944000000000000001'],
        ]);
    }

    private function pinnedUser(): User
    {
        return User::factory()->create([
            'transaction_pin' => Hash::make('1234'),
            'transaction_pin_set_at' => now(),
        ]);
    }

    public function test_topup_can_be_paid_from_a_non_usd_wallet_with_the_usd_equivalent(): void
    {
        Queue::fake();
        CurrencyRate::create(['code' => 'USD', 'name' => 'US Dollar', 'type' => 'fiat', 'rate_per_usd' => 1.0, 'is_active' => true]);
        CurrencyRate::create(['code' => 'XAF', 'name' => 'CFA Franc', 'type' => 'fiat', 'rate_per_usd' => 600.0, 'is_active' => true]);

        $user = $this->pinnedUser();
        // Only a local-currency wallet, no USD wallet - but plenty of balance.
        $wallet = Wallet::create(['user_id' => $user->id, 'currency' => 'XAF', 'balance' => 1_000_000, 'is_active' => true]);

        $item = $this->fulfilledEsimItem($user);

        $this->mock(AiraloFulfillmentProvider::class)
            ->shouldReceive('listTopupsForIccid')
            ->andReturn([['id' => 'topup_1gb', 'net_price' => 5.0]]);

        $response = $this->actingAs($user)->post(
            route('dashboard.esim.topup.purchase', $item),
            ['package_id' => 'topup_1gb', 'net_price' => 5.0, 'wallet_pin' => '1234'],
        );

        // Succeeds: a new top-up order is created and the XAF wallet is debited.
        $response->assertSessionHasNoErrors();

        $topupOrder = Order::where('user_id', $user->id)
            ->where('order_number', '!=', 'RSR-PARENT-ESIM-1')
            ->latest()->first();

        $this->assertNotNull($topupOrder);
        $this->assertSame('XAF', $topupOrder->metadata['paid_from_wallet_currency']);
        $this->assertLessThan(1_000_000, (float) $wallet->fresh()->balance);
    }

    public function test_topup_with_no_wallet_covering_the_price_shows_a_friendly_error_not_a_422(): void
    {
        $user = $this->pinnedUser();
        // A USD wallet, but far too little to cover the ~$5.50 top-up.
        Wallet::create(['user_id' => $user->id, 'currency' => 'USD', 'balance' => 1.00, 'is_active' => true]);

        $item = $this->fulfilledEsimItem($user);

        $this->mock(AiraloFulfillmentProvider::class)
            ->shouldReceive('listTopupsForIccid')
            ->andReturn([['id' => 'topup_1gb', 'net_price' => 5.0]]);

        $response = $this->actingAs($user)->post(
            route('dashboard.esim.topup.purchase', $item),
            ['package_id' => 'topup_1gb', 'net_price' => 5.0, 'wallet_pin' => '1234'],
        );

        // Friendly redirect with a specific message - NOT the raw 422 page.
        $response->assertRedirect(route('dashboard.esim.topup', $item));
        $response->assertSessionHasErrors('package_id');
        $this->assertNotSame(422, $response->status());
    }

    private function riskFlags(bool $master, bool $enforce): void
    {
        SiteSetting::put('features.risk_engine_enabled', $master ? 'on' : 'off', 'features');
        SiteSetting::put('features.risk_engine_enforce_enabled', $enforce ? 'on' : 'off', 'features');
        Cache::flush();
    }

    public function test_a_risk_denied_customer_cannot_drain_the_wallet_through_topups(): void
    {
        Queue::fake();
        $this->riskFlags(master: true, enforce: true);
        CurrencyRate::create(['code' => 'USD', 'name' => 'US Dollar', 'type' => 'fiat', 'rate_per_usd' => 1.0, 'is_active' => true]);

        $user = $this->pinnedUser();
        $wallet = Wallet::create(['user_id' => $user->id, 'currency' => 'USD', 'balance' => 50.0, 'is_active' => true]);
        $item = $this->fulfilledEsimItem($user);

        // Confirmed fraud forces a deny through the override map.
        RiskLabel::create([
            'user_id' => $user->id,
            'label' => RiskLabel::LABEL_CONFIRMED_FRAUD,
            'source' => 'admin',
        ]);

        $this->mock(AiraloFulfillmentProvider::class)
            ->shouldReceive('listTopupsForIccid')
            ->andReturn([['id' => 'topup_1gb', 'net_price' => 5.0]]);

        $response = $this->actingAs($user)->post(
            route('dashboard.esim.topup.purchase', $item),
            ['package_id' => 'topup_1gb', 'net_price' => 5.0, 'wallet_pin' => '1234'],
        );

        // Refused with a friendly message; no order written, nothing debited.
        $response->assertRedirect(route('dashboard.esim.topup', $item));
        $response->assertSessionHasErrors('package_id');
        $this->assertNull(
            Order::where('user_id', $user->id)->where('order_number', '!=', 'RSR-PARENT-ESIM-1')->first(),
        );
        $this->assertSame(50.0, (float) $wallet->fresh()->balance);
    }

    /**
     * A top-up spends a balance the customer already holds. The payment rate
     * limiter only stops new money coming in, so even a device locked for
     * running stolen cards can still spend what is already in its wallet.
     * Freezing funds is a separate, human decision: the admin wallet hold.
     */
    public function test_a_locked_device_can_still_spend_its_wallet_balance_on_topups(): void
    {
        Queue::fake();
        SiteSetting::put('features.payment_backoff_enforce_enabled', 'on', 'features');
        Cache::flush();
        CurrencyRate::create(['code' => 'USD', 'name' => 'US Dollar', 'type' => 'fiat', 'rate_per_usd' => 1.0, 'is_active' => true]);

        $user = $this->pinnedUser();
        $wallet = Wallet::create(['user_id' => $user->id, 'currency' => 'USD', 'balance' => 50.0, 'is_active' => true]);
        $item = $this->fulfilledEsimItem($user);

        // Five different cards, all refused as fraud, on this account.
        foreach (['a', 'b', 'c', 'd', 'e'] as $card) {
            PaymentDecline::create([
                'user_id' => $user->id,
                'product_line' => 'checkout',
                'gateway' => 'flutterwave',
                'gateway_transaction_id' => 'drain-'.$card,
                'tx_ref' => 'ref-'.$user->id,
                'card_fingerprint' => 'card-'.$card,
                'verdict' => 'Payment declined. Suspected Fraud',
                'verdict_class' => 'fraud',
                'amount' => 25,
                'currency' => 'USD',
                'declined_at' => now()->subSeconds(5),
            ]);
        }

        $this->mock(AiraloFulfillmentProvider::class)
            ->shouldReceive('listTopupsForIccid')
            ->andReturn([['id' => 'topup_1gb', 'net_price' => 5.0]]);

        $response = $this->actingAs($user)->post(
            route('dashboard.esim.topup.purchase', $item),
            ['package_id' => 'topup_1gb', 'net_price' => 5.0, 'wallet_pin' => '1234'],
        );

        $response->assertSessionHasNoErrors();
        $this->assertLessThan(50.0, (float) $wallet->fresh()->balance, 'a locked device was stopped from spending its own balance');
        $this->assertNotNull(
            Order::where('user_id', $user->id)->where('order_number', '!=', 'RSR-PARENT-ESIM-1')->first(),
            'no top-up order was written',
        );
    }

    /**
     * A top-up is paid from wallet balance: money that was counted when it was
     * funded. It adds nothing to the transaction caps or the cumulative KYC
     * dial, so a customer sitting exactly at their daily cap can still spend
     * what they hold. (Counted as new money, this $5 would read $505 against
     * the $500 cap and be refused.)
     */
    public function test_a_customer_at_their_transaction_cap_can_still_top_up_from_wallet(): void
    {
        Queue::fake();
        SiteSetting::put('features.spending_limits_enforce_enabled', 'on', 'features');
        SiteSetting::put('features.kyc_enforce_enabled', 'on', 'features');
        SiteSetting::put('features.kyc_verification_enabled', 'on', 'features');
        Cache::flush();
        CurrencyRate::create(['code' => 'USD', 'name' => 'US Dollar', 'type' => 'fiat', 'rate_per_usd' => 1.0, 'is_active' => true]);

        $user = $this->pinnedUser();
        $wallet = Wallet::create(['user_id' => $user->id, 'currency' => 'USD', 'balance' => 500.0, 'is_active' => true]);
        $item = $this->fulfilledEsimItem($user);

        // $500 came in today: exactly the standard daily cap.
        WalletFunding::create([
            'user_id' => $user->id,
            'wallet_id' => $wallet->id,
            'reference' => 'FUND-ATCAP',
            'currency' => 'USD',
            'amount' => 500,
            'settled_amount_usd' => 500,
            'gateway' => 'flutterwave',
            'status' => FundingStatus::Completed,
            'completed_at' => now(),
        ]);

        $this->mock(AiraloFulfillmentProvider::class)
            ->shouldReceive('listTopupsForIccid')
            ->andReturn([['id' => 'topup_1gb', 'net_price' => 5.0]]);

        $response = $this->actingAs($user)->post(
            route('dashboard.esim.topup.purchase', $item),
            ['package_id' => 'topup_1gb', 'net_price' => 5.0, 'wallet_pin' => '1234'],
        );

        $response->assertSessionHasNoErrors();
        $this->assertLessThan(500.0, (float) $wallet->fresh()->balance, 'a customer at the cap was stopped from spending their own balance');
    }

    public function test_shadow_mode_records_an_assessment_and_lets_the_topup_through(): void
    {
        Queue::fake();
        $this->riskFlags(master: true, enforce: false);
        CurrencyRate::create(['code' => 'USD', 'name' => 'US Dollar', 'type' => 'fiat', 'rate_per_usd' => 1.0, 'is_active' => true]);

        $user = $this->pinnedUser();
        Wallet::create(['user_id' => $user->id, 'currency' => 'USD', 'balance' => 50.0, 'is_active' => true]);
        $item = $this->fulfilledEsimItem($user);

        $this->mock(AiraloFulfillmentProvider::class)
            ->shouldReceive('listTopupsForIccid')
            ->andReturn([['id' => 'topup_1gb', 'net_price' => 5.0]]);

        $response = $this->actingAs($user)->post(
            route('dashboard.esim.topup.purchase', $item),
            ['package_id' => 'topup_1gb', 'net_price' => 5.0, 'wallet_pin' => '1234'],
        );

        $response->assertSessionHasNoErrors();

        // The top-up now leaves the same audit trail as a checkout.
        $this->assertDatabaseHas('risk_assessments', [
            'user_id' => $user->id,
            'context' => 'order',
            'subject_type' => 'order_item',
            'subject_id' => (string) $item->id,
        ]);
    }

    public function test_topup_is_priced_off_airalo_retail_price_not_the_wholesale_net_price(): void
    {
        Queue::fake();
        CurrencyRate::create(['code' => 'USD', 'name' => 'US Dollar', 'type' => 'fiat', 'rate_per_usd' => 1.0, 'is_active' => true]);

        $user = $this->pinnedUser();
        $wallet = Wallet::create(['user_id' => $user->id, 'currency' => 'USD', 'balance' => 50.0, 'is_active' => true]);

        $item = $this->fulfilledEsimItem($user);

        // Airalo package: $5 wholesale (net_price) but $10 recommended retail (price).
        // The store marks up `price`; the top-up must do the same, not undercut it.
        $this->mock(AiraloFulfillmentProvider::class)
            ->shouldReceive('listTopupsForIccid')
            ->andReturn([['id' => 'topup_1gb', 'net_price' => 5.0, 'price' => 10.0]]);

        $response = $this->actingAs($user)->post(
            route('dashboard.esim.topup.purchase', $item),
            ['package_id' => 'topup_1gb', 'net_price' => 5.0, 'wallet_pin' => '1234'],
        );

        $response->assertSessionHasNoErrors();

        $topupOrder = Order::where('user_id', $user->id)
            ->where('order_number', '!=', 'RSR-PARENT-ESIM-1')
            ->latest()->first();
        $this->assertNotNull($topupOrder);

        // Priced off the $10 retail base (marked up), NOT the $5 wholesale net_price.
        // A net_price basis would land ~$5.50; a retail basis is always >= $10.
        $this->assertGreaterThanOrEqual(10.0, (float) $topupOrder->subtotal_amount);

        // net_price is still recorded as the true cost, and margin is measured
        // against that cost - not against the retail base.
        $topupItem = OrderItem::where('order_id', $topupOrder->id)->first();
        $this->assertSame(5.0, (float) $topupItem->provider_cost_usd);
        $this->assertEqualsWithDelta((float) $topupOrder->subtotal_amount - 5.0, (float) $topupItem->markup_amount, 0.01);
    }
}
