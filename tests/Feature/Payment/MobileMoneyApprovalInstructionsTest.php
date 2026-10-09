<?php

namespace Tests\Feature\Payment;

use App\Domain\Order\Services\CheckoutService;
use App\Domain\Payment\Support\MobileMoneyApproval;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Category;
use App\Models\CurrencyRate;
use App\Models\Order;
use App\Models\PricingRule;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SiteSetting;
use App\Models\Subcategory;
use App\Models\User;
use Database\Seeders\MobileMoneyApprovalSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * A customer paying over a rail that prompts the handset is told what to do,
 * and given the operator's own dial code when no prompt arrives.
 *
 * The Francophone rail answers a charge with "Transaction in progress" in
 * processor_response and, under meta.authorization, the merchant's own
 * return URL spelled `redirect_url` (the gateway's reference shows exactly
 * that). The modal used to show that status phrase as the whole
 * instruction, because it passed a length check, and looked for the
 * redirect under the other spelling. A customer waiting for an MTN prompt
 * saw "Transaction in progress" and no code to dial.
 */
class MobileMoneyApprovalInstructionsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Cart $cart;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.zendit.api_key' => 'ZENDIT_API_KEY_MOCK',
            'services.flutterwave.secret_key' => 'FLWSECK_TEST-realish',
            'services.flutterwave.public_key' => 'FLWPUBK_TEST-realish',
            'pricing.safety_markup_percent' => 10.0,
            'pricing.min_margin_percent' => 1.0,
        ]);

        SiteSetting::put('features.gateway_flutterwave_enabled', 'on', 'features');
        $this->seed(MobileMoneyApprovalSeeder::class);

        // A call this test did not fake must fail loudly, never reach the
        // gateway.
        Http::preventStrayRequests();

        $this->user = User::factory()->create();

        foreach (['GHS' => 15.5, 'XAF' => 600.0, 'XOF' => 600.0] as $code => $rate) {
            CurrencyRate::create(['code' => $code, 'name' => $code, 'type' => 'fiat', 'rate_per_usd' => $rate, 'is_active' => true]);
        }

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

        Cache::flush();
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
        $this->cart->load('items.product', 'items.variant');
    }

    private function checkout(string $currency): Order
    {
        return app(CheckoutService::class)->placeOrder(
            user: $this->user,
            cart: $this->cart->fresh()->load('items.product', 'items.variant'),
            paymentMethod: 'flutterwave',
            displayCurrency: $currency,
        );
    }

    /**
     * @param  array<string, mixed>  $details
     */
    private function pay(Order $order, array $details): TestResponse
    {
        $session = $order->paymentAttempts->first()->paymentSession;

        return $this->actingAs($this->user)->postJson("/api/payment-sessions/{$session->id}/pay", [
            'method' => 'mobile_money',
            'details' => $details,
        ]);
    }

    /**
     * The Francophone rail's answer, as the sandbox and the reference give
     * it: a pending charge, a status phrase, and a redirect_url.
     *
     * @param  array<string, mixed>  $authorization
     */
    private function fakeFrancophoneRail(string $processorResponse = 'Transaction in progress', array $authorization = ['mode' => 'redirect', 'redirect_url' => 'https://rshoprefill.test/checkout/return/abc']): void
    {
        Http::fake([
            'api.flutterwave.com/v3/charges*' => Http::response([
                'status' => 'success',
                'message' => 'Charge initiated',
                'data' => ['id' => 1191267, 'tx_ref' => 'x', 'status' => 'pending', 'processor_response' => $processorResponse],
                'meta' => ['authorization' => $authorization],
            ]),
        ]);
    }

    public function test_a_cameroon_mtn_customer_is_told_to_approve_the_prompt_and_given_the_dial_code(): void
    {
        $this->fakeFrancophoneRail();

        $response = $this->pay($this->checkout('XAF'), ['phone_number' => '237670000000', 'network' => 'MTN']);

        $response->assertOk();
        $this->assertSame('awaiting_confirmation', $response->json('data.status'));

        $message = (string) $response->json('data.payment_payload.message');
        $this->assertStringNotContainsString('Transaction in progress', $message, 'a status phrase is not an instruction');
        $this->assertStringStartsWith('We sent a payment request of ', $message);
        $this->assertStringContainsString('XAF to 237670000000', $message);

        $this->assertSame('MTN', $response->json('data.payment_payload.approval.network'));
        $this->assertSame('*126#', $response->json('data.payment_payload.approval.code'));
        $this->assertCount(3, $response->json('data.payment_payload.approval.steps'));
        $this->assertSame('CM', $response->json('data.payment_payload.mobile_money.country'));
        $this->assertSame('237670000000', $response->json('data.payment_payload.mobile_money.phone_number'));
    }

    public function test_an_instruction_from_the_gateway_is_shown_word_for_word(): void
    {
        $this->fakeFrancophoneRail('Dial *126# and approve the payment of 6,600 XAF');

        $response = $this->pay($this->checkout('XAF'), ['phone_number' => '237670000000', 'network' => 'MTN']);

        $this->assertSame('Dial *126# and approve the payment of 6,600 XAF', $response->json('data.payment_payload.message'));
    }

    public function test_orange_gets_its_own_code_and_a_market_without_one_gets_no_hint(): void
    {
        $this->fakeFrancophoneRail();

        $orange = $this->pay($this->checkout('XAF'), ['phone_number' => '237690000000', 'network' => 'orange']);
        $this->assertSame('#150*50#', $orange->json('data.payment_payload.approval.code'));
        $this->assertSame('Orange', $orange->json('data.payment_payload.approval.network'));

        // A West African number on the same rail: no code on file for CI.
        $this->cart = Cart::create(['user_id' => $this->user->id, 'status' => 'active', 'last_activity_at' => now()]);
        CartItem::create([
            'cart_id' => $this->cart->id,
            'product_id' => Product::first()->id,
            'product_variant_id' => ProductVariant::first()->id,
            'quantity' => 1,
            'display_currency' => 'USD',
            'display_amount' => 11.00,
            'provider_cost_usd' => 9.50,
            'markup_amount' => 1.00,
            'unit_price_snapshot' => 11.00,
            'subtotal_snapshot' => 11.00,
        ]);

        $ivoryCoast = $this->pay($this->checkout('XOF'), ['phone_number' => '0700000000', 'network' => 'MTN']);
        $this->assertSame('awaiting_confirmation', $ivoryCoast->json('data.status'));
        $this->assertNull($ivoryCoast->json('data.payment_payload.approval'));
        $this->assertSame('CI', $ivoryCoast->json('data.payment_payload.mobile_money.country'));
    }

    public function test_the_francophone_rail_stays_in_the_modal_whatever_url_it_echoes(): void
    {
        $this->fakeFrancophoneRail('Transaction in progress', [
            'mode' => 'redirecturl',
            'redirect_url' => 'https://checkout.flutterwave.com/v3/hosted/pay/some-page',
        ]);

        $response = $this->pay($this->checkout('XAF'), ['phone_number' => '237670000000', 'network' => 'MTN']);

        $this->assertSame('awaiting_confirmation', $response->json('data.status'));
        $this->assertNull($response->json('data.payment_payload.redirect_url'));
    }

    /**
     * A rail that would hand the customer an operator page is never charged by
     * API from here; it opens in the popup on our page, so there is no
     * redirect under either spelling of the authorisation mode.
     */
    public function test_a_rail_that_hands_over_a_page_opens_in_the_popup_instead(): void
    {
        Http::fake();

        $response = $this->pay($this->checkout('GHS'), ['phone_number' => '0244000000', 'network' => 'MTN']);

        $this->assertSame('awaiting_payment', $response->json('data.status'));
        $this->assertSame('mobilemoneyghana', $response->json('data.payment_payload.inline.payment_options'));
        $this->assertNull($response->json('data.payment_payload.redirect_url'));
        Http::assertNothingSent();
    }

    public function test_the_checkout_and_wallet_pages_carry_the_no_prompt_panel(): void
    {
        $this->withoutVite();
        // The pages themselves may read rates or coin lists; none of that is
        // under test here.
        Http::fake();

        $checkout = $this->actingAs($this->user)->get('/checkout')->assertOk()->getContent();

        $this->assertStringContainsString('data-momo-approval', $checkout);
        $this->assertStringContainsString(__('checkout.momo_no_prompt_title'), $checkout);
        $this->assertStringContainsString('x-text="momoApproval?.code"', $checkout);
        $this->assertStringContainsString('this.momoApproval = sessionData.payment_payload?.approval || null;', $checkout);
        $this->assertStringContainsString(__('checkout.momo_keep_open'), $checkout);

        // The wallet top-up sheet is the same wizard on the dashboard.
        $this->actingAs($this->user);
        Livewire::test('dashboard.fund-wallet', ['currency' => 'USD'])
            ->assertSeeHtml('data-momo-approval')
            ->assertSee(__('checkout.momo_no_prompt_title'));
    }

    public function test_the_seeder_keeps_an_operators_correction(): void
    {
        $key = MobileMoneyApproval::key('CM', 'MTN').'.code';
        SiteSetting::put($key, '*126*9#', 'payments');

        $this->seed(MobileMoneyApprovalSeeder::class);

        $this->assertSame('*126*9#', SiteSetting::get($key));
        $this->assertSame('*126*9#', MobileMoneyApproval::for('CM', 'MTN')['code']);
        $this->assertNull(MobileMoneyApproval::for('CI', 'MTN'));
    }
}
