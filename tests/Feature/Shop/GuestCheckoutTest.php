<?php

namespace Tests\Feature\Shop;

use App\Domain\Notification\Providers\MailProviderInterface;
use App\Domain\Order\Events\FulfillmentSucceeded;
use App\Mail\GuestAccountClaimMail;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PricingRule;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SiteSetting;
use App\Models\Subcategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Guest checkout (features.guest_checkout_enabled, default ON). Guests order
 * under a shadow account keyed by their delivery email; after fulfillment
 * they receive a claim link to set a password + transaction PIN, and every
 * order they placed as a guest is already on the account. Wallet/Rcoin stay
 * signed-in-only, and guest attempts are rate limited per IP and per email.
 */
class GuestCheckoutTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.flutterwave.secret_key' => 'FLW_SECRET_KEY_MOCK',
            'services.nowpayments.api_key' => 'NOWPAYMENTS_KEY_MOCK',
        ]);

        // The notification pipeline must never hit the real mail provider.
        $this->app->instance(MailProviderInterface::class, new class implements MailProviderInterface
        {
            public function send(string $to, string $subject, string $htmlBody, array $headers = [], array $attachments = []): array
            {
                return ['id' => 'spy', 'status' => 'success'];
            }
        });
    }

    /** Build an active cart with one gift-card item under a guest token. */
    private function seedGuestCart(string $guestToken): Cart
    {
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
            'sku' => 'STEAM10-GUEST',
            'currency' => 'USD',
            'face_value' => 10.00,
            'cost_price' => 9.50,
            'retail_price' => 10.50,
            'is_available' => true,
        ]);
        PricingRule::firstOrCreate(['markup_type' => 'fixed', 'markup_value' => 1.00, 'is_active' => true]);

        $cart = Cart::create([
            'guest_token' => $guestToken,
            'status' => 'active',
            'last_activity_at' => now(),
        ]);

        CartItem::create([
            'cart_id' => $cart->id,
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

        return $cart;
    }

    // ── Feature flag + guards ───────────────────────────────────

    public function test_guest_checkout_is_blocked_when_the_flag_is_off(): void
    {
        SiteSetting::put('features.guest_checkout_enabled', 'off', 'features');

        $this->post(route('checkout.process'), [
            'delivery_email' => 'guest@example.com',
            'payment_method' => 'card',
            'currency' => 'USD',
        ])->assertForbidden();
    }

    public function test_guests_cannot_pay_with_wallet_or_rcoin(): void
    {
        $response = $this->post(route('checkout.process'), [
            'delivery_email' => 'guest@example.com',
            'payment_method' => 'wallet',
            'currency' => 'USD',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('checkout_status', 'Sign in to pay with your wallet or Rcoin.');
        $this->assertDatabaseMissing('users', ['email' => 'guest@example.com']);
    }

    public function test_guest_checkout_is_rate_limited_per_email(): void
    {
        // Per-email cap is 4 attempts per 10 minutes; the 5th gets a 429.
        for ($i = 0; $i < 4; $i++) {
            $this->post(route('checkout.process'), [
                'delivery_email' => 'hammer@example.com',
                'payment_method' => 'card',
                'currency' => 'USD',
            ])->assertRedirect(); // empty cart bounce, but the attempt counts
        }

        $this->post(route('checkout.process'), [
            'delivery_email' => 'hammer@example.com',
            'payment_method' => 'card',
            'currency' => 'USD',
        ])->assertStatus(429);
    }

    // ── Placing a guest order ───────────────────────────────────

    public function test_guest_order_is_placed_under_a_shadow_account(): void
    {
        Http::fake(['*' => Http::response(['status' => 'success', 'data' => ['link' => 'https://checkout.test/pay', 'id' => 1]], 200)]);

        $token = Str::uuid()->toString();
        $this->seedGuestCart($token);

        $response = $this->withHeader('X-Guest-Token', $token)->post(route('checkout.process'), [
            'delivery_email' => 'guest@example.com',
            'payment_method' => 'card',
            'currency' => 'USD',
        ]);

        $response->assertSessionMissing('checkout_status');

        $guest = User::where('email', 'guest@example.com')->first();
        $this->assertNotNull($guest);
        $this->assertTrue($guest->is_guest);

        $order = Order::where('user_id', $guest->id)->first();
        $this->assertNotNull($order);

        // Checkout granted this browser session access to its own order...
        $response->assertSessionHas('guest_order_numbers', [$order->order_number]);

        // ...which the confirmation page honours (the array session driver in
        // tests does not persist between requests, so the grant is re-seeded)...
        $this->withoutVite()
            ->withSession(['guest_order_numbers' => [$order->order_number]])
            ->get(route('shop.order', $order->order_number))
            ->assertOk();

        // ...while a fresh session (another visitor) cannot enumerate it.
        $this->flushSession();
        $this->get(route('shop.order', $order->order_number))->assertNotFound();
    }

    public function test_guest_order_with_a_registered_email_attaches_to_that_account(): void
    {
        Http::fake(['*' => Http::response(['status' => 'success', 'data' => ['link' => 'https://checkout.test/pay', 'id' => 1]], 200)]);

        $existing = User::factory()->create(['email' => 'customer@example.com']);
        $token = Str::uuid()->toString();
        $this->seedGuestCart($token);

        $this->withHeader('X-Guest-Token', $token)->post(route('checkout.process'), [
            'delivery_email' => 'customer@example.com',
            'payment_method' => 'card',
            'currency' => 'USD',
        ])->assertSessionMissing('checkout_status');

        $this->assertSame(1, User::where('email', 'customer@example.com')->count());
        $this->assertFalse($existing->refresh()->is_guest);
        $this->assertSame(1, Order::where('user_id', $existing->id)->count());
    }

    // ── Claim email on fulfillment ──────────────────────────────

    public function test_fulfillment_sends_the_claim_email_to_guests_once(): void
    {
        Mail::fake();

        $guest = User::factory()->create(['email' => 'guest@example.com', 'is_guest' => true]);
        $item = $this->makeFulfilledItem($guest);

        event(new FulfillmentSucceeded($item));
        event(new FulfillmentSucceeded($item)); // second item of the same order

        Mail::assertQueued(GuestAccountClaimMail::class, 1);
        Mail::assertQueued(GuestAccountClaimMail::class, fn (GuestAccountClaimMail $mail) => $mail->user->is($guest));
        $this->assertNotNull($guest->refresh()->guest_claim_last_sent_at);
    }

    public function test_fulfillment_sends_no_claim_email_to_registered_users(): void
    {
        Mail::fake();

        $user = User::factory()->create();
        $item = $this->makeFulfilledItem($user);

        event(new FulfillmentSucceeded($item));

        Mail::assertNotQueued(GuestAccountClaimMail::class);
    }

    // ── Claiming the account ────────────────────────────────────

    public function test_guest_can_claim_their_account_with_password_and_pin(): void
    {
        $guest = User::factory()->create(['email' => 'guest@example.com', 'is_guest' => true]);

        $showUrl = URL::temporarySignedRoute('account.claim', now()->addDays(7), ['user' => $guest->id]);
        $this->withoutVite()->get($showUrl)->assertOk()->assertSee('Claim your account');

        $storeUrl = URL::temporarySignedRoute('account.claim.store', now()->addHour(), ['user' => $guest->id]);
        $response = $this->post($storeUrl, [
            'password' => 'SuperSecret123!',
            'password_confirmation' => 'SuperSecret123!',
            'pin' => '8461',
            'pin_confirmation' => '8461',
        ]);

        $response->assertRedirect(route('dashboard.orders'));
        $this->assertAuthenticatedAs($guest->fresh());

        $guest->refresh();
        $this->assertFalse($guest->is_guest);
        $this->assertNotNull($guest->email_verified_at);
        $this->assertTrue(Hash::check('SuperSecret123!', $guest->password));
        $this->assertTrue($guest->hasTransactionPin());
    }

    public function test_claim_link_requires_a_valid_signature(): void
    {
        $guest = User::factory()->create(['is_guest' => true]);

        $this->get(route('account.claim', ['user' => $guest->id]))->assertForbidden();
    }

    public function test_already_claimed_accounts_are_bounced_to_sign_in(): void
    {
        $user = User::factory()->create(['is_guest' => false]);

        $showUrl = URL::temporarySignedRoute('account.claim', now()->addDays(7), ['user' => $user->id]);
        $this->get($showUrl)->assertRedirect(route('home'));
    }

    /** Minimal fulfilled order item for firing FulfillmentSucceeded. */
    private function makeFulfilledItem(User $buyer): OrderItem
    {
        $order = Order::create([
            'user_id' => $buyer->id,
            'order_number' => 'RSR-'.uniqid(),
            'settlement_currency' => 'USD',
            'display_currency' => 'USD',
            'subtotal_amount' => 10.00,
            'markup_amount' => 2.00,
            'total_amount' => 12.00,
            'payment_method' => 'card',
            'payment_status' => 'paid',
            'fulfillment_status' => 'fulfilled',
            'order_status' => 'completed',
            'placed_at' => now(),
            'metadata' => ['exchange_rate' => 1.0, 'delivery_email' => $buyer->email],
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
            'product_snapshot' => ['name' => 'Test gift card'],
            'fulfillment_status' => 'fulfilled',
        ]);
    }
}
