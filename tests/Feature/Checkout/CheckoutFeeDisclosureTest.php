<?php

namespace Tests\Feature\Checkout;

use App\Domain\Payment\Support\PaymentFeeDisclosure;
use App\Domain\Payment\Support\PaymentRouteTable;
use App\Models\SiteSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The processing fee the checkout discloses follows the gateway that will
 * take the method. The rates in config/payment_fees.php are the Flutterwave
 * account's, where the customer bears the fee; a method the switchboard
 * routes to Kora discloses Kora's customer fee, which is none, so the page
 * and Kora's payment window name the same figure. A customer was shown
 * XAF 3,411 and asked by Kora for XAF 3,280.
 */
class CheckoutFeeDisclosureTest extends TestCase
{
    use RefreshDatabase;

    private function source(): string
    {
        return file_get_contents(resource_path('views/shop/checkout.blade.php'));
    }

    private function koraTakesXafMobileMoney(): void
    {
        config(['services.korapay.secret_key' => 'sk_live_korapay_realish']);
        SiteSetting::put('features.gateway_korapay_enabled', 'on', 'features');
        DB::table('payment_routes')->where('method', 'mobile_money')->where('currency', 'XAF')
            ->update(['gateways' => json_encode(['korapay', 'flutterwave'])]);
        PaymentRouteTable::forget();
    }

    public function test_kora_has_no_customer_fee_in_the_table(): void
    {
        $kora = config('payment_fees.gateways.korapay');

        $this->assertSame([], $kora['methods']);
        $this->assertSame(0.0, (float) $kora['default']['transaction']);
        $this->assertSame(0.0, (float) $kora['default']['international']);
    }

    public function test_the_disclosure_names_the_gateway_that_takes_each_method_per_currency(): void
    {
        $this->koraTakesXafMobileMoney();

        $fees = PaymentFeeDisclosure::forCheckout();

        $this->assertSame('korapay', $fees['gateway_by_currency']['XAF']['mobile_money']);
        $this->assertSame('flutterwave', $fees['gateway_by_currency']['NGN']['bank_transfer']);
        $this->assertSame('flutterwave', $fees['gateway_by_currency']['NGN']['mobile_money'] ?? 'flutterwave', 'NGN has no mobile money on Kora');
        $this->assertSame([], $fees['gateways']['korapay']['methods']);
        $this->assertSame(2.0, (float) $fees['methods']['mobile_money']['transaction'], 'the account table is still there for the other gateway');
    }

    public function test_a_switched_off_gateway_is_not_named(): void
    {
        $this->koraTakesXafMobileMoney();
        SiteSetting::put('features.gateway_korapay_enabled', 'off', 'features');
        PaymentRouteTable::forget();

        $this->assertSame('flutterwave', PaymentFeeDisclosure::gatewayByCurrency()['XAF']['mobile_money']);
    }

    public function test_the_page_is_handed_the_disclosure_and_reads_the_routed_gateways_rates(): void
    {
        Http::fake();
        $this->koraTakesXafMobileMoney();

        $this->withoutVite()->actingAs(User::factory()->create())->get(route('shop.checkout'))
            ->assertOk()
            ->assertSee('gateway_by_currency', false);

        $source = $this->source();
        $this->assertStringContainsString('$paymentFees = PaymentFeeDisclosure::forCheckout();', $source);
        $this->assertStringContainsString('feeGateway() {', $source);
        $this->assertStringContainsString('return byMethod[this.method] || null;', $source);
        $this->assertStringContainsString('const own = gateway ? (this.paymentFees.gateways || {})[gateway] : null;', $source);
        // Both the amount and the percentage shown come from the same lookup.
        $this->assertSame(2, substr_count($source, 'const rates = this.feeRates();'));
        $this->assertStringNotContainsString('const rates = (this.paymentFees.methods || {})[method]', $source);
    }
}
