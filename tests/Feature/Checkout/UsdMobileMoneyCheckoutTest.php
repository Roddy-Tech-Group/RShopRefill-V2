<?php

namespace Tests\Feature\Checkout;

use App\Domain\Payment\Support\GatewayCapabilities;
use App\Domain\Payment\Support\MobileMoneyRail;
use App\Domain\Payment\Support\SupportedPaymentMethods;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * A dollar order can be paid over MTN or Orange. No rail takes dollars, so
 * the charge is taken in CFA francs (MobileMoneyRail); what the checkout page
 * has to do is offer the tile under USD and say, beside the phone number,
 * roughly what the account will be debited. The charge itself, and the
 * verification and refund that follow it, are pinned in MobileMoneyRailTest.
 */
class UsdMobileMoneyCheckoutTest extends TestCase
{
    private function checkout(): string
    {
        return File::get(resource_path('views/shop/checkout.blade.php'));
    }

    /**
     * The server offers mobile money under USD, and the page renders the
     * server's list rather than a copy of its own.
     */
    public function test_the_tile_is_offered_under_usd_on_both_sides(): void
    {
        $this->assertSame(['card', 'mobile_money', 'crypto', 'wallet'], SupportedPaymentMethods::forCurrency('USD'));
        $this->assertStringContainsString('SupportedPaymentMethods::byCurrency()', $this->checkout());
    }

    /** The networks under USD are the Francophone rail's, and the charge is in XAF. */
    public function test_usd_rides_the_francophone_rail(): void
    {
        // A dollar customer is charged in francs, so Flutterwave's rail offers
        // every network the franc countries have between them; the number's
        // country decides which are accepted at the pay step.
        $this->assertSame(
            ['CM' => ['MTN', 'Orange'], 'CI' => ['MTN', 'Orange', 'Moov', 'Wave'], 'SN' => ['Orange', 'Wave'], 'BF' => ['Mobicash']],
            GatewayCapabilities::mobileMoneyUnion(['flutterwave'], 'USD'),
        );
        $this->assertSame(['USD' => 'XAF'], MobileMoneyRail::chargeCurrencyByCurrency());
        $this->assertSame(0, MobileMoneyRail::precision('XAF'), 'francs have no minor unit');
        $this->assertSame(0, MobileMoneyRail::precision('XOF'));
        $this->assertSame(2, MobileMoneyRail::precision('GHS'));
    }

    /**
     * The panel tells the customer what their account will be debited. The
     * page's own total line says "You'll be charged in this currency", which
     * is not true of this one method, so the correction sits beside the
     * number that will be debited and follows the same figure the total does.
     */
    public function test_the_panel_says_what_the_account_will_be_debited(): void
    {
        $source = $this->checkout();

        $this->assertStringContainsString('chargeByCurrency: @js($momoChargeByCurrency)', $source);
        $this->assertStringContainsString('x-show="momoCharge"', $source);
        $this->assertStringContainsString("__('checkout.momo_charge_hint')", $source);
        $this->assertStringContainsString('Math.ceil(this.totalDue() * charge.rate * factor) / factor', $source);
        $this->assertStringContainsString('return this.$store.cart.pay(this.totalDue());', $source, 'the total line reads the same figure');
    }

    /** The hint is worded in every storefront language, with the figure slot in each. */
    public function test_every_locale_words_the_hint(): void
    {
        $checked = 0;

        foreach (File::directories(lang_path()) as $dir) {
            $file = $dir.'/checkout.php';

            if (! File::exists($file)) {
                continue;
            }

            $lines = require $file;

            $this->assertArrayHasKey('momo_charge_hint', $lines, basename($dir).' has no wording for the hint');
            $this->assertStringContainsString(':amount', $lines['momo_charge_hint'], basename($dir));
            $checked++;
        }

        $this->assertGreaterThanOrEqual(10, $checked);
    }
}
