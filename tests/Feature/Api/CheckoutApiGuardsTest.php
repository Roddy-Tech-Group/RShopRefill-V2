<?php

namespace Tests\Feature\Api;

use App\Domain\Kyc\Contracts\KycProvider;
use App\Domain\Kyc\DTOs\KycSession;
use App\Domain\Kyc\DTOs\KycVerdict;
use App\Domain\Kyc\Services\KycProviderFactory;
use App\Domain\Shared\Enums\FundingStatus;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Category;
use App\Models\Order;
use App\Models\PricingRule;
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
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The API checkout route reaches the same placeOrder as the web form, so it
 * must pass the same gates. It used to skip the admin checkout kill switch,
 * the email-verification requirement and the risk guard entirely: an admin
 * could "turn checkout off" and the API would keep selling.
 */
class CheckoutApiGuardsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.flutterwave.secret_key' => 'FLW_SECRET_KEY_MOCK',
            'pricing.safety_markup_percent' => 10.0,
            'pricing.min_margin_percent' => 1.0,
            'pricing.floor_mode' => 'legacy',
        ]);
    }

    private function postCheckout(User $user, array $payload = []): TestResponse
    {
        return $this->actingAs($user)->postJson('/api/checkout/place-order', $payload + [
            'cart_id' => '00000000-0000-0000-0000-000000000000',
            'payment_method' => 'flutterwave',
            'preferred_currency' => 'USD',
        ]);
    }

    /**
     * A valid cart with one purchasable item, so placeOrder reaches the risk gate
     * instead of failing cart validation first.
     */
    private function cartWithItem(User $user): Cart
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
            'sku' => 'STEAM10',
            'currency' => 'USD',
            'face_value' => 10.00,
            'cost_price' => 9.50,
            'retail_price' => 10.50,
            'is_available' => true,
        ]);
        PricingRule::create(['markup_type' => 'fixed', 'markup_value' => 1.00, 'is_active' => true]);

        $cart = Cart::create(['user_id' => $user->id, 'status' => 'active', 'last_activity_at' => now()]);
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

    public function test_checkout_kill_switch_refuses_the_api_route(): void
    {
        SiteSetting::put('features.checkout_enabled', 'off', 'features');
        Cache::flush();

        // 503 before validation: "checkout is off" must not depend on the
        // caller first supplying a valid cart.
        $this->postCheckout(User::factory()->create())->assertStatus(503);
    }

    public function test_a_customer_cannot_place_an_order_against_someone_elses_cart(): void
    {
        // The cart id used to be looked up with a bare findOrFail, so any
        // signed-in customer who had another customer's cart id could check
        // it out. It is now the caller's own active cart or nothing, and a
        // cart that exists but is not yours answers exactly like one that
        // does not exist.
        $owner = User::factory()->create();
        $cart = $this->cartWithItem($owner);

        $this->postCheckout(User::factory()->create(), ['cart_id' => $cart->id])->assertNotFound();
        $this->postCheckout($owner, ['cart_id' => '00000000-0000-0000-0000-000000000000'])->assertNotFound();

        $this->assertSame(0, Order::count());
    }

    public function test_a_paid_cart_cannot_be_checked_out_again(): void
    {
        // A cart is set to abandoned once its payment confirms. Placing a
        // second order against it would bill the customer twice for items
        // they already received.
        $user = User::factory()->create();
        $cart = $this->cartWithItem($user);
        $cart->update(['status' => 'abandoned']);

        $this->postCheckout($user, ['cart_id' => $cart->id])->assertNotFound();
    }

    public function test_unverified_email_is_always_refused_at_checkout(): void
    {
        // Email verification is mandatory: the EnsureEmailVerified middleware
        // refuses an unverified user before placeOrder runs, no setting needed.
        $unverified = User::factory()->create(['email_verified_at' => null]);

        $this->postCheckout($unverified)
            ->assertStatus(403)
            ->assertJsonFragment(['email_unverified' => true]);
    }

    public function test_a_risk_denied_checkout_is_refused_by_the_engine(): void
    {
        // Risk engine enforcing; a confirmed-fraud label forces a deny override.
        SiteSetting::put('features.risk_engine_enabled', 'on', 'features');
        SiteSetting::put('features.risk_engine_enforce_enabled', 'on', 'features');
        Cache::flush();

        $user = User::factory()->create();
        $cart = $this->cartWithItem($user);
        RiskLabel::create([
            'user_id' => $user->id,
            'label' => RiskLabel::LABEL_CONFIRMED_FRAUD,
            'source' => 'admin',
        ]);

        $this->postCheckout($user, ['cart_id' => $cart->id])
            ->assertStatus(403)
            ->assertJsonFragment(['risk_declined' => true]);
    }

    public function test_a_checkout_past_the_daily_spending_cap_is_refused(): void
    {
        SiteSetting::put('features.spending_limits_enforce_enabled', 'on', 'features');
        Cache::flush();

        // $495 came in by card today: the $11 card order makes $506 and crosses
        // the seeded $500 standard daily cap.
        $user = User::factory()->create();
        $cart = $this->cartWithItem($user); // 11.00 USD
        Order::factory()->create([
            'user_id' => $user->id,
            'payment_method' => 'flutterwave',
            'total_amount' => 495,
            'created_at' => now(),
        ]);

        $this->postCheckout($user, ['cart_id' => $cart->id])
            ->assertStatus(422)
            ->assertJsonFragment(['limit_exceeded' => true]);

        // The same $11 from wallet balance brings nothing new in, so the cap
        // does not apply. Whatever the wallet rail says next is not this
        // test's concern.
        $this->postCheckout($user, ['cart_id' => $cart->id, 'payment_method' => 'wallet'])
            ->assertJsonMissingPath('limit_exceeded');
    }

    /**
     * KYC's cumulative dial counts money in. A checkout paid by card is new
     * money and counts toward the line; the same checkout paid from wallet
     * balance spends money that was counted when it was funded, so it must
     * not be asked to verify. This pins the wiring from the payment method the
     * customer chose through to the tier engine.
     */
    public function test_a_wallet_paid_checkout_does_not_add_itself_to_the_kyc_money_in_count(): void
    {
        SiteSetting::put('features.kyc_enforce_enabled', 'on', 'features');
        SiteSetting::put('features.kyc_verification_enabled', 'on', 'features');
        Cache::flush();

        $fake = new class implements KycProvider
        {
            public function key(): string
            {
                return 'fake';
            }

            public function startVerification(User $user, ?string $issuingCountry = null): KycSession
            {
                return new KycSession('ref-1', token: 'tok-1', metadata: ['partner_id' => 'p']);
            }

            public function verdictFromWebhook(array $payload): ?KycVerdict
            {
                return null;
            }
        };
        $this->mock(KycProviderFactory::class, fn ($mock) => $mock->shouldReceive('forUser')->andReturn($fake));

        // $1,995 already in: an $11 card order makes $2,006 and crosses $2,000.
        $user = User::factory()->create();
        $wallet = Wallet::create(['user_id' => $user->id, 'currency' => 'USD', 'balance' => 1995, 'is_active' => true]);
        WalletFunding::create([
            'user_id' => $user->id,
            'wallet_id' => $wallet->id,
            'reference' => 'FUND-KYCWALLET',
            'currency' => 'USD',
            'amount' => 1995,
            'settled_amount_usd' => 1995,
            'gateway' => 'flutterwave',
            'status' => FundingStatus::Completed,
            'completed_at' => now(),
        ]);

        $this->postCheckout($user, ['cart_id' => $this->cartWithItem($user)->id, 'payment_method' => 'flutterwave'])
            ->assertJsonPath('kyc_required', true);

        // The same $11 from wallet balance brings nothing new in: no step-up.
        // Whatever the wallet rail says next is not this test's concern.
        $this->postCheckout($user, ['cart_id' => Cart::where('user_id', $user->id)->latest()->first()->id, 'payment_method' => 'wallet'])
            ->assertJsonMissingPath('kyc_required');
    }

    public function test_a_reviewed_checkout_is_held_with_a_neutral_message(): void
    {
        SiteSetting::put('features.risk_engine_enabled', 'on', 'features');
        SiteSetting::put('features.risk_engine_enforce_enabled', 'on', 'features');
        Cache::flush();

        // Map the critical band to review (not deny) and drop the override so the
        // confirmed-fraud signal scores into that band instead of forcing deny.
        config([
            'risk.overrides' => [],
            'risk.band_outcomes' => ['LOW' => 'allow', 'MEDIUM' => 'step_up', 'HIGH' => 'review', 'CRITICAL' => 'review'],
        ]);

        $user = User::factory()->create();
        $cart = $this->cartWithItem($user);
        RiskLabel::create([
            'user_id' => $user->id,
            'label' => RiskLabel::LABEL_CONFIRMED_FRAUD,
            'source' => 'admin',
        ]);

        $this->postCheckout($user, ['cart_id' => $cart->id])
            ->assertStatus(422)
            ->assertJsonFragment(['risk_review' => true]);

        $this->assertDatabaseHas('risk_reviews', ['user_id' => $user->id, 'status' => 'pending']);
    }
}
