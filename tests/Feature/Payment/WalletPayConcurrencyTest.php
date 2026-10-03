<?php

namespace Tests\Feature\Payment;

use App\Domain\Order\Services\CheckoutService;
use App\Domain\Payment\Enums\PaymentStatus;
use App\Domain\Shared\Enums\Currency;
use App\Domain\Wallet\Services\TransactionPinService;
use App\Jobs\FulfillOrderItemJob;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Category;
use App\Models\Order;
use App\Models\PaymentSession;
use App\Models\PricingRule;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Subcategory;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * One wallet payment per session, however many taps.
 *
 * The PIN token is single-use, which stops the SAME tap running twice. Two
 * taps with two tokens (the PIN entered on two tabs) both passed the session's
 * terminal-state check before either had written, and both reserved the
 * amount; the second hold was never released. The session is now claimed into
 * 'processing' under a row lock before any money moves, so the second caller
 * finds it taken.
 *
 * Two requests cannot run at once inside a test, so the race is replayed at
 * its decisive moment: the second request arrives while the first holds the
 * session in 'processing'.
 */
class WalletPayConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    private const PIN = '5283';

    private User $user;

    private Cart $cart;

    private Wallet $wallet;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.zendit.api_key' => 'ZENDIT_API_KEY_MOCK',
            'services.flutterwave.secret_key' => 'FLW_SECRET_KEY_MOCK',
            'pricing.safety_markup_percent' => 10.0,
            'pricing.min_margin_percent' => 1.0,
        ]);

        Cache::flush();
        Queue::fake([FulfillOrderItemJob::class]);

        $this->user = User::factory()->create();
        app(TransactionPinService::class)->setupPin($this->user, self::PIN);

        $this->wallet = Wallet::create([
            'user_id' => $this->user->id,
            'currency' => Currency::USD,
            'balance' => 100.0,
            'locked_balance' => 0.0,
            'is_active' => true,
        ]);

        $category = Category::create(['name' => 'Gift Cards', 'slug' => 'gift-cards', 'type' => 'digital']);
        $subcategory = Subcategory::create(['category_id' => $category->id, 'name' => 'Gaming', 'slug' => 'gaming']);
        $product = Product::create([
            'category_id' => $category->id,
            'subcategory_id' => $subcategory->id,
            'provider_name' => 'zendit',
            'country_code' => 'US',
            'currency_code' => 'USD',
            'name' => 'Steam Gift Card',
            'slug' => 'steam-gift-card',
        ]);
        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'provider_offer_id' => 'steam-offer-10',
            'sku' => 'STEAM10',
            'currency' => 'USD',
            'face_value' => 10.00,
            'cost_price' => 9.50,
            'retail_price' => 10.50,
            'is_available' => true,
        ]);
        PricingRule::create(['markup_type' => 'fixed', 'markup_value' => 1.00, 'is_active' => true]);

        $this->cart = Cart::create(['user_id' => $this->user->id, 'status' => 'active', 'last_activity_at' => now()]);
        CartItem::create([
            'cart_id' => $this->cart->id,
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'quantity' => 1,
            'display_currency' => 'USD',
            'display_amount' => 11.00,
            'provider_cost_usd' => 9.50,
            'markup_amount' => 1.00,
            'unit_price_snapshot' => 11.00,
            'subtotal_snapshot' => 11.00,
        ]);
    }

    private function walletOrder(): Order
    {
        return app(CheckoutService::class)->placeOrder(
            user: $this->user,
            cart: $this->cart->load('items.product', 'items.variant'),
            paymentMethod: 'wallet',
            displayCurrency: 'USD',
        );
    }

    private function pinToken(): string
    {
        return $this->actingAs($this->user)
            ->postJson(route('api.wallets.pin.verify'), ['pin' => self::PIN])
            ->assertOk()
            ->json('auth_token');
    }

    private function pay(PaymentSession $session, string $token)
    {
        return $this->actingAs($this->user)->postJson(route('api.payment-sessions.pay', $session->id), [
            'method' => 'wallet',
            'details' => ['auth_token' => $token],
        ]);
    }

    /**
     * THE one. A second request that lands while the first is mid-flight is
     * refused before it touches the token or the balance.
     */
    public function test_a_second_authorisation_during_the_first_is_refused_and_moves_nothing(): void
    {
        $order = $this->walletOrder();
        $session = $order->paymentAttempts->first()->paymentSession;
        $secondToken = $this->pinToken();

        // The first request has claimed the session and is authorising.
        $session->update(['status' => 'processing']);

        $this->pay($session, $secondToken)
            ->assertStatus(409)
            ->assertJsonPath('message', 'That payment is already being processed. Please wait a moment.');

        $this->assertEquals(0.0, (float) $this->wallet->fresh()->locked_balance, 'the second request reserved funds');
        $this->assertSame('processing', $session->fresh()->status, 'the refusal must not move the session');
        $this->assertTrue(
            Cache::has("pin_auth_{$this->user->id}_{$secondToken}"),
            'the refused request must not burn the customer\'s token',
        );
    }

    public function test_a_single_authorisation_claims_the_session_and_reserves_once(): void
    {
        $order = $this->walletOrder();
        $session = $order->paymentAttempts->first()->paymentSession;
        $this->assertSame('awaiting_customer_action', $session->status);

        $this->pay($session, $this->pinToken())->assertOk();

        $this->assertSame('confirmed', $session->fresh()->status);
        $this->assertSame(PaymentStatus::Paid, $order->fresh()->payment_status);
        $this->assertEquals(11.0, (float) $this->wallet->fresh()->locked_balance);
    }

    /** After the first has finished, the second finds a settled session. */
    public function test_a_second_authorisation_after_the_first_finished_is_refused_and_holds_nothing_more(): void
    {
        $order = $this->walletOrder();
        $session = $order->paymentAttempts->first()->paymentSession;

        $this->pay($session, $this->pinToken())->assertOk();
        $this->pay($session, $this->pinToken())->assertStatus(400)->assertJsonPath('status', 'confirmed');

        $this->assertEquals(11.0, (float) $this->wallet->fresh()->locked_balance, 'the amount was reserved twice');
    }

    /**
     * A refused authorisation (bad or expired token) still ends the claim: the
     * session fails rather than sitting in 'processing' forever.
     */
    public function test_a_refused_authorisation_releases_the_claim_by_failing_the_session(): void
    {
        $order = $this->walletOrder();
        $session = $order->paymentAttempts->first()->paymentSession;

        $this->pay($session, 'not-a-real-token')->assertOk();

        $this->assertSame('failed', $session->fresh()->status);
        $this->assertEquals(0.0, (float) $this->wallet->fresh()->locked_balance);
    }

    /** The same session cannot be claimed twice. */
    public function test_a_session_in_processing_is_not_claimable_again(): void
    {
        $order = $this->walletOrder();
        $session = $order->paymentAttempts->first()->paymentSession;
        $session->update(['status' => 'processing']);

        $this->pay($session, $this->pinToken())->assertStatus(409);
        $this->pay($session, $this->pinToken())->assertStatus(409);

        $this->assertSame('processing', $session->fresh()->status);
    }
}
