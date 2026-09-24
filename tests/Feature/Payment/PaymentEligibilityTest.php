<?php

namespace Tests\Feature\Payment;

use App\Domain\Order\Services\CheckoutService;
use App\Domain\Payment\Exceptions\PaymentCategoryIneligibleException;
use App\Domain\Payment\Services\PaymentEligibilityService;
use App\Domain\Shared\Enums\ComplianceCategory;
use App\Domain\Shared\Enums\Currency;
use App\Domain\Shared\Services\OperationalAlerter;
use App\Domain\Wallet\Services\WalletFundingService;
use App\Enums\AlertSeverity;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Category;
use App\Models\Order;
use App\Models\PaymentAttempt;
use App\Models\PaymentSession;
use App\Models\PricingRule;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SiteSetting;
use App\Models\Subcategory;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletFunding;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * The payment eligibility gate. The rule these tests pin: a product category
 * can only be charged through a gateway the matrix has approved for it, every
 * restricted category defaults to deny, and the whole control is inert until
 * the enforcement flag is switched on (shadow mode changes nothing customers
 * experience). The gate is enforced server-side at every point money can move,
 * so hiding a tile is never the control.
 */
class PaymentEligibilityTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.zendit.api_key' => 'ZENDIT_API_KEY_MOCK',
            'services.flutterwave.secret_key' => 'FLW_SECRET_KEY_MOCK',
            'pricing.safety_markup_percent' => 10.0,
            'pricing.min_margin_percent' => 1.0,
        ]);

        $this->user = User::factory()->create();

        Cache::flush();
        PricingRule::create(['markup_type' => 'fixed', 'markup_value' => 1.00, 'is_active' => true]);
    }

    // --------------------------------------------------------------- helpers

    private function enforce(bool $on): void
    {
        SiteSetting::put('features.payment_eligibility_enforce_enabled', $on ? 'on' : 'off', 'features');

        if ($on) {
            // Sentinel cell: enforcement now refuses to engage with ZERO
            // approved cells (the full-catalogue-outage interlock). Approve a
            // cell no scenario in this file routes through — airtime on the
            // wallet gateway — so enforcement is genuinely active while every
            // cell under test keeps its intended state.
            SiteSetting::put('features.eligibility_wallet_airtime_enabled', 'on', 'features');
        }

        Cache::flush();
    }

    private function approve(ComplianceCategory $category, array $gateways): void
    {
        foreach ($gateways as $gateway) {
            SiteSetting::put("features.eligibility_{$gateway}_{$category->value}_enabled", 'on', 'features');
        }
        Cache::flush();
    }

    private function enableBackups(): void
    {
        SiteSetting::put('features.gateway_paystack_enabled', 'on', 'features');
        SiteSetting::put('features.gateway_korapay_enabled', 'on', 'features');
        Cache::flush();
    }

    private function productInCategory(string $slug, string $name): Product
    {
        $category = Category::firstOrCreate(['slug' => $slug], ['name' => ucwords(str_replace('-', ' ', $slug)), 'type' => 'digital']);
        $subcategory = Subcategory::create(['category_id' => $category->id, 'name' => 'General', 'slug' => $slug.'-general-'.uniqid()]);

        $product = Product::create([
            'category_id' => $category->id,
            'subcategory_id' => $subcategory->id,
            'provider_name' => 'zendit',
            'country_code' => 'US',
            'currency_code' => 'USD',
            'name' => $name,
            'slug' => Str::slug($name).'-'.uniqid(),
        ]);

        ProductVariant::create([
            'product_id' => $product->id,
            'provider_offer_id' => 'offer-'.uniqid(),
            'sku' => 'SKU'.strtoupper(uniqid()),
            'currency' => 'USD',
            'face_value' => 10.00,
            'cost_price' => 9.50,
            'retail_price' => 10.50,
            'is_available' => true,
        ]);

        return $product->fresh();
    }

    private function cartFor(Product ...$products): Cart
    {
        $cart = Cart::create(['user_id' => $this->user->id, 'status' => 'active', 'last_activity_at' => now()]);

        foreach ($products as $product) {
            CartItem::create([
                'cart_id' => $cart->id,
                'product_id' => $product->id,
                'product_variant_id' => $product->variants->first()->id,
                'quantity' => 1,
                'display_currency' => 'USD',
                'display_amount' => 11.00,
                'provider_cost_usd' => 9.50,
                'markup_amount' => 1.00,
                'unit_price_snapshot' => 11.00,
                'subtotal_snapshot' => 11.00,
            ]);
        }

        return $cart->load('items.product.category', 'items.variant');
    }

    private function checkout(Cart $cart, string $paymentMethod = 'flutterwave'): Order
    {
        return app(CheckoutService::class)->placeOrder(
            user: $this->user,
            cart: $cart->fresh()->load('items.product.category', 'items.variant'),
            paymentMethod: $paymentMethod,
            displayCurrency: 'USD',
        );
    }

    // ---------------------------------------------------------- policy + data

    public function test_every_restricted_category_defaults_to_deny_on_every_gateway(): void
    {
        $service = app(PaymentEligibilityService::class);

        foreach (ComplianceCategory::cases() as $category) {
            foreach (['flutterwave', 'paystack', 'korapay', 'nowpayments', 'wallet'] as $gateway) {
                $allowed = $service->allows($category, $gateway);

                if ($category === ComplianceCategory::NormalDigitalGood) {
                    $this->assertTrue($allowed, "normal goods must stay payable on {$gateway}");
                } else {
                    $this->assertFalse($allowed, "{$category->value} must default to deny on {$gateway}");
                }
            }
        }
    }

    public function test_crypto_gateway_alias_is_normalised_to_nowpayments(): void
    {
        $service = app(PaymentEligibilityService::class);
        $this->approve(ComplianceCategory::GiftCard, ['nowpayments']);

        $this->assertTrue($service->allows(ComplianceCategory::GiftCard, 'crypto'));
        $this->assertTrue($service->allows(ComplianceCategory::GiftCard, 'nowpayments'));
    }

    public function test_storefront_slugs_map_to_the_right_compliance_category(): void
    {
        $this->assertSame(ComplianceCategory::GiftCard, ComplianceCategory::fromCategorySlug('gift-cards'));
        $this->assertSame(ComplianceCategory::Esim, ComplianceCategory::fromCategorySlug('esims'));
        $this->assertSame(ComplianceCategory::Airtime, ComplianceCategory::fromCategorySlug('mobile-airtime'));
        $this->assertSame(ComplianceCategory::NormalDigitalGood, ComplianceCategory::fromCategorySlug('bill-payments'));
        $this->assertSame(ComplianceCategory::NormalDigitalGood, ComplianceCategory::fromCategorySlug(null));
    }

    public function test_a_gift_card_product_is_classified_as_a_gift_card_even_when_created_after_migration(): void
    {
        // The column defaults to null; the accessor derives from the slug, so an
        // untagged row cannot be misread as a normal good.
        $giftCard = $this->productInCategory('gift-cards', 'Steam Card');

        $this->assertNull($giftCard->getRawOriginal('compliance_category'), 'the column stays null');
        $this->assertSame(ComplianceCategory::GiftCard, $giftCard->load('category')->complianceCategory());
    }

    // --------------------------------------------------------- shadow vs enforce

    public function test_shadow_mode_lets_a_restricted_cart_check_out_unchanged(): void
    {
        // Enforcement off (the default). A gift-card cart still checks out, so
        // deploying the gate changes nothing until it is switched on.
        $cart = $this->cartFor($this->productInCategory('gift-cards', 'Steam Card'));

        $order = $this->checkout($cart);

        $this->assertNotNull($order->id);
        $this->assertSame(ComplianceCategory::GiftCard, $order->items->first()->compliance_category);
    }

    public function test_enforcing_blocks_a_restricted_cart_before_any_write(): void
    {
        $this->enforce(true);
        $cart = $this->cartFor($this->productInCategory('gift-cards', 'Steam Card'));

        try {
            $this->checkout($cart);
            $this->fail('an ineligible gift-card checkout was allowed while enforcing');
        } catch (PaymentCategoryIneligibleException $e) {
            $this->assertStringContainsString('Gift cards', $e->customerMessage());
            $this->assertStringNotContainsString('flutterwave', strtolower($e->customerMessage()), 'provider names never reach customers');
        }

        $this->assertSame(0, Order::count(), 'a blocked checkout must cost zero rows');
        $this->assertSame(0, PaymentAttempt::count());
        $this->assertSame(0, PaymentSession::count());
    }

    public function test_enforcing_allows_a_category_once_its_gateway_is_approved(): void
    {
        $this->enforce(true);
        $this->approve(ComplianceCategory::GiftCard, ['flutterwave']);
        $cart = $this->cartFor($this->productInCategory('gift-cards', 'Steam Card'));

        $order = $this->checkout($cart);

        $this->assertNotNull($order->id, 'an approved gift-card checkout was blocked');
    }

    public function test_a_normal_digital_good_is_unaffected_by_enforcement(): void
    {
        $this->enforce(true);
        $cart = $this->cartFor($this->productInCategory('bill-payments', 'Electricity Bill'));

        $order = $this->checkout($cart);

        $this->assertNotNull($order->id, 'a normal digital good must stay payable');
    }

    // ------------------------------------------------------------- mixed carts

    public function test_a_mixed_cart_is_blocked_when_one_category_is_ineligible(): void
    {
        $this->enforce(true);
        // Normal goods are allowed, gift cards are not: one payment cannot span
        // gateways, so the whole cart is refused.
        $cart = $this->cartFor(
            $this->productInCategory('bill-payments', 'Electricity Bill'),
            $this->productInCategory('gift-cards', 'Steam Card'),
        );

        $this->expectException(PaymentCategoryIneligibleException::class);

        try {
            $this->checkout($cart);
        } finally {
            $this->assertSame(0, Order::count(), 'a blocked mixed cart must cost zero rows');
        }
    }

    // ------------------------------------------------ pay endpoint (authoritative)

    public function test_pay_endpoint_refuses_a_category_the_gateway_lost_approval_for(): void
    {
        // Create the order in shadow mode, then flip enforcement on: this is a
        // mid-session matrix change and a crafted direct call to the public
        // pay endpoint, exactly what the authoritative gate must catch.
        $cart = $this->cartFor($this->productInCategory('gift-cards', 'Steam Card'));
        $order = $this->checkout($cart);
        $session = $order->paymentAttempts->first()->paymentSession;

        $this->enforce(true);

        $response = $this->actingAs($this->user)->postJson("/api/payment-sessions/{$session->id}/pay", [
            'method' => 'card',
        ]);

        $response->assertStatus(422)->assertJson(['category_ineligible' => true]);
    }

    public function test_pay_endpoint_allows_an_approved_category(): void
    {
        $cart = $this->cartFor($this->productInCategory('gift-cards', 'Steam Card'));
        $order = $this->checkout($cart);
        $session = $order->paymentAttempts->first()->paymentSession;

        $this->enforce(true);
        $this->approve(ComplianceCategory::GiftCard, ['flutterwave']);

        $response = $this->actingAs($this->user)->postJson("/api/payment-sessions/{$session->id}/pay", [
            'method' => 'card',
        ]);

        // Approved: the gate lets it through to the gateway (inline init), so the
        // response is NOT the eligibility refusal.
        $this->assertNotSame(422, $response->getStatusCode());
        $response->assertJsonMissing(['category_ineligible' => true]);
    }

    // ---------------------------------------------------------- wallet funding

    public function test_wallet_funding_is_refused_while_enforcing(): void
    {
        $wallet = Wallet::create([
            'user_id' => $this->user->id,
            'currency' => Currency::USD,
            'balance' => 0.00,
            'locked_balance' => 0.00,
            'is_active' => true,
        ]);

        $this->enforce(true);

        $this->expectException(PaymentCategoryIneligibleException::class);

        try {
            app(WalletFundingService::class)->initializeFunding($this->user, $wallet, 50.0, Currency::USD);
        } finally {
            $this->assertSame(0, WalletFunding::count(), 'a refused funding must cost zero rows');
        }
    }

    public function test_wallet_funding_gate_passes_once_stored_value_is_approved(): void
    {
        $this->enforce(true);
        $this->approve(ComplianceCategory::Wallet, ['flutterwave']);

        $blockers = app(PaymentEligibilityService::class)
            ->blockers([ComplianceCategory::Wallet], 'flutterwave', 'USD');

        $this->assertSame([], $blockers, 'approved stored value must pass the funding gate');
    }

    // --------------------------------------------------------------- tiles

    public function test_ineligible_methods_map_is_empty_in_shadow_mode(): void
    {
        $service = app(PaymentEligibilityService::class);

        $map = $service->ineligibleMethodsByCurrency([ComplianceCategory::GiftCard]);

        $this->assertSame([], $map, 'shadow mode must not change any tile');
    }

    public function test_ineligible_methods_map_greys_every_method_for_an_unapproved_cart(): void
    {
        $this->enforce(true);
        $service = app(PaymentEligibilityService::class);

        $map = $service->ineligibleMethodsByCurrency([ComplianceCategory::GiftCard]);

        // No gateway approves gift cards, so every USD method is ineligible.
        $this->assertContains('card', $map['USD']);
        $this->assertContains('crypto', $map['USD']);
        $this->assertContains('wallet', $map['USD']);
    }

    public function test_ineligible_methods_map_keeps_approved_methods_payable(): void
    {
        $this->enforce(true);
        $this->approve(ComplianceCategory::GiftCard, ['flutterwave']);
        $service = app(PaymentEligibilityService::class);

        $map = $service->ineligibleMethodsByCurrency([ComplianceCategory::GiftCard]);

        // Flutterwave approves gift cards and serves card in USD, so card stays.
        $this->assertNotContains('card', $map['USD']);
        // Crypto (nowpayments) and wallet are still unapproved for gift cards.
        $this->assertContains('crypto', $map['USD']);
        $this->assertContains('wallet', $map['USD']);
    }

    // ---------------------------------------------------- category-aware routing

    public function test_routing_picks_an_approved_backup_over_an_unapproved_primary(): void
    {
        $this->enforce(true);
        $this->enableBackups();
        // Gift cards approved on Kora only, not the primary.
        $this->approve(ComplianceCategory::GiftCard, ['korapay']);

        $service = app(PaymentEligibilityService::class);

        // mobile_money in GHS is served by Flutterwave (first, unapproved) then
        // Kora (approved). The router must skip the primary and route to the
        // approved backup, so the sale is not lost.
        $this->assertSame('korapay', $service->routeGateway('mobile_money', 'GHS', [ComplianceCategory::GiftCard]));
    }

    public function test_default_gateway_selection_picks_the_approved_provider(): void
    {
        $this->enforce(true);
        $this->enableBackups();
        $this->approve(ComplianceCategory::GiftCard, ['paystack']);

        $service = app(PaymentEligibilityService::class);

        // Flutterwave is first but unapproved; Paystack is the approved provider.
        $this->assertSame('paystack', $service->defaultGatewayFor([ComplianceCategory::GiftCard]));
    }

    public function test_routing_returns_null_when_no_candidate_is_approved(): void
    {
        $this->enforce(true);
        $this->enableBackups();
        // No gift-card approval anywhere.

        $service = app(PaymentEligibilityService::class);

        $this->assertNull($service->routeGateway('mobile_money', 'GHS', [ComplianceCategory::GiftCard]));
    }

    public function test_routing_ignores_eligibility_in_shadow_mode(): void
    {
        // Enforcement off: even with no approval, routing picks the first capable
        // gateway, so behaviour is unchanged until enforcement is switched on.
        $this->enableBackups();

        $service = app(PaymentEligibilityService::class);

        $this->assertSame('flutterwave', $service->routeGateway('mobile_money', 'GHS', [ComplianceCategory::GiftCard]));
    }

    // --------------------------------------------- the zero-cell interlock

    /**
     * A lockdown stays a lockdown. With the enforce flag ON and ZERO approved
     * cells (only reachable by bypassing the admin UI), enforcement used to
     * fall back to shadow, which turned "nothing approved" into "everything
     * allowed" the moment the last cell went off. Now it keeps refusing
     * restricted carts and pages someone to fix the matrix.
     */
    public function test_enforcement_holds_with_zero_approved_cells_and_pages_someone(): void
    {
        $alerts = [];
        $this->app->instance(OperationalAlerter::class, new class($alerts) extends OperationalAlerter
        {
            public function __construct(private array &$sent) {}

            public function send(AlertSeverity $severity, string $key, string $title, string $message, array $context = [], ?string $runbook = null): bool
            {
                $this->sent[] = compact('severity', 'key');

                return true;
            }
        });

        SiteSetting::put('features.payment_eligibility_enforce_enabled', 'on', 'features');
        Cache::flush();

        $service = app(PaymentEligibilityService::class);

        $this->assertSame(0, $service->approvedCellCount());
        $this->assertTrue($service->enforcementEnabled(), 'zero approved cells must not switch enforcement off');
        $this->assertNotEmpty($service->blockers([ComplianceCategory::GiftCard], 'flutterwave', 'USD'), 'a restricted cart is refused, not waved through');
        $this->assertTrue(collect($alerts)->pluck('key')->contains('payment_eligibility:enforcing-empty-matrix'));

        // One approved cell and the same flag is enforcing as intended.
        $this->approve(ComplianceCategory::Airtime, ['korapay']);

        $this->assertSame(1, $service->approvedCellCount());
        $this->assertTrue($service->enforcementEnabled());
    }

    public function test_the_admin_ui_refuses_to_switch_off_the_last_approved_cell_while_enforcing(): void
    {
        $this->approve(ComplianceCategory::Airtime, ['korapay']);
        SiteSetting::put('features.payment_eligibility_enforce_enabled', 'on', 'features');
        Cache::flush();

        Volt::test('admin.system-settings')
            ->call('updateSetting', 'features.eligibility_korapay_airtime_enabled', 'off')
            ->assertHasErrors('settings.features.eligibility_korapay_airtime_enabled');

        $this->assertSame(1, app(PaymentEligibilityService::class)->approvedCellCount(), 'the last cell is still approved');

        // With a second cell approved, the same cell can be switched off.
        $this->approve(ComplianceCategory::Airtime, ['paystack']);

        Volt::test('admin.system-settings')
            ->call('updateSetting', 'features.eligibility_korapay_airtime_enabled', 'off')
            ->assertHasNoErrors();

        Cache::flush();
        $this->assertSame(1, app(PaymentEligibilityService::class)->approvedCellCount());

        // And with enforcement off, cells come and go freely.
        SiteSetting::put('features.payment_eligibility_enforce_enabled', 'off', 'features');
        Cache::flush();

        Volt::test('admin.system-settings')
            ->call('updateSetting', 'features.eligibility_paystack_airtime_enabled', 'off')
            ->assertHasNoErrors();
    }

    public function test_the_admin_ui_refuses_to_enforce_with_zero_approved_cells(): void
    {
        Volt::test('admin.system-settings')
            ->call('updateSetting', 'features.payment_eligibility_enforce_enabled', 'on')
            ->assertHasErrors('settings.features.payment_eligibility_enforce_enabled');

        // The flip must not have been persisted.
        $this->assertFalse(app(PaymentEligibilityService::class)->enforcementEnabled());
        $this->assertNotSame('on', SiteSetting::get('features.payment_eligibility_enforce_enabled'));

        // With one cell approved the same flip is accepted.
        $this->approve(ComplianceCategory::Airtime, ['korapay']);

        Volt::test('admin.system-settings')
            ->call('updateSetting', 'features.payment_eligibility_enforce_enabled', 'on')
            ->assertHasNoErrors();

        $this->assertTrue(app(PaymentEligibilityService::class)->enforcementEnabled());
    }
}
