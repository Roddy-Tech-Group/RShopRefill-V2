<?php

namespace Tests\Feature\Checkout;

use App\Domain\Payment\Services\PaymentSessionService;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PaymentAttempt;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Subcategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * A failed payment does not leave the customer with a red sentence and a
 * close button. Beneath the reason sit a "Having trouble?" list of things to
 * try, chosen by the failure's reason code and the method used, and a "Why
 * did this happen?" accordion that says who decided and that nothing was
 * charged. The same partial serves the checkout wizard, the wallet top-up
 * sheet and the order page's pay-again wizard.
 */
class PaymentFailureHelpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    private function checkout(?string $locale = null): string
    {
        $request = $this->actingAs(User::factory()->create());

        if ($locale !== null) {
            $request = $request->withSession(['locale' => $locale]);
        }

        return $request->get('/checkout')->assertOk()->getContent();
    }

    public function test_the_checkout_wizard_offers_help_under_a_failed_payment(): void
    {
        $html = $this->checkout();

        // Page-sized haystacks are asserted tersely.
        foreach ([
            'data-failure-help',
            __('checkout.trouble_title'),
            __('checkout.why_title'),
            __('checkout.trouble_card_1'),
            __('checkout.trouble_momo_2'),
            __('checkout.why_provider'),
            'href="'.route('shop.contact').'"',
            __('checkout.trouble_support_link'),
            ':aria-expanded="whyOpen.toString()"',
            'x-text="why()"',
        ] as $needle) {
            $this->assertTrue(str_contains($html, $needle), "the checkout failure help lacks: {$needle}");
        }

        // The help sits inside the failed state, after the reason line.
        $failedState = strpos($html, 'x-show="paymentState === \'error\'"');
        $help = strpos($html, 'data-failure-help');
        $closeButton = strpos($html, '@click="closeModal()" class="mt-6 rounded-full');
        $this->assertTrue($failedState !== false && $failedState < $help && $help < $closeButton, 'the help is not between the reason and the close button');
    }

    public function test_the_wizard_keeps_the_reason_code_for_the_help_and_clears_it_on_the_next_try(): void
    {
        $html = $this->checkout();

        $this->assertTrue(str_contains($html, 'this.failureCode = payload?.failure_code || null;'), 'the failure code is not kept');
        $this->assertTrue(str_contains($html, "this.errorMessage = '';\n                    this.failureCode = null;") || str_contains($html, "this.errorMessage = '';\r\n                    this.failureCode = null;"), 'a new payment does not clear the old code');
    }

    public function test_advice_follows_the_reason_code_and_the_method(): void
    {
        $partial = File::get(resource_path('views/shop/_payment-failure-help.blade.php'));

        // Timeouts and gateway errors are the payment service's; config and
        // system faults are ours; the phone prompt family is mobile money;
        // bank verdicts are the card's unless the money came over mobile money.
        $this->assertStringContainsString("['provider_timeout', 'provider_error'].includes(code)) return 'provider'", $partial);
        $this->assertStringContainsString("['gateway_config_error', 'system_error'].includes(code)) return 'platform'", $partial);
        $this->assertStringContainsString("'mobile_money_unknown'].includes(code)) return 'mobile_money'", $partial);
        $this->assertStringContainsString("'limit_exceeded'].includes(code)) return momo ? 'mobile_money' : 'card'", $partial);
    }

    public function test_the_wallet_sheet_and_the_order_page_carry_the_same_help(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        Livewire::test('dashboard.fund-wallet', ['currency' => 'USD'])
            ->assertSeeHtml('data-failure-help')
            ->assertSee(__('checkout.trouble_title'))
            ->assertSeeHtml('const chosen = selectedMethod;');

        $order = Order::create([
            'user_id' => $user->id,
            'order_number' => 'RSR-'.strtoupper(uniqid()),
            'settlement_currency' => 'USD',
            'display_currency' => 'USD',
            'subtotal_amount' => 25.00,
            'markup_amount' => 2.00,
            'total_amount' => 27.00,
            'payment_method' => 'flutterwave',
            'payment_status' => 'unpaid',
            'fulfillment_status' => 'not_started',
            'order_status' => 'pending',
            'placed_at' => now(),
            'metadata' => ['exchange_rate' => 1.0],
        ]);
        $category = Category::factory()->create();
        $subcategory = Subcategory::factory()->create(['category_id' => $category->id]);
        $product = Product::factory()->create(['category_id' => $category->id, 'brand_key' => 'Netflix']);
        $variant = ProductVariant::factory()->create(['product_id' => $product->id]);
        OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'category_id' => $category->id,
            'subcategory_id' => $subcategory->id,
            'provider_name' => 'test',
            'quantity' => 1,
            'display_currency' => 'USD',
            'display_amount' => 25.00,
            'provider_cost_usd' => 22.00,
            'markup_amount' => 2.00,
            'subtotal_amount' => 25.00,
            'product_snapshot' => ['brand_key' => 'Netflix', 'name' => 'Netflix Gift Card', 'country_code' => 'US'],
            'variant_snapshot' => ['face_value' => 25, 'currency' => 'USD'],
            'fulfillment_status' => 'not_started',
        ]);

        // The pay-again wizard renders only while a payment session is live.
        $attempt = PaymentAttempt::create([
            'order_id' => $order->id,
            'user_id' => $user->id,
            'gateway' => 'flutterwave',
            'idempotency_key' => 'help_'.Str::random(12),
            'currency' => 'USD',
            'amount' => 27.00,
            'payment_status' => 'pending',
        ]);
        app(PaymentSessionService::class)->createForOrder($order, $attempt, [])
            ->forceFill(['status' => 'awaiting_payment'])
            ->save();

        $html = $this->get(route('shop.order', $order->order_number))->assertOk()->getContent();

        $this->assertTrue(str_contains($html, 'data-failure-help'), 'the order page pay-again wizard lacks the help');
        $this->assertTrue(str_contains($html, 'const chosen = selectedMethod;'), 'the order page help does not read its own method variable');
    }

    public function test_the_help_reads_in_french(): void
    {
        $html = $this->checkout('fr');

        $this->assertTrue(str_contains($html, __('checkout.trouble_title', [], 'fr')), 'the French title is missing');
        $this->assertTrue(str_contains($html, __('checkout.why_title', [], 'fr')), 'the French accordion title is missing');
        $this->assertFalse(str_contains($html, 'Why did this happen?'), 'English reached the French page');

        foreach (File::glob(lang_path('*/checkout.php')) as $file) {
            $this->assertStringContainsString("'why_platform' =>", File::get($file), basename(dirname($file)).' lacks the failure help copy');
        }
    }
}
