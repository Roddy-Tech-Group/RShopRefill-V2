<?php

namespace Tests\Feature\Security;

use App\Domain\Admin\Enums\AdminRole;
use App\Domain\Order\Services\CheckoutService;
use App\Domain\Payment\Services\PaymentGatewayFactory;
use App\Domain\Security\Services\ApiTokenService;
use App\Models\Admin;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Category;
use App\Models\Order;
use App\Models\PricingRule;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Subcategory;
use App\Models\User;
use Database\Seeders\AdminSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * The website's older /api endpoints tell a customer only what is theirs.
 *
 * The mobile API was written without these leaks; the storefront's own JSON
 * endpoints, built earlier, still carried them: the SKU (Airalo's begin with the
 * supplier's name), the supplier's list price, the supplier's offer id on every
 * order line, and the raw text of any exception the pay step caught. Phone
 * verification also survived a change of number in the profile settings.
 */
class StorefrontApiLeaksTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.zendit.api_key' => 'ZENDIT_API_KEY_MOCK',
            'services.flutterwave.secret_key' => 'FLW_SECRET_KEY_MOCK',
            'pricing.safety_markup_percent' => 10.0,
            'pricing.min_margin_percent' => 1.0,
            'pricing.floor_mode' => 'legacy',
        ]);

        Cache::flush();

        $this->user = User::factory()->create(['email_verified_at' => now()]);

        $category = Category::create(['name' => 'eSIMs', 'slug' => 'esims', 'type' => 'digital']);
        $subcategory = Subcategory::create(['category_id' => $category->id, 'name' => 'Data eSIM', 'slug' => 'data-esim']);
        $this->product = Product::create([
            'category_id' => $category->id,
            'subcategory_id' => $subcategory->id,
            'provider_name' => 'airalo',
            'country_code' => 'US',
            'currency_code' => 'USD',
            'name' => 'United States eSIM',
            'slug' => 'united-states-esim',
            'is_active' => true,
        ]);
        ProductVariant::create([
            'product_id' => $this->product->id,
            'provider_offer_id' => 'airalo-us-1gb-7d',
            'sku' => 'AIRALO-US-1GB-7D',
            'currency' => 'USD',
            'face_value' => 10.00,
            'cost_price' => 9.50,
            'retail_price' => 10.50,
            'is_available' => true,
        ]);
        PricingRule::create(['markup_type' => 'fixed', 'markup_value' => 1.00, 'is_active' => true]);
    }

    public function test_the_storefront_catalogue_never_publishes_the_sku_or_the_suppliers_price(): void
    {
        $body = $this->getJson('/api/storefront/products/'.$this->product->slug)->assertOk()->getContent();

        $this->assertStringNotContainsString('AIRALO', strtoupper($body), 'the supplier is named in the response');
        $this->assertStringNotContainsString('"sku"', $body);
        $this->assertStringNotContainsString('"retail_price"', $body);
    }

    public function test_the_admin_catalogue_api_still_carries_both_for_staff(): void
    {
        $this->seed(AdminSeeder::class);
        $admin = Admin::firstOrCreate(
            ['email' => 'leaks-admin@example.test'],
            ['name' => 'Leaks Admin', 'password' => 'password', 'role' => AdminRole::SuperAdmin, 'is_active' => true],
        );

        $body = $this->actingAs($admin, 'admin')->getJson('/admin/api/catalog/products')->assertOk()->getContent();

        $this->assertStringContainsString('"sku":"AIRALO-US-1GB-7D"', $body);
        $this->assertStringContainsString('"retail_price"', $body);
    }

    public function test_the_storefront_order_api_never_publishes_the_suppliers_offer_id(): void
    {
        $this->placeOrder();

        $body = $this->actingAs($this->user)->getJson('/api/orders')->assertOk()->getContent();

        $this->assertStringContainsString('United States eSIM', $body, 'the order line is there');
        $this->assertStringNotContainsString('provider_offer_id', $body);
        $this->assertStringNotContainsString('airalo-us-1gb-7d', $body);
    }

    /**
     * THE one for the pay step. Whatever the exception says (a gateway's
     * words, SQL, a config key) it is logged, never shown.
     */
    public function test_the_pay_steps_catch_all_never_shows_the_exceptions_text(): void
    {
        $session = $this->placeOrder()->paymentAttempts()->first()->paymentSession;

        $this->mock(PaymentGatewayFactory::class)
            ->shouldReceive('getProvider')
            ->andThrow(new \RuntimeException('SQLSTATE[42S22]: Column not found: flw_secret_key'));

        $response = $this->actingAs($this->user)
            ->postJson("/api/payment-sessions/{$session->id}/pay", ['method' => 'card'])
            ->assertStatus(400)
            ->assertJsonPath('message', 'This payment could not be processed. Please try again or choose another payment method.')
            ->assertJsonPath('payment_failed', true);

        $this->assertStringNotContainsString('SQLSTATE', $response->getContent());
        $this->assertStringNotContainsString('flw_secret_key', $response->getContent());
    }

    /** The mobile API routes on the marker, not on the wording. */
    public function test_the_mobile_api_still_answers_the_catch_all_as_payment_failed(): void
    {
        $session = $this->placeOrder()->paymentAttempts()->first()->paymentSession;
        $token = app(ApiTokenService::class)
            ->issuePair($this->user, 'leaks-test-phone-1')->toArray()['access_token'];

        $this->mock(PaymentGatewayFactory::class)
            ->shouldReceive('getProvider')
            ->andThrow(new \RuntimeException('SQLSTATE[42S22]: Column not found: flw_secret_key'));

        $response = $this->postJson("/v2/payments/{$session->id}/pay", ['method' => 'card'], [
            'Authorization' => 'Bearer '.$token,
            'X-Installation-ID' => 'leaks-test-phone-1',
            'Idempotency-Key' => 'leaks-catch-all-1',
        ])
            ->assertStatus(400)
            ->assertJsonPath('error.type', 'payment_failed');

        $this->assertStringNotContainsString('SQLSTATE', $response->getContent());
    }

    public function test_changing_the_phone_number_clears_its_verification(): void
    {
        $this->user->forceFill([
            'phone' => '+2348031234567',
            'phone_verified_at' => now(),
            'country' => 'NG',
        ])->save();

        $this->actingAs($this->user);

        Volt::test('settings.profile')
            ->set('phone', '+2348039876543')
            ->call('updateProfileInformation')
            ->assertHasNoErrors();

        $this->user->refresh();
        $this->assertSame('+2348039876543', $this->user->phone);
        $this->assertNull($this->user->phone_verified_at, 'a new number kept the old verification');
    }

    public function test_saving_the_profile_without_changing_the_phone_keeps_its_verification(): void
    {
        $this->user->forceFill([
            'phone' => '+2348031234567',
            'phone_verified_at' => now(),
            'country' => 'NG',
        ])->save();

        $this->actingAs($this->user);

        Volt::test('settings.profile')
            ->set('name', 'A New Name')
            ->call('updateProfileInformation')
            ->assertHasNoErrors();

        $this->assertNotNull($this->user->fresh()->phone_verified_at);
    }

    private function placeOrder(): Order
    {
        $cart = Cart::create(['user_id' => $this->user->id, 'status' => 'active', 'last_activity_at' => now()]);
        CartItem::create([
            'cart_id' => $cart->id,
            'product_id' => $this->product->id,
            'product_variant_id' => ProductVariant::sole()->id,
            'quantity' => 1,
            'display_currency' => 'USD',
            'display_amount' => 11.00,
            'provider_cost_usd' => 9.50,
            'markup_amount' => 1.00,
            'unit_price_snapshot' => 11.00,
            'subtotal_snapshot' => 11.00,
        ]);

        return app(CheckoutService::class)->placeOrder(
            user: $this->user,
            cart: $cart->load('items.product', 'items.variant'),
            paymentMethod: 'flutterwave',
            displayCurrency: 'USD',
        );
    }
}
